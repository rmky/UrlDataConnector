<?php
namespace exface\UrlDataConnector\ModelBuilders;

use exface\Core\Interfaces\DataSources\ModelBuilderInterface;
use exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Exceptions\ModelBuilders\ModelBuilderRuntimeError;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\DateDataType;
use exface\UrlDataConnector\DataConnectors\OData2Connector;
use exface\Core\Exceptions\Model\MetaAttributeNotFoundError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\UrlDataConnector\Actions\CallOData2Operation;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;

/**
 * 
 * @method OData2ConnectorConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class OData2ModelBuilder extends AbstractModelBuilder implements ModelBuilderInterface {
    
    private $metadata = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object) : DataSheetInterface
    {
        $transaction = $meta_object->getWorkbench()->data()->startTransaction();
        
        $created_ds = $this->generateAttributes($meta_object, $transaction);
        
        $entityType = $this->getEntityType($meta_object);
        
        $relationConstraints = $this->findRelationNodes($entityType);
        $this->generateRelations($meta_object->getApp(), $relationConstraints, $transaction);
        
        $functionImports = $this->findActionNodes($entityType);
        $this->generateActions($meta_object, $functionImports, $transaction);
        
        $transaction->commit();
        
        return $created_ds;
    }
    
    protected function findRelationNodes(string $entityType) : Crawler
    {
        return $this->getMetadata()->filterXPath($this->getXPathToProperties($entityType))->siblings()->filterXPath('default:NavigationProperty/default:ReferentialConstraint');
    }
    
    protected function findActionNodes(string $entityType) : Crawler
    {
        return $this->getMetadata()->filterXPath('default:FunctionImport[@ReturnType="' . $this->getNamespace($entityType) . '.' . $entityType . '"]');
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
        $created_ds->setAutoCount(false);
        
        $entityName = $this->getEntityType($meta_object);
        $property_nodes = $this->getMetadata()->filterXPath($this->getXPathToProperties($entityName));
        $imported_rows = $this->getAttributeData($property_nodes, $meta_object)->getRows();
        foreach ($imported_rows as $row) {
            if (count($meta_object->findAttributesByDataAddress($row['DATA_ADDRESS'])) === 0) {
                $created_ds->addRow($row);
            }
        }
        $created_ds->setCounterForRowsInDataSource(count($imported_rows));
        
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
    public function generateObjectsForDataSource(AppInterface $app, DataSourceInterface $source, string $data_address_mask = null) : DataSheetInterface
    {
        $existing_objects = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        $existing_objects->getColumns()->addMultiple(['DATA_ADDRESS', 'ALIAS']);
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
        $existingAddressCol = $existing_objects->getColumns()->getByExpression('DATA_ADDRESS');
        $existingAliasCol = $existing_objects->getColumns()->getByExpression('ALIAS');
        foreach ($imported_rows as $row) {
            if ($existingAddressCol->findRowByValue($row['DATA_ADDRESS']) === false) {
                if ($existingAliasCol->findRowByValue($row['ALIAS']) !== false) {
                    $row['ALIAS'] = $row['ALIAS'] . '2';
                }
                $new_objects->addRow($row);
            } 
        }
        $new_objects->setCounterForRowsInDataSource(count($imported_rows));
        
        if (! $new_objects->isEmpty()) {
            $new_objects->dataCreate(false, $transaction);
            // Generate attributes for each object
            foreach ($new_objects->getRows() as $row) {
                $object = $app->getWorkbench()->model()->getObjectByAlias($row['ALIAS'], $app->getAliasWithNamespace());
                $this->generateAttributes($object, $transaction);
                
                $functionImports = $this->findActionNodes($this->getEntityType($object));
                $this->generateActions($object, $functionImports, $transaction);
            }
            // After all attributes are there, generate relations. It must be done after all new objects have
            // attributes as relations need attribute UIDs on both sides!
            $this->generateRelations($app, null, $transaction);
            
        }
        
        $transaction->commit();
        
        return $new_objects;
    }

    /**
     * Create action models for function imports.
     * 
     * Example $metadata:
     * 
     *  <FunctionImport Name="UnlockTaskItem" ReturnType="MY.NAMESPACE.TaskItem" EntitySet="TaskItemSet" m:HttpMethod="GET">
            <Parameter Name="TaskId" Type="Edm.String" Mode="In" MaxLength="20"/>
            <Parameter Name="TaskItemId" Type="Edm.String" Mode="In" MaxLength="20"/>
            <Parameter Name="WarehouseNumber" Type="Edm.String" Mode="In" MaxLength="10"/>
        </FunctionImport>
     * 
     * @param MetaObjectInterface $object
     * @param Crawler $functionImports
     * @param DataTransactionInterface $transaction
     * @return DataSheetInterface
     */
    protected function generateActions(MetaObjectInterface $object, Crawler $functionImports, DataTransactionInterface $transaction) : DataSheetInterface
    {
        $newActions = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT_ACTION');
        $newActions->setAutoCount(false);
        $skipped = 0;
        
        foreach ($functionImports as $node) {
            
            // Read action parameters
            $parameters = [];
            $paramNodes = (new Crawler($node))->filterXpath('//default:Parameter');
            foreach ($paramNodes as $paramNode) {
                $pType = $this->guessDataType($object, $paramNode);
                $parameter = [
                    'name' => $paramNode->getAttribute('Name'),
                    'data_type' => [
                        'alias' => $pType->getAliasWithNamespace()
                    ]
                ];
                if (strcasecmp($node->getAttribute('Nullable'), 'true') !== 0) {
                    $parameter['required'] = true;
                }
                $pTypeOptions = $this->getDataTypeConfig($pType, $paramNode);
                if (! $pTypeOptions->isEmpty()) {
                    $parameter['data_type'] = array_merge($parameter['data_type'], $pTypeOptions->toArray());
                }
                $parameters[] = $parameter;
            }
            
            // See if action alread exists in the model
            $existingAction = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT_ACTION');
            $existingAction->addFilterFromString('APP', $object->getApp()->getUid(), EXF_COMPARATOR_EQUALS);
            $existingAction->addFilterFromString('ALIAS', $node->getAttribute('Name'), EXF_COMPARATOR_EQUALS);
            $existingAction->getColumns()->addFromSystemAttributes()->addFromExpression('CONFIG_UXON');
            $existingAction->dataRead();
            
            // If it does not exist, create it. Otherwise update the parameters only (because they really MUST match the metadata)
            if ($existingAction->isEmpty()) {
                $prototype = str_replace('\\', '/', CallOData2Operation::class) . '.php';
                
                $actionConfig = new UxonObject([
                    'function_import_name' => $node->getAttribute('Name'),
                    'parameters' => $parameters
                ]);
                
                $resultObjectAlias = $this->stripNamespace($node->getAttribute('ReturnType'));
                if ($object->getAlias() !== $resultObjectAlias) {
                    $actionConfig->setProperty('result_object_alias', $object->getNamespace() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER . $resultObjectAlias);
                }
                
                $actionData = [
                    'ACTION_PROTOTYPE' => $prototype,
                    'ALIAS' => $node->getAttribute('Name'),
                    'APP' => $object->getApp()->getUid(),
                    'NAME' => $this->getActionName($node->getAttribute('Name')),
                    'OBJECT' => $object->getId(),
                    'CONFIG_UXON' => $actionConfig->toJson()
                ]; 
                
                // Add relation data to the data sheet: just those fields, that will mark the attribute as a relation
                $newActions->addRow($actionData);
            } else {
                $existingConfig = UxonObject::fromJson($existingAction->getCellValue('CONFIG_UXON', 0));
                $existingAction->setCellValue('CONFIG_UXON', 0, $existingConfig->setProperty('parameters', $parameters)->toJson());
                $existingAction->dataUpdate(false, $transaction);
                $skipped++;
            }
        }
        
        // Create all new actions
        if (! $newActions->isEmpty()) {
            $newActions->dataCreate(true, $transaction);
        }
        
        $newActions->setCounterForRowsInDataSource($newActions->countRows() + $skipped);
        
        return $newActions;
    }
    
    protected function getActionName(string $alias) : string
    {
        return ucwords(str_replace('_', ' ', StringDataType::convertCasePascalToUnderscore($alias)));
    }
    
    /**
     * 
     * @param AppInterface $app
     * @param Crawler $associations
     * @param DataTransactionInterface $transaction
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function generateRelations(AppInterface $app, Crawler $associations = null, DataTransactionInterface $transaction = null)
    {
        // If no nodes specified, get all constraint nodes from the metadata
        if (is_null($associations)) {
            $associations = $this->getMetadata()->filterXPath('//default:Association');
        }
        
        $new_relations = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $skipped = 0;
        
        foreach ($associations as $node) {
            // This array needs to be filled
            $attributeData = [
                'UID' => null,
                'ALIAS' => null,
                'NAME' => null,
                'RELATED_OBJ' => null,
                'RELATED_OBJ_ATTR' => null,
                'DATA_ADDRESS_PROPS' => null,
                'COPY_WITH_RELATED_OBJECT' => 0, // oData services are expected to take care of correct copying themselves
                'DELETE_WITH_RELATED_OBJECT' => 0 // oData services are expected to take care of cascading deletes themselves
            ];
                
            try {
                
                $ends = [];
                foreach ($node->getElementsByTagName('End') as $endNode) {
                    $ends[$endNode->getAttribute('Role')] = $endNode;
                }
                
                $constraintNode = $node->getElementsByTagName('ReferentialConstraint')->item(0);
                $principalNode = $constraintNode->getElementsByTagName('Principal')->item(0);
                $dependentNode = $constraintNode->getElementsByTagName('Dependent')->item(0);
                
                $leftEndNode = $ends[$dependentNode->getAttribute('Role')];
                $leftEntityType = $this->stripNamespace($leftEndNode->getAttribute('Type'));
                $leftObject = $app->getWorkbench()->model()->getObjectByAlias($leftEntityType, $app->getAliasWithNamespace());
                $leftAttributeAlias = $dependentNode->getElementsByTagName('PropertyRef')->item(0)->getAttribute('Name');
                $leftAttribute = $leftObject->getAttribute($leftAttributeAlias);
                
                // Skip existing relations with the same alias
                if ($leftAttribute->isRelation() === true) {
                    $skipped++;
                    continue;
                }
                
                $rightEndNode = $ends[$principalNode->getAttribute('Role')];
                $rightEntityType = $this->stripNamespace($rightEndNode->getAttribute('Type'));
                $rightObject = $app->getWorkbench()->model()->getObjectByAlias($rightEntityType, $app->getAliasWithNamespace());
                $rightAttributeAlias = $principalNode->getElementsByTagName('PropertyRef')->item(0)->getAttribute('Name');
                $rightKeyAttribute = $rightObject->getAttribute($rightAttributeAlias);
                
                $attributeData['UID'] = $leftObject->getAttribute($leftAttributeAlias)->getId();
                $attributeData['ALIAS'] = $leftAttributeAlias;
                $attributeData['NAME'] = $rightObject->getName();
                $attributeData['RELATED_OBJ'] = $rightObject->getId();
                $attributeData['RELATED_OBJ_ATTR'] = $rightKeyAttribute->isUidForObject() === false ? $rightKeyAttribute->getId() : '';
                $attributeData['DATA_ADDRESS_PROPS'] = $leftAttribute->getDataAddressProperties()->extend(new UxonObject(['odata_association' => $node->getAttribute('Name')]))->toJson();
                
            } catch (MetaObjectNotFoundError $eo) {
                $app->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot find object for one of the ends of oData association ' . $node->getAttribute('Name') . ': Skipping association!', '73G87II', $eo), LoggerInterface::WARNING);
                continue;
            } catch (MetaAttributeNotFoundError $ea) {
                throw new ModelBuilderRuntimeError($this, 'Cannot convert oData association "' . $node->getAttribute('Name') . '" to relation for object ' . $leftObject->getAliasWithNamespace() . ' automatically: one of the key attributes was not found - see details below.', '73G87II', $ea);
            }
                
            // Add relation data to the data sheet: just those fields, that will mark the attribute as a relation
            $new_relations->addRow($attributeData);
        }
        
        $new_relations->setCounterForRowsInDataSource($new_relations->countRows() + $skipped);
        
        if (! $new_relations->isEmpty()) {
            // To update attributes with new relation data, we need to read the current system columns first
            // (e.g. to allow TimeStampingBehavior, etc.)
            $attributes = $new_relations->copy();
            $attributes->getColumns()->addFromSystemAttributes();
            $attributes->addFilterFromColumnValues($attributes->getUidColumn());
            $attributes->dataRead();
            
            // Overwrite existing values with those read from the $metadata
            $attributes->merge($new_relations);
            $attributes->dataUpdate(false, $transaction);
        }
        
        return $new_relations;
    }
    
    /**
     * Here is how an <Association> node looks like (provided, that each Delivery consists
     * of 0 to many Tasks).
     * 
<Association Name="DeliveryToTasks" sap:content-version="1">
    <End Type="Namespace.Delivery" Multiplicity="1" Role="FromRole_DeliveryToTasks"/>
    <End Type="Namespace.Task" Multiplicity="*" Role="ToRole_DeliveryToTasks"/>
    <ReferentialConstraint>
        <Principal Role="FromRole_DeliveryToTasks">
            <PropertyRef Name="DeliveryId"/>
        </Principal>
        <Dependent Role="ToRole_DeliveryToTasks">
            <PropertyRef Name="DeliveryId"/>
        </Dependent>
    </ReferentialConstraint>
</Association>
     * 
     * @param \DOMElement $association
     * @return array
     */
    private function getRelationDataFromAssociation(\DOMElement $association, AppInterface $app) : array
    {
        // This array needs to be filled
        $attributeData = [
            'UID' => null,
            'ALIAS' => null,
            'NAME' => null,
            'RELATED_OBJ' => null,
            'RELATED_OBJ_ATTR' => null
        ];
        
        $ends = [];
        foreach ($association->getElementsByTagName('End') as $endNode) {
            $ends[$endNode->getAttribute('Role')] = $endNode;
        }
        
        $constraintNode = $association->getElementsByTagName('ReferentialConstraint')->item(0);
        $principalNode = $constraintNode->getElementsByTagName('Principal')->item(0);
        $dependentNode = $constraintNode->getElementsByTagName('Dependent')->item(0);
        
        $leftEndNode = $ends[$dependentNode->getAttribute('Role')];
        $leftEntityType = $this->stripNamespace($leftEndNode->getAttribute('Type'));
        $leftObject = $app->getWorkbench()->model()->getObjectByAlias($leftEntityType, $app->getAliasWithNamespace());
        $leftAttributeAlias = $dependentNode->getElementsByTagName('PropertyRef')->getAttribute('Name');
        
        $rightEndNode = $ends[$principalNode->getAttribute('Role')];
        $rightEntityType = $this->stripNamespace($rightEndNode->getAttribute('Type'));
        $rightObject = $app->getWorkbench()->model()->getObjectByAlias($rightEntityType, $app->getAliasWithNamespace());
        $rightAttributeAlias = $principalNode->getElementsByTagName('PropertyRef')->getAttribute('Name');
        
        $attributeData['UID'] = $leftObject->getAttribute($leftAttributeAlias)->getId();
        $attributeData['ALIAS'] = $leftAttributeAlias;
        $attributeData['NAME'] = $rightObject->getName();
        $attributeData['RELATED_OBJ'] = $rightObject->getId();
        $rightKeyAttribute = $rightObject->getAttribute($rightAttributeAlias);
        $attributeData['RELATED_OBJ_ATTR'] = $rightKeyAttribute->isUidForObject() === false ? $rightKeyAttribute->getId() : '';
        
        return $attributeData;
    }
    
    /**
     * Returns a crawlable instance containing the entire metadata XML.
     * 
     * @return Crawler
     */
    protected function getMetadata() : Crawler
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
                'NAME' => $entityName,
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
        $sheet->setAutoCount(false);
        $object_uid = $object->getId();
        
        // Find the primary key
        $keys = $this->findPrimaryKeys($property_nodes);
        if (count($keys) === 1) {
            $primary_key = $keys[0];
        } else {
            $primary_key = false;
        }
        if (count($keys) > 1) {
            $object->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot import compound primary key for ' . $object->getAliasWithNamespace() . ' - please specify a UID manually if needed!'));
        }
        
        foreach ($property_nodes as $node) {
            $name = $node->getAttribute('Name');
            $dataType = $this->guessDataType($object, $node);
            
            $dataAddressProps = [
                'odata_type' => $node->getAttribute('Type')
            ];
            
            $row = [
                'NAME' => $this->generateLabel($name),
                'ALIAS' => $name,
                'DATATYPE' => $this->getDataTypeId($dataType),
                'DATA_ADDRESS' => $name,
                'DATA_ADDRESS_PROPS' => json_encode($dataAddressProps),
                'OBJECT' => $object_uid,
                'REQUIREDFLAG' => (strtolower($node->getAttribute('Nullable')) === 'false' ? 1 : 0),
                'UIDFLAG' => ($primary_key !== false && strcasecmp($name, $primary_key) === 0 ? 1 : 0)
            ];
            
            $dataTypeOptions = $this->getDataTypeConfig($dataType, $node);
            if (! $dataTypeOptions->isEmpty()) {
                $row['CUSTOM_DATA_TYPE'] = json_encode($dataTypeOptions->toArray());
            }
            
            $sheet->addRow($row);
        }
        return $sheet;
    }
    
    /**
     * 
     * @param Crawler $property_nodes
     * @return string[]
     */
    protected function findPrimaryKeys(Crawler $property_nodes) : array
    {
        $keys = [];
        $key_nodes = $property_nodes->siblings()->filterXPath('default:Key/default:PropertyRef');
        foreach ($key_nodes as $node) {
            $keys[] = $node->getAttribute('Name');
        }
        return $keys;
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
     * Returns the meta data type, that fit's the given XML node best.
     *
     * @param MetaObjectInterface $object
     * @param \DOMElement $node
     * @return DataTypeInterface
     */
    protected function guessDataType(MetaObjectInterface $object, \DOMElement $node) : DataTypeInterface
    {
        $workbench = $object->getWorkbench();
        $source_data_type = strtoupper($node->getAttribute('Type'));
        switch (true) {
            case (strpos($source_data_type, 'INT') !== false):
                $type = DataTypeFactory::createFromString($workbench, IntegerDataType::class);
                break;
            case (strpos($source_data_type, 'BYTE') !== false):
                $type = DataTypeFactory::createFromString($workbench, 'exface.Core.NumberNatural');
                break;
            case (strpos($source_data_type, 'FLOAT') !== false):
            case (strpos($source_data_type, 'DECIMAL') !== false):
            case (strpos($source_data_type, 'DOUBLE') !== false):
                $type = DataTypeFactory::createFromString($workbench, NumberDataType::class);
                break;
            case (strpos($source_data_type, 'BOOL') !== false):
                $type = DataTypeFactory::createFromString($workbench, BooleanDataType::class);
                break;
            case (strpos($source_data_type, 'DATETIME') !== false):
                $type = DataTypeFactory::createFromString($workbench, DateTimeDataType::class);
                break;
            case (strpos($source_data_type, 'DATE') !== false):
                $type = DataTypeFactory::createFromString($workbench, DateDataType::class);
                break;
            default:
                $type = DataTypeFactory::createFromString($workbench, StringDataType::class);
        }
        return $type;
    }
    
    /**
     * Returns a UXON configuration object for the given node and the target meta data type.
     *
     * @param DataTypeInterface $type
     * @param string $source_data_type
     */
    protected function getDataTypeConfig(DataTypeInterface $type, \DOMElement $node) : UxonObject
    {
        $options = [];
        switch (true) {
            case $type instanceof StringDataType:
                if ($length = $node->getAttribute('MaxLength')) {
                    $options['length_max'] = $length;
                }
                break;
            case $type instanceof NumberDataType:
                if ($scale = $node->getAttribute('Scale')) {
                    $options['precision'] = $scale;
                }
                if ($precision = $node->getAttribute('Precision')) {
                    $options['max'] = pow($type->getBase(), ($precision - $scale)) - 1;
                }
                break;
        }
        return new UxonObject($options);
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
    
    /**
     * Returns the Namespace of the schema in the given XML
     * 
     * @param Crawler $xml
     * @return string
     */
    protected function getNamespace(string $entityType) : string
    {
        return $this->getMetadata()->filterXPath('//default:Schema')->attr('Namespace');
    }
}