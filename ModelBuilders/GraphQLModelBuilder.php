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
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\UrlDataConnector\Actions\CallOData2Operation;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\UrlDataConnector\DataConnectors\GraphQLConnector;
use exface\UrlDataConnector\Actions\CallGraphQLQuery;
use exface\UrlDataConnector\Actions\CallGraphQLMutation;
use exface\Core\Exceptions\ModelBuilders\ModelBuilderRuntimeError;

/**
 * Creates metamodels from GraphQL introspection queries.
 * 
 * @method GraphQLConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class GraphQLModelBuilder extends AbstractModelBuilder implements ModelBuilderInterface {
    
    private $schema = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object, string $addressPattern = '') : DataSheetInterface
    {
        $transaction = $meta_object->getWorkbench()->data()->startTransaction();
        
        $created_ds = $this->generateAttributes($meta_object, $transaction);
        
        /*$entityType = $this->getEntityType($meta_object);
        
        $relationConstraints = $this->findRelationNodes($entityType);
        $this->generateRelations($meta_object->getApp(), $relationConstraints, $transaction);
        */
        
        // Generate queries for object
        $queries = $this->getSchemaQueries($meta_object);
        $this->generateActions($meta_object, $queries, 'query', $transaction);
        
        // Generate mutations for object
        $mutations = $this->getSchemaMutations($meta_object);
        $this->generateActions($meta_object, $mutations, 'mutation', $transaction);
        
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
        
        $objectType = $this->getSchemaOfType($meta_object->getDataAddress());
        if ($objectType === null) {
            return $created_ds;
        }
        
        $imported_rows = $this->getAttributeData($objectType, $meta_object)->getRows();
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
    
    protected function getSchemaOfType(string $typeName) : ?array
    {
        foreach ($this->getSchema()['types'] as $type) {
            if ($type['name'] === $typeName) {
                return $type;
            }
        }
        return null;
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
     * Create action models for function queries or mutations.
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
     * Returns a crawlable instance containing the entire metadata XML.
     * 
     * @return array
     */
    protected function getSchema() : array
    {
        if (is_null($this->schema)) {
            $body = $this->buildGqlBody($this->buildGqlIntrospectionQuery(), 'IntrospectionQuery');
            $query = new Psr7DataQuery(new Request('POST', '', ['Content-Type' => 'application/json'], $body));
            $query = $this->getDataConnection()->query($query);
            $json = json_decode($query->getResponse()->getBody(), true);
            $this->schema = $json['data']['__schema'];
        }
        return $this->schema;
    }
    
    protected function getSchemaQueries(MetaObjectInterface $metaObject = null) : array
    {
        $types = $this->getSchemaOfType($this->getSchemaQueryTypeName())['fields'] ?? [];
        if ($metaObject !== null) {
            $types = $this->filterSchemaActionsForObject($types, $metaObject);
        }
        return $types;
    }
    
    protected function getSchemaMutations(MetaObjectInterface $metaObject = null) : array
    {
        $types = $this->getSchemaOfType($this->getSchemaMutationTypeName())['fields'] ?? [];
        if ($metaObject !== null) {
            $types = $this->filterSchemaActionsForObject($types, $metaObject);
        }
        return $types;
    }
    
    protected function filterSchemaActionsForObject(array $types, MetaObjectInterface $object) : array
    {
        $result = [];
        $objectType = $this->getTypeNameForObject($object);
        foreach ($types as $type) {
            // Single return type matching the object
            if ($type['type']['kind'] === 'OBJECT' 
                && $type['type']['name'] === $objectType) {
                $result[] = $type;
            }
            // List return type matchnig the object
            if ($type['type']['ofType'] 
                && $type['type']['ofType']['kind'] === 'LIST' 
                && $type['type']['ofType']['ofType']
                && $type['type']['ofType']['ofType']['ofType']
                && $type['type']['ofType']['ofType']['ofType']['kind'] === 'OBJECT'
                && $type['type']['ofType']['ofType']['ofType']['name'] === $objectType) {
                $result[] = $type;
            }
        }
        return $result;
    }
    
    protected function getTypeNameForObject(MetaObjectInterface $object) : string
    {
        return $object->getDataAddressProperty('graphql_type') ?? $object->getAlias();
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
        $ds_uid = $data_source->getId();
        $app_uid = $app->getUid();
        
        foreach ($this->getSchemaObjects() as $type) {
            $typeName = $type['name'];
            
            // Check name pattern
            if ($namePattern && preg_match($namePattern, $typeName) !== 1) {
                continue;
            }
            
            $sheet->addRow([
                'NAME' => $this->getNameFromAlias($typeName),
                'ALIAS' => $typeName,
                'DATA_ADDRESS' => $typeName,
                'DATA_ADDRESS_PROPS' => (new UxonObject(['graphql_type' => $typeName]))->toJson(),
                'DATA_SOURCE' => $ds_uid,
                'APP' => $app_uid,
                'SHORT_DESCRIPTION' => $type['description']
            ]);
        }
        return $sheet;
    }
    
    protected function getSchemaQueryTypeName() : string
    {
        return $this->getSchema()['queryType']['name'];
    }
    
    protected function getSchemaMutationTypeName() : string
    {
        return $this->getSchema()['mutationType']['name'];
    }
    
    protected function getSchemaObjects() : array
    {
        $schema = $this->getSchema();
        $types = $schema['types'];
        $queryType = $this->getSchemaQueryTypeName();
        $mutationType = $this->getSchemaMutationTypeName();
        $objects = [];
        
        foreach ($types as $type) {
            $name = $type['name'];
            
            // Skip non-objects
            if ($type['kind'] !== 'OBJECT') {
                continue;
            }
            
            // Skip internal types
            if (substr($name, 0, 2) === '__') {
                continue;
            }
            if ($name === $queryType || $name === $mutationType) {
                continue;
            }
            
            $objects[] = $type;
        }
        
        return $objects;
    }
    
    /**
     * Reads the metadata for Properties into a data sheet based on exface.Core.ATTRIBUTE.
     * 
     * @param Crawler $property_nodes
     * @param MetaObjectInterface $object
     * @return DataSheetInterface
     */
    protected function getAttributeData(array $objectType, MetaObjectInterface $object)
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $sheet->setAutoCount(false);
        $object_uid = $object->getId();
        
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
        
        foreach ($objectType['fields'] as $field) {
            $name = $field['name'];
            $dataType = $this->guessDataType($object, $field['type']);
            
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
                'REQUIREDFLAG' => ($field['type']['kind'] === 'NON_NULL' ? 1 : 0),
                'UIDFLAG' => 0,
                'SHORT_DESCRIPTION' => $field['description']
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
    protected function guessDataType(MetaObjectInterface $object, array $typeData) : DataTypeInterface
    {
        $workbench = $object->getWorkbench();
        $ofType = $typeData['ofType'];
        switch (true) {
            case $typeData['kind'] === 'SCALAR' && $typeData['name'] === 'Int':
                $type = DataTypeFactory::createFromString($workbench, IntegerDataType::class);
                break;
            /*case (strpos($source_data_type, 'BYTE') !== false):
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
                break;*/
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
    
    protected function buildGqlBody(string $gqlQuery, string $operationName, array $variables = []) : string
    {
        return json_encode([
            "operationName" => $operationName,
            //"variables" => $variables,
            "query" => $gqlQuery
        ]);
    }
    
    protected function buildGqlIntrospectionQuery() : string
    {
        return <<<GraphQL
query IntrospectionQuery {
    __schema {
        queryType {
            name
        }
        mutationType {
            name
        }
        subscriptionType {
            name
        }
        types {
            ...FullType
        }
        directives {
            name
            description
            locations
            args {
                ...InputValue
            }
        }
    }
}

fragment FullType on __Type {
    kind
    name
    description
    fields(includeDeprecated: true) {
        name
        description
        args {
            ...InputValue
        }
        type {
            ...TypeRef
        }
        isDeprecated
        deprecationReason
    }
    inputFields {
        ...InputValue
    }
    interfaces {
        ...TypeRef
    }
    enumValues(includeDeprecated: true) {
        name
        description
        isDeprecated
        deprecationReason
    }
    possibleTypes {
        ...TypeRef
    }
}

fragment InputValue on __InputValue {
    name
    description
    type {
        ...TypeRef
    }
    defaultValue
}

fragment TypeRef on __Type {
    kind
    name
    ofType {
        kind
        name
        ofType {
            kind
            name
            ofType {
                kind
                name
                ofType {
                    kind
                    name
                    ofType {
                        kind
                        name
                        ofType {
                            kind
                            name
                            ofType {
                                kind
                                name
                            }
                        }
                    }
                }
            }
        }
    }
}
GraphQL;
    }
}