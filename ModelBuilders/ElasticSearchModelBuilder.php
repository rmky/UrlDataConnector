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
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\UrlDataConnector\Actions\CallOData2Operation;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\UrlDataConnector\DataConnectors\GraphQLConnector;
use exface\UrlDataConnector\Actions\CallGraphQLQuery;
use exface\UrlDataConnector\Actions\CallGraphQLMutation;
use exface\Core\Exceptions\ModelBuilders\ModelBuilderRuntimeError;
use exface\Core\DataTypes\DateTimeDataType;

/**
 * Creates metamodels from GraphQL introspection queries.
 * 
 * @method GraphQLConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class ElasticSearchModelBuilder extends AbstractModelBuilder implements ModelBuilderInterface 
{
    private $indexInfo = [];
    private $aliases = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object, string $addressPattern = '') : DataSheetInterface
    {
        $transaction = $meta_object->getWorkbench()->data()->startTransaction();
        
        $created_ds = $this->generateAttributes($meta_object, $transaction);
        
        /* TODO generate relations
        
        $entityType = $this->getEntityType($meta_object);
        
        $relationConstraints = $this->findRelationNodes($entityType);
        $this->generateRelations($meta_object->getApp(), $relationConstraints, $transaction);
        */
        
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
        $created_ds->setAutoCount(false);
        
        $indexName = $meta_object->getDataAddress();
        $indexInfo = $this->getIndexInfo($indexName);
        $imported_rows = $this->getAttributeData($indexName, $indexInfo, $meta_object)->getRows();
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
        $existing_objects->getFilters()->addConditionFromString('APP', $app->getUid(), EXF_COMPARATOR_EQUALS);
        $existing_objects->dataRead();
        
        $new_objects = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        
        $transaction = $app->getWorkbench()->data()->startTransaction();
        
        $imported_rows = $this->getObjectData($data_address_mask, $app, $source)->getRows();
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
                
                // Generate queries for object
                $queries = $this->getSchemaQueries($object);
                $this->generateActions($object, $queries, 'query', $transaction);
                
                // Generate mutations for object
                $mutations = $this->getSchemaMutations($object);
                $this->generateActions($object, $mutations, 'mutation', $transaction);
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
     * @param MetaObjectInterface $object
     * @param Crawler $functionImports
     * @param DataTransactionInterface $transaction
     * @return DataSheetInterface
     */
    protected function generateActions(MetaObjectInterface $object, array $types, string $operation, DataTransactionInterface $transaction) : DataSheetInterface
    {
        $newActions = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT_ACTION');
        $newActions->setAutoCount(false);
        $skipped = 0;
        
        foreach ($types as $type) {
            
            // Read action parameters
            $parameters = [];
            foreach ($type['args'] as $typeArg) {
                $pType = $this->guessDataType($object, $typeArg['type']);
                $parameter = [
                    'name' => $typeArg['name'],
                    'data_type' => [
                        'alias' => $pType->getAliasWithNamespace()
                    ]/* TODO,
                    'custom_properties' => [
                        
                    ]*/
                ];
                if (strcasecmp($typeArg['type']['kind'], 'NON_NULL') !== 0) {
                    $parameter = ['required' => true] + $parameter;
                }
                $pTypeOptions = $this->getDataTypeConfig($pType, $typeArg['type']['ofType'] ?? []);
                if (! $pTypeOptions->isEmpty()) {
                    $parameter['data_type'] = array_merge($parameter['data_type'], $pTypeOptions->toArray());
                }
                $parameters[] = $parameter;
            }
            
            // See if action alread exists in the model
            $existingAction = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.OBJECT_ACTION');
            $existingAction->getFilters()->addConditionFromString('APP', $object->getApp()->getUid(), EXF_COMPARATOR_EQUALS);
            $existingAction->getFilters()->addConditionFromString('ALIAS', $this->getActionAliasFromType($type['name'], $operation), EXF_COMPARATOR_EQUALS);
            $existingAction->getColumns()->addFromSystemAttributes()->addFromExpression('CONFIG_UXON');
            $existingAction->dataRead();
            
            // If it does not exist, create it. Otherwise update the parameters only (because they really MUST match the metadata)
            if ($existingAction->isEmpty()) {
                switch ($operation) {
                    case 'query':
                        $prototype = str_replace('\\', '/', CallGraphQLQuery::class) . '.php';
                        break;
                    case 'mutation':
                        $prototype = str_replace('\\', '/', CallGraphQLMutation::class) . '.php';
                        break;
                    default:
                        throw new ModelBuilderRuntimeError($this, 'Invalid GraphQL operation "' . $operation . '": expecting "query" or "mutation"!');
                }
                
                $actionConfig = new UxonObject([
                    $operation . '_name' => $type['name'],
                    'parameters' => $parameters
                ]);
                
                $resultType = $type['type'];
                if ($resultType['kind'] === 'OBJECT' && $resultObjectAlias = $resultType['name']) {
                    $actionConfig->setProperty('result_object_alias', $object->getNamespace() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER . $resultObjectAlias);
                }
                
                $actionData = [
                    'ACTION_PROTOTYPE' => $prototype,
                    'ALIAS' => $this->getActionAliasFromType($type['name'], $operation),
                    'APP' => $object->getApp()->getUid(),
                    'NAME' => $this->getNameFromAlias($type['name']),
                    'OBJECT' => $object->getId(),
                    'CONFIG_UXON' => $actionConfig->toJson(),
                    'SHORT_DESCRIPTION' => $type['description']
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
    
    protected function getActionAliasFromType(string $typeName, string $operation) : string
    {
        return $typeName . ucfirst($operation);
    }
    
    protected function getNameFromAlias(string $alias) : string
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
        $new_relations = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $skipped = 0;
        
        /* TODO
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
        }*/
        
        $new_relations->setCounterForRowsInDataSource($new_relations->countRows() + $skipped);
        
        if (! $new_relations->isEmpty()) {
            // To update attributes with new relation data, we need to read the current system columns first
            // (e.g. to allow TimeStampingBehavior, etc.)
            $attributes = $new_relations->copy();
            $attributes->getColumns()->addFromSystemAttributes();
            $attributes->getFilters()->addConditionFromColumnValues($attributes->getUidColumn());
            $attributes->dataRead();
            
            // Overwrite existing values with those read from the $metadata
            $attributes->merge($new_relations);
            $attributes->dataUpdate(false, $transaction);
        }
        
        return $new_relations;
    }
    
    /**
     * Returns a data sheet of exface.Core.OBJECT created from the given EntityTypes.
     * 
     * @param Crawler $entity_nodes
     * @param AppInterface $app
     * @param DataSourceInterface $data_source
     * @return DataSheetInterface
     */
    protected function getObjectData($namePattern, AppInterface $app, DataSourceInterface $data_source) 
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.OBJECT');
        $sheet->setAutoCount(false);
        $ds_uid = $data_source->getId();
        $app_uid = $app->getUid();
        
        foreach ($this->findIndexAliases($namePattern) as $index) {
            $sheet->addRow([
                'NAME' => $this->getNameFromAlias($index),
                'ALIAS' => $index,
                'DATA_ADDRESS' => $index,
                'DATA_ADDRESS_PROPS' => (new UxonObject(['elastic_index' => $index]))->toJson(),
                'DATA_SOURCE' => $ds_uid,
                'APP' => $app_uid
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
    protected function getAttributeData(string $indexName, array $indexInfo, MetaObjectInterface $object)
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $sheet->setAutoCount(false);
        $object_uid = $object->getId();
        $properties = $indexInfo[$indexName]['mappings'][$indexName]['properties'];
        
        // Find the primary key
        // TODO
        /*$keys = $this->findPrimaryKeys($property_nodes);
        if (count($keys) === 1) {
            $primary_key = $keys[0];
        } else {
            $primary_key = false;
        }
        if (count($keys) > 1) {
            $object->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot import compound primary key for ' . $object->getAliasWithNamespace() . ' - please specify a UID manually if needed!'));
        }*/
        
        foreach ($properties as $name => $property) {
            $dataType = $this->guessDataType($object, $property['type'], $property);
            
            $dataAddressProps = [
                //TODO
            ];
            
            $row = [
                'NAME' => $this->generateLabel($name),
                'ALIAS' => $name,
                'DATATYPE' => $this->getDataTypeId($dataType),
                'DATA_ADDRESS' => $name,
                //'DATA_ADDRESS_PROPS' => json_encode($dataAddressProps),
                'OBJECT' => $object_uid,
                'REQUIREDFLAG' => 0, // TODO
                'UIDFLAG' => 0 // TODO
            ];
            
            /* TODO
            $dataTypeOptions = $this->getDataTypeConfig($dataType, $field['type']['ofType'] ?? []);
            if (! $dataTypeOptions->isEmpty()) {
                $row['CUSTOM_DATA_TYPE'] = json_encode($dataTypeOptions->toArray());
            }*/
            
            $sheet->addRow($row);
        }
        return $sheet;
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
    protected function guessDataType(MetaObjectInterface $object, string $elasticType, array $propertyData = null) : DataTypeInterface
    {
        $workbench = $object->getWorkbench();
        switch (strtolower($elasticType)) {
            case 'integer':
            case 'short':
            case 'long':
            case 'double':
            case 'byte':
                $type = DataTypeFactory::createFromString($workbench, IntegerDataType::class);
                break;
            case 'float':
            case 'half_float':
            case 'scaled_float':
                $type = DataTypeFactory::createFromString($workbench, NumberDataType::class);
                break;
            case 'boolean':
                $type = DataTypeFactory::createFromString($workbench, BooleanDataType::class);
                break;
            case 'date':
                $type = DataTypeFactory::createFromString($workbench, DateTimeDataType::class);
                break;
            case 'text':
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
    protected function getDataTypeConfig(DataTypeInterface $type, array $ofType) : UxonObject
    {
        $options = [];
        /* TODO
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
        }*/
        return new UxonObject($options);
    }
    
    protected function getIndexInfo(string $indexName) : array
    {
        if ($this->indexInfo[$indexName] === null) {
            $this->indexInfo[$indexName] = $this->loadIndexInfo($indexName);
        }
        return $this->indexInfo[$indexName];
    }
    
    protected function loadIndexInfo(string $indexName) : array
    {
        $query = new Psr7DataQuery(
            new Request('GET', $indexName)
        );
        $result = $this->getDataConnection()->query($query);
        return json_decode($result->getResponse()->getBody()->__toString(), true);
    }
    
    protected function getIndexAliases() : array
    {
        if ($this->aliases === null) {
            $this->aliases = $this->loadIndexAliases();
        }
        return $this->aliases;
    }
    
    protected function loadIndexAliases() : array
    {
        $query = new Psr7DataQuery(
            new Request('GET', '_aliases')
            );
        $result = $this->getDataConnection()->query($query);
        return json_decode($result->getResponse()->getBody()->__toString(), true);
    }
    
    protected function findIndexAliases(string $pattern = null) : array
    {
        if ($pattern === null || $pattern === '') {
            return array_keys($this->getIndexAliases());
        }
        
        $result = [];
        foreach ($this->getIndexAliases() as $index => $info) {
            if (preg_match($pattern, $index) === 1) {
                $result[] = $index;
            }
        }
        return $result;
    }
}