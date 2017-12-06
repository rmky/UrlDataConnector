<?php
namespace exface\UrlDataConnector\ModelBuilders;

use exface\Core\Interfaces\DataSources\ModelBuilderInterface;
use exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder;
use exface\Core\Exceptions\NotImplementedError;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\UrlDataConnector\DataConnectors\ODataConnector;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Exceptions\ModelBuilders\ModelBuilderRuntimeError;
use exface\Core\DataTypes\StringDataType;

/**
 * 
 * @method ODataConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class ODataModelBuilder extends AbstractModelBuilder implements ModelBuilderInterface {
    
    private $metadata = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object)
    {
        $transaction = $meta_object->getWorkbench()->data()->startTransaction();
        
        $created_ds = $this->generateAttributes($meta_object, $transaction);
        
        $relationConstraints = $this->getMetadata()->filterXPath($this->getXPathToProperties($this->getEntityType($meta_object)))->siblings()->filterXPath('default:NavigationProperty/default:ReferentialConstraint');
        $this->generateRelations($meta_object->getApp(), $relationConstraints, $transaction);
        
        $transaction->commit();
        
        return $created_ds;
    }
    
    /**
     * Generates the attributes for a given meta object and saves them in the model.
     * 
     * @param MetaObjectInterface $meta_object
     * @param DataTransactionInterface $transaction
     * 
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function generateAttributes(MetaObjectInterface $meta_object, DataTransactionInterface $transaction = null)
    {
        $created_ds = DataSheetFactory::createFromObjectIdOrAlias($meta_object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        
        $entityName = $this->getEntityType($meta_object);
        $property_nodes = $this->getMetadata()->filterXPath($this->getXPathToProperties($entityName));
        $imported_rows = $this->getAttributeData($property_nodes, $meta_object)->getRows();
        foreach ($imported_rows as $row) {
            if (count($meta_object->findAttributesByDataAddress($row['DATA_ADDRESS'])) === 0) {
                $created_ds->addRow($row);
            }
        }
        $created_ds->setCounterRowsAll(count($imported_rows));
        
        if (! $created_ds->isEmpty()) {
            $created_ds->dataCreate(false, $transaction);
            // Reload object model and recreate the data sheet, so it is based on the refreshed object
            $refreshed_object = $meta_object->getWorkbench()->model()->reloadObject($meta_object);
            $uxon = $created_ds->exportUxonObject();
            $created_ds = DataSheetFactory::createFromObject($refreshed_object);
            $created_ds->importUxonObject($uxon);
        }
        
        return $created_ds;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\ModelBuilderInterface::generateObjectsForDataSource()
     */
    public function generateObjectsForDataSource(AppInterface $app, DataSourceInterface $source, $data_address_mask = null)
    {
        $existing_objects = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        $existing_objects->getColumns()->addFromExpression('DATA_ADDRESS');
        $existing_objects->addFilterFromString('APP', $app->getUid(), EXF_COMPARATOR_EQUALS);
        $existing_objects->dataRead();
        
        $new_objects = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        
        $transaction = $app->getWorkbench()->data()->startTransaction();
        
        if ($data_address_mask) {
            $filter = '[@Name="' . $data_address_mask . '"]';
        } else {
            $filter = '';
        }
        $entities = $this->getMetadata()->filterXPath($this->getXPathToEntityTypes() . $filter);
        $imported_rows = $this->getObjectData($entities, $app, $source)->getRows();
        foreach ($imported_rows as $row) {
            if ($existing_objects->getColumns()->getByExpression('DATA_ADDRESS')->findRowByValue($row['DATA_ADDRESS']) === false) {
                $new_objects->addRow($row);
            } 
        }
        $new_objects->setCounterRowsAll(count($imported_rows));
        
        if (! $new_objects->isEmpty()) {
            $new_objects->dataCreate(false, $transaction);
            // Generate attributes for each object
            foreach ($new_objects->getRows() as $row) {
                $object = $app->getWorkbench()->model()->getObjectByAlias($row['ALIAS'], $app->getAliasWithNamespace());
                $this->generateAttributes($object, $transaction);
            }
            // After all attributes are there, generate relations. It must be done after all new objects have
            // attributes as relations need attribute UIDs on both sides!
            $this->generateRelations($app, null, $transaction);
            
        }
        
        $transaction->commit();
        
        return $new_objects;
    }
    
    /**
     * 
     * @param AppInterface $app
     * @param Crawler $referentialConstraints
     * @param DataTransactionInterface $transaction
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function generateRelations(AppInterface $app, Crawler $referentialConstraints = null, DataTransactionInterface $transaction = null)
    {
        // If no nodes specified, get all constraint nodes from the metadata
        if (is_null($referentialConstraints)) {
            $referentialConstraints = $this->getMetadata()->filterXPath('//default:EntityType/default:NavigationProperty/default:ReferentialConstraint');
        }
        
        $new_relations = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.ATTRIBUTE');
        foreach ($new_relations->getMetaObject()->getAttributes()->getSystem() as $sys) {
            $new_relations->getColumns()->addFromAttribute($sys);
        }
        $skipped = 0;
        
        foreach ($referentialConstraints as $node) {
            // Find object on both ends of the relation. If not there, log an info and skip the relation
            try {
                $relationAlias = $node->parentNode->attributes['Name']->nodeValue;
                $relationAddress = $node->getAttribute('Property');
                $entityType = $this->stripNamespace($node->parentNode->parentNode->attributes['Name']->nodeValue);
                $object = $app->getWorkbench()->model()->getObjectByAlias($entityType, $app->getAliasWithNamespace());
                
                $relatedEntityType = $this->stripNamespace($node->parentNode->attributes['Type']->nodeValue);
                $relatedObject = $app->getWorkbench()->model()->getObjectByAlias($relatedEntityType, $app->getAliasWithNamespace());
                $relatedObjectKeyAlias = $node->getAttribute('ReferencedProperty');
            } catch (MetaObjectNotFoundError $e) {
                $app->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot find related object for NavigationProperty ' . $relationAlias . ': EntityType ' . $relatedEntityType . ' not imported yet?', null, $e), LoggerInterface::INFO);
                continue;
            }
            
            // If the object has no relation matching the alias or the relation is not 
            $relationAttribute = null;
            foreach ($object->findAttributesByDataAddress($relationAddress) as $attr) {
                if ($attr->isRelation() && $attr->getRelation()->getRelatedObject()->isExactly($relatedObject)) {
                    $skipped++;
                    continue;
                } elseif (! is_null($relationAttribute)) {
                    throw new ModelBuilderRuntimeError($this, 'Cannot create relation for object ' . $object->getAliasWithNamespace() . ' automatically: found multiple attributes (' . $attr->getAlias() . ', ' . $relationAttribute->getAlias() . ') matching the data address and not being a relation - cannot decide, which one to make a relation!.');
                } else {
                    $relationAttribute = $attr;
                }
            }
            
            if ($skipped === 0) {
                if (is_null($relationAttribute)) {
                    throw new ModelBuilderRuntimeError($this, 'Cannot create relation for object ' . $object->getAliasWithNamespace() . ' automatically: no attribute found with relation property "' . $relationAddress . '" as data address - please rebuild the model for object ' . $object->getAliasWithNamespace() . '!');
                }
                
                // Add relation data to the data sheet: just those fields, that will mark the attribute as a relation
                $new_relations->addRow([
                    $new_relations->getMetaObject()->getUidAttributeAlias() => $relationAttribute->getId(),
                    'LABEL' => $this->generateLabel($relationAlias),
                    'ALIAS' => $relationAlias,
                    'RELATED_OBJ_ATTR' => ($relatedObject->getUidAttributeAlias() !== $relatedObjectKeyAlias ? $relatedObjectKeyAlias : ''),
                    'RELATED_OBJ' => $relatedObject->getId()
                ]);
            }
        }
        
        $new_relations->setCounterRowsAll($new_relations->countRows() + $skipped);
        
        if (! $new_relations->isEmpty()) {
            $new_relations->dataUpdate(false, $transaction);
        }
        
        return $new_relations;
    }
    
    /**
     * Returns a crawlable instance containing the entire metadata XML.
     * 
     * @return Crawler
     */
    protected function getMetadata()
    {
        if (is_null($this->metadata)) {
            $query = new Psr7DataQuery(new Request('GET', $this->getDataConnection()->getMetadataUrl()));
            $query->setUriFixed(true);
            $query = $this->getDataConnection()->query($query);
            $this->metadata = new Crawler((string) $query->getResponse()->getBody());
        }
        return $this->metadata;
    }
    
    /**
     * Returns a data sheet of exface.Core.OBJECT created from the given EntityTypes.
     * 
     * @param Crawler $entity_nodes
     * @param AppInterface $app
     * @param DataSourceInterface $data_source
     * @return DataSheetInterface
     */
    protected function getObjectData(Crawler $entity_nodes, AppInterface $app, DataSourceInterface $data_source) 
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        $ds_uid = $data_source->getId();
        $app_uid = $app->getUid();
        foreach ($entity_nodes as $entity) {
            $namespace = $entity_nodes->parents()->first()->attr('Namespace');
            $entityName = $entity->getAttribute('Name');
            $address = $this->getEntitySetNode($entity)->attr('Name');
            $sheet->addRow([
                'LABEL' => $entityName,
                'ALIAS' => $entityName,
                'DATA_ADDRESS' => $address,
                'DATA_SOURCE' => $ds_uid,
                'APP' => $app_uid,
                'DATA_ADDRESS_PROPS' => json_encode([
                                            "EntityType" => $entityName,
                                            "Namespace" => $namespace
                                        ])
            ]);
        }
        return $sheet;
    }
    
    /**
     * Reads the metadata for Properties into a data sheet based on exface.Core.ATTRIBUTE.
     * 
     * @param Crawler $property_nodes
     * @param MetaObjectInterface $object
     * @return DataSheetInterface
     */
    protected function getAttributeData(Crawler $property_nodes, MetaObjectInterface $object)
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $object_uid = $object->getId();
        
        // Find the primary key
        $key_nodes = $property_nodes->siblings()->filterXPath('default:Key/default:PropertyRef');
        if ($key_nodes->count() === 1) {
            $primary_key = $key_nodes->first()->attr('Name');
        } else {
            $primary_key = false;
            $object->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot import compound primary key for ' . $object->getAliasWithNamespace() . ' - please specify a UID manually if needed!'));
        }
        
        foreach ($property_nodes as $node) {
            $name = $node->getAttribute('Name');
            $sheet->addRow([
                'LABEL' => $this->generateLabel($name),
                'ALIAS' => $name,
                'DATATYPE' => $this->getDataTypeId($this->guessDataType($object->getWorkbench(), $node)),
                'DATA_ADDRESS' => $name,
                'OBJECT' => $object_uid,
                'REQUIREDFLAG' => (strcasecmp($node->getAttribute('Nullable'), 'false') === 0 ? 1 : 0),
                'UIDFLAG' => ($name === $primary_key ? 1 : 0)
            ]);
        }
        return $sheet;
    }
    
    /**
     * Returns "MyEntityType" from "My.Model.EntityType"
     * 
     * @param string $nameWithNamespace
     * @return string
     */
    protected function stripNamespace($nameWithNamespace)
    {
        $dotPos = strrpos($nameWithNamespace, '.');
        if ($dotPos === false) {
            return $nameWithNamespace;
        }
        return substr($nameWithNamespace, ($dotPos+1));
    }
    
    /**
     * Attempts to make a given XML node name a bit mor human readable.
     * 
     * @param string $nodeName
     * @return string
     */
    protected function generateLabel($nodeName) {
        $string = StringDataType::convertCasePascalToUnderscore($nodeName);
        $string = str_replace('_', ' ', $string);
        $string = ucwords($string);
        return $string;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::guessDataType()
     */
    protected function guessDataType(Workbench $workbench, $node)
    {
        return DataTypeFactory::createFromAlias($workbench, 'exface.Core.String');
    }
    
    /**
     * Returns the XPath expression to filter EntityTypes
     * @return string
     */
    protected function getXPathToEntityTypes()
    {
        return '//default:EntityType';
    }
    
    /**
     * Returns the XPath expression to filter EntitySets
     * @return string
     */
    protected function getXPathToEntitySets()
    {
        return '//default:EntitySet';
    }
    
    /**
     * Returns the XPath expression to filter all EntityType Properties
     * @return string
     */
    protected function getXPathToProperties($entityName)
    {
        return $this->getXPathToEntityTypes() . '[@Name="' . $entityName . '"]/default:Property';
    }
    
    /**
     * Returns the EntityType holding the definition of the given object or NULL if the object does not match an EntityType.
     * 
     * Technically the data address of the object is the name of the EntitySet, so the result of this method is
     * the EntityType in the first EntitySet, where the name matches the data address of the given object.
     * 
     * @param MetaObjectInterface $object
     * @return string|null
     */
    protected function getEntityType(MetaObjectInterface $object)
    {
        return $this->stripNamespace($this->getMetadata()->filterXPath($this->getXPathToEntitySets() . '[@Name="' . $object->getDataAddress() . '"]')->attr('EntityType'));
    }
    
    /**
     * 
     * @param \DOMElement $entityTypeNode
     * @return \Symfony\Component\DomCrawler\Crawler
     */
    protected function getEntitySetNode(\DOMElement $entityTypeNode)
    {
        $namespace = (new Crawler($entityTypeNode))->parents()->first()->attr('Namespace');
        $entityName = $entityTypeNode->getAttribute('Name');
        return $this->getMetadata()->filterXPath($this->getXPathToEntitySets() . '[@EntityType="' . $namespace . '.' . $entityName . '"]');
    }
}