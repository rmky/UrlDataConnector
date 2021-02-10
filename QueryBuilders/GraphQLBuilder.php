<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilterGroup;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use exface\Core\CommonLogic\DataQueries\DataQueryResultData;
use exface\Core\CommonLogic\QueryBuilder\QueryPartSorter;
use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use exface\Core\CommonLogic\Model\Condition;
use exface\Core\CommonLogic\Model\ConditionGroup;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\QueryBuilder\QueryPartAttribute;
use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\CommonLogic\QueryBuilder\QueryPartValue;
use Psr\Http\Message\RequestInterface;
use exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder;

/**
 * This is a query builder for GraphQL.
 * 
 * NOTE: this query builder is an early beta and has many limitations:
 * 
 * - no remote filtering
 * - no remote sorting
 * - no remote pagination
 * - cannot create/update with relations (nested data)
 * - cannot read related attibutes (nested data)
 * 
 * # Data source options
 * 
 * ## On object level
 * 
 * - `graphql_type`
 * 
 * - `graphql_read_query`
 * 
 * - `graphql_count_query`
 * 
 * - `graphql_create_mutation`
 * 
 * - `graphql_update_mutation`
 * 
 * - `graphql_delete_mutation`
 * 
 * - `graphql_remote_pagination` - set to `false` to disable remote pagination.
 * If not set or set to `true`, `request_offset_parameter` and `request_limit_parameter`
 * must be set in the data source configuration to make pagination work.
 * 
 * - `graphql_offset_argument` - name of the query argument containing the 
 * page offset for pagination
 * 
 * - `graphql_limit_argument` - name of the query argument holding the 
 * maximum number of returned items
 * 
 * ## On attribute level
 * 
 * - `filter_remote` - set to 1 to enable remote filtering (0 by default)
 * 
 * - `filter_remote_url` - used to set a custom URL to be used if there is a 
 * filter over this attribute. The URL accepts the placeholder [#~value#] which
 * will be replaced by the. Note, that if the URL does not have the placeholder,
 * it will be always the same - regardles of what the filter is actually set to. 
 * 
 * - `filter_remote_argument` - used for filtering instead of the attributes 
 * data address: e.g. &[filter_remote_argument]=VALUE instead of 
 * &[data_address]=VALUE
 * 
 * - `filter_remote_prefix` - prefix for the value in a filter query: e.g. 
 * &[data_address]=[filter_remote_prefix]VALUE. Can be used to pass default 
 * operators etc.
 * 
 * - `filter_locally` - set to 1 to filter in ExFace after reading the data
 * (e.g. if the data source does not support filtering over this attribute) or
 * set to 0 to take the data as it is. If not set, the data will be filtered
 * locally automatically if no remote filtering is configured.
 * 
 * - `sort_remote` - set to 1 to enable remote sorting (0 by default)
 * 
 * - `sort_remote_argument` - used for sorting instead of the attributes 
 * data address: e.g. &[sort_remote_argument]=VALUE instead of 
 * &[data_address]=VALUE
 * 
 * - `sort_locally` - set to 1 to sort in ExFace after reading the data (if 
 * the data source does not support filtering over this attribute).
 * 
 * - `create_data_address` - GraphQL field to use in the `graphql_create_mutation`.
 * If not set, the data address will be used.
 * 
 * - `read_data_address` - GraphQL field to use in the `graphql_read_mutation`.
 * If not set, the data address will be used.
 * 
 * - `update_data_address` - GraphQL field to use in the `graphql_update_mutation`.
 * If not set, the data address will be used
 * 
 * @author Andrej Kabachnik
 *        
 */
class GraphQLBuilder extends AbstractQueryBuilder
{   
    const CRUD_ACTION_READ = 'read';
    const CRUD_ACTION_UPDATE = 'update';
    const CRUD_ACTION_CREATE = 'create';
    const CRUD_ACTION_DELETE = 'delete';
    
    protected function buildGqlQueryRead() : string
    {
        $query = $this->buildGqlReadQueryName($this->getMainObject());
        $fields = $this->buildGqlQueryFields($this->getAttributes());
        return $this->buildGqlQuery($query, $fields);
    }
    
    /**
     * 
     * @param QueryPartAttribute[] $qparts
     * @throws QueryBuilderException
     * @return string
     */
    protected function buildGqlQueryFields(array $qparts) : string
    {
        $fields = [];
        foreach ($qparts as $qpart) {
            if (count($qpart->getUsedRelations()) > 0) {
                throw new QueryBuilderException('GraphQL queries with relations not yet supported!');
            }
            $fields[] = $this->buildGqlField($qpart, self::CRUD_ACTION_READ);
        }
        $fields = array_unique(array_filter($fields));
        return implode("\r\n        ", $fields);
    }
    
    protected function buildGqlQuery(string $queryName, string $fields) : string
    {
        return <<<GraphQL

query {
    {$queryName} {
        {$fields}
    }
} 

GraphQL;
    }
        
    protected function buildGqlReadQueryName(MetaObjectInterface $object) : string 
    {
        return $object->getDataAddress();
    }
    
    protected function buildGqlField(QueryPartAttribute $qpart, string $crudAction = self::CRUD_ACTION_READ) : string
    {
        switch ($crudAction) {
            case self::CRUD_ACTION_CREATE: $addr = $qpart->getDataAddressProperty(AbstractUrlBuilder::DAP_CREATE_DATA_ADDRESS); break;
            case self::CRUD_ACTION_READ: $addr = $qpart->getDataAddressProperty('read_data_address'); break;
            case self::CRUD_ACTION_UPDATE: $addr = $qpart->getDataAddressProperty(AbstractUrlBuilder::DAP_UPDATE_DATA_ADDRESS); break;
        }
        return $addr ?? $qpart->getAttribute()->getDataAddress();
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::parseResponse()
     */
    protected function parseResponse(Psr7DataQuery $query)
    {
        return json_decode($query->getResponse()->getBody(), true);
    }
    
    protected function readResultRows(array $data, string $operation) : array
    {
        $data = $data['data'][$operation] ?? [];
        $rows = [];
        foreach ($data as $dataRow) {
            $row = [];
            foreach ($this->getAttributes() as $qpart) {
                if ($field = $qpart->getDataAddress()) {
                    $row[$qpart->getColumnKey()] = $dataRow[$field];
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }
    
    protected function buildGqlRequest(string $body) : RequestInterface
    {
        return new Request('POST', '', ['Content-Type' => 'application/graphql'], $body);
    }
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::read()
     */
    public function read(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $result_rows = array();
        $totalCnt = null;
        
        // Increase limit by one to check if there are more rows
        $usingExtraRowForPagination = false;
        if ($this->isRemotePaginationConfigured() === true) {
            $originalLimit = $this->getLimit();
            if ($originalLimit > 0) {
                $usingExtraRowForPagination = true;
                $this->setLimit($originalLimit+1, $this->getOffset());
            }
        }
        
        $query = $data_connection->query(
            new Psr7DataQuery(
                $this->buildGqlRequest($this->buildGqlQueryRead())
            )
        );
        if ($data = $this->parseResponse($query)) {
            // Find the total row counter within the response
            //$totalCnt = $this->findRowCounter($data, $query);
            // Find data rows within the response and do the postprocessing
            $result_rows = $this->readResultRows($data, $this->buildGqlReadQueryName($this->getMainObject()));
            
            // If we increased the limit artificially, pop off the last result row as it
            // would not be there normally.
            if ($usingExtraRowForPagination === true && count($result_rows) === ($originalLimit+1)) {
                $hasMoreRows = true;
                array_pop($result_rows);
            } else {
                $hasMoreRows = false;
            }
            
            // Apply local filters
            $cnt_before_local_filters = count($result_rows);
            $result_rows = $this->applyFilters($result_rows);
            $cnt_after_local_filters = count($result_rows);
            if ($cnt_before_local_filters !== $cnt_after_local_filters) {
                $totalCnt = $cnt_after_local_filters;
            }
            
            // Apply local sorting
            $result_rows = $this->applySorting($result_rows);
            
            // Apply local pagination
            if (! $this->isRemotePaginationConfigured()) {
                if (! $totalCnt) {
                    $totalCnt = count($result_rows);
                }
                $result_rows = $this->applyPagination($result_rows);
            }
        } else {
            $hasMoreRows = false;
        }
        
        if ($hasMoreRows === false && ! $totalCnt) {
            $totalCnt = count($result_rows);
        }
        
        $rows = array_values($result_rows);
        
        return new DataQueryResultData($rows, count($result_rows), $hasMoreRows, $totalCnt);
    }
    
    /**
     * Generally UrlBuilders can only handle attributes of one objects - no relations (JOINs) supported!
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::canReadAttribute()
     */
    public function canReadAttribute(MetaAttributeInterface $attribute) : bool
    {
        return $attribute->getRelationPath()->isEmpty();
    }
    
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addFilter()
     */
    protected function addFilter(QueryPartFilter $filter)
    {
        $result = parent::addFilter($filter);
        $this->prepareFilter($filter);
        return $result;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addFilterCondition()
     */
    public function addFilterCondition(Condition $condition)
    {
        $qpart = parent::addFilterCondition($condition);
        $this->prepareFilter($qpart);
        return $qpart;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addFilterGroup()
     */
    protected function addFilterGroup(QueryPartFilterGroup $filter_group)
    {
        $result = parent::addFilterGroup($filter_group);
        $this->prepareFilterGroup($filter_group);
        return $result;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addFilterConditionGroup()
     */
    public function addFilterConditionGroup(ConditionGroup $condition_group)
    {
        $qpart = parent::addFilterConditionGroup($condition_group);
        $this->prepareFilterGroup($qpart);
        return $qpart;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::setFiltersConditionGroup()
     */
    public function setFiltersConditionGroup(ConditionGroup $condition_group)
    {
        $result = parent::setFiltersConditionGroup($condition_group);
        $this->prepareFilterGroup($this->getFilters());
        return $result;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::setFilters()
     */
    protected function setFilters(QueryPartFilterGroup $filter_group)
    {
        $result = parent::setFilters($filter_group);
        $this->prepareFilterGroup($filter_group);
        return $result;
    }
    
    /**
     *
     * @param QueryPartFilterGroup $group
     * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartFilterGroup
     */
    protected function prepareFilterGroup(QueryPartFilterGroup $group)
    {
        foreach ($group->getFilters() as $qpart) {
            $this->prepareFilter($qpart);
        }
        
        foreach ($group->getNestedGroups() as $qpart) {
            $this->prepareFilterGroup($qpart);
        }
        
        return $group;
    }
    
    /**
     * Checks the custom filter configuration of a query part for consistency.
     *
     * @param QueryPartFilter $qpart
     * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartFilter
     */
    protected function prepareFilter(QueryPartFilter $qpart)
    {
        // Enable local filtering if remote filters are not enabled and local filtering is not explicitly off
        if (! $qpart->getDataAddressProperty(AbstractUrlBuilder::DAP_FILTER_REMOTE) && (is_null($qpart->getDataAddressProperty(AbstractUrlBuilder::DAP_FILTER_LOCALLY)) || $qpart->getDataAddressProperty(AbstractUrlBuilder::DAP_FILTER_LOCALLY) === '')) {
            $qpart->setDataAddressProperty(AbstractUrlBuilder::DAP_FILTER_REMOTE, 1);
        }
        
        // If a local filter is to be applied in postprocessing, mark the respective query part and make sure, the attribute is always
        // in the result - otherwise there will be nothing to filter over ;)
        if ($qpart->getDataAddressProperty(AbstractUrlBuilder::DAP_FILTER_LOCALLY)) {
            $qpart->setApplyAfterReading(true);
            if ($qpart->getAttribute()) {
                $this->addAttribute($qpart->getAlias());
            }
        }
        return $qpart;
    }
    
    /**
    *
    * {@inheritDoc}
    * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::addSorter()
    */
    public function addSorter($sort_by, $order = 'ASC') {
        $qpart = parent::addSorter($sort_by, $order);
        $this->prepareSorter($qpart);
        return $qpart;
    }
    
    /**
     * Checks the custom sorting configuration of a query part for consistency.
     *
     * @param QueryPartSorter $qpart
     * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartSorter
     */
    protected function prepareSorter(QueryPartSorter $qpart)
    {
        // If there are options for remote sorting set and the sort_remote address property is not explicitly off, enable it
        if ($qpart->getDataAddressProperty('sort_remote_graphql_field')) {
            if ($qpart->getDataAddressProperty(static::DAP_SORT_REMOTE) === '' || is_null($qpart->getDataAddressProperty(static::DAP_SORT_REMOTE))) {
                $qpart->setDataAddressProperty(static::DAP_SORT_REMOTE, 1);
            }
        }
        
        // Enable local sorting if remote sort is not enabled and local sorting is not explicitly off
        if (! $qpart->getDataAddressProperty(static::DAP_SORT_REMOTE) && (is_null($qpart->getDataAddressProperty(static::DAP_SORT_LOCALLY)) || $qpart->getDataAddressProperty(static::DAP_SORT_LOCALLY) === '')) {
            $qpart->setDataAddressProperty(static::DAP_SORT_LOCALLY, 1);
        }
        
        // If a local sorter is to be applied in postprocessing, mark the respective query part and make sure, the attribute is always
        // in the result - otherwise there will be nothing to sort over ;)
        if ($qpart->getDataAddressProperty(static::DAP_SORT_LOCALLY)) {
            $qpart->setApplyAfterReading(true);
            if ($qpart->getAttribute()) {
                $this->addAttribute($qpart->getAlias());
            }
        }
        
        return $qpart;
    }
    
    /**
     * Returns TRUE if remote pagination for the main object is enabled and the
     * request parameters for limit and offset are set - FALSE otherwise.
     *
     * @return boolean
     */
    protected function isRemotePaginationConfigured() : bool
    {
        return false;
        
        $dsOption = $this->getMainObject()->getDataAddressProperty(AbstractUrlBuilder::DAP_REQUEST_REMOTE_PAGINATION);
        if ($dsOption === null) {
            // TODO
            // return $this->buildUrlParamLimit($this->getMainObject()) ? true : false;
        } else {
            // TODO
            // return BooleanDataType::cast($dsOption) && $this->buildUrlParamLimit($this->getMainObject());
        }
        return false;
    }
    
    protected function buildGqlMutation(string $mutationName, array $argFieldsValues, array $returnFields) : string
    {
        $arguments = '';
        $returns = '';
        foreach ($argFieldsValues as $field => $value) {
            $arguments .= "        {$field}: {$value}\r\n";
        }
        $arguments = trim($arguments);
        foreach ($returnFields as $field) {
            $returns .= "        {$field}\r\n";
        }
        $returns = trim($returns);
        return <<<GraphQL

mutation {
    {$this->buildGqlMutationCreateName($this->getMainObject())} (
        {$arguments}
    ) {
        {$returns}
    }
} 

GraphQL;
    }
    
    protected function buildGqlValue(QueryPartValue $qpart, $value) : string
    {
        return '"' . str_replace('"', '\"', $value) . '"';
    }
    
    protected function buildGqlMutationCreateName(MetaObjectInterface $object) : string
    {
        if (! $name = $object->getDataAddressProperty('graphql_create_mutation')) {
            throw new QueryBuilderException('Cannot create object "' . $object->getName() . '" [' . $object->getAliasWithNamespace() . '] via GraphQL: no graphql_create_mutation data address property defined!', '76G66CL');
        }
        return $name;
    }
    
    protected function buildGqlMutationUpdateName(MetaObjectInterface $object) : string
    {
        if (! $name = $object->getDataAddressProperty('graphql_update_mutation')) {
            throw new QueryBuilderException('Cannot update object "' . $object->getName() . '" [' . $object->getAliasWithNamespace() . '] via GraphQL: no graphql_create_mutation data address property defined!', '76G66CL');
        }
        return $name;
    }
    
    protected function buildGqlMutationDeleteName(MetaObjectInterface $object) : string
    {
        if (! $name = $object->getDataAddressProperty('graphql_delete_mutation')) {
            throw new QueryBuilderException('Cannot delete object "' . $object->getName() . '" [' . $object->getAliasWithNamespace() . '] via GraphQL: no graphql_create_mutation data address property defined!', '76G66CL');
        }
        return $name;
    }
    
    public function create(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $resultIds = array();
        
        $object = $this->getMainObject();
        $mutationName = $this->buildGqlMutationCreateName($object);
        if ($object->hasUidAttribute() === true) {
            $uidField = $object->getUidAttribute()->getDataAddress();
            $uidAlias = $object->getUidAttributeAlias();
        }
        
        foreach ($this->buildGqlMutationRows(self::CRUD_ACTION_CREATE) as $row) {
            $mutation = $this->buildGqlMutation($mutationName, $row, [$uidField]);
            $query = new Psr7DataQuery($this->buildGqlRequest($mutation));
            $result = $this->parseResponse($data_connection->query($query));
            $resultIds[] = [$uidAlias => $result['data'][$mutationName][$uidField]];
        }
        
        return new DataQueryResultData($resultIds, count($resultIds), false);
    }
    
    public function update(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $resultIds = array();
        
        $object = $this->getMainObject();
        $mutationName = $this->buildGqlMutationDeleteName($object);
        if ($object->hasUidAttribute() === true) {
            $uidField = $object->getUidAttribute()->getDataAddress();
            $uidAlias = $object->getUidAttributeAlias();
        }
        
        foreach ($this->buildGqlMutationRows(self::CRUD_ACTION_UPDATE) as $row) {
            $mutation = $this->buildGqlMutation($mutationName, $row, [$uidField]);
            $query = new Psr7DataQuery($this->buildGqlRequest($mutation));
            $result = $this->parseResponse($data_connection->query($query));
            $resultIds[] = [$uidAlias => $result['data'][$mutationName][$uidField]];
        }
        
        return new DataQueryResultData([], count($resultIds), false);
    }
    
    protected function buildGqlMutationRows($createOrUpdate = self::CRUD_ACTION_CREATE) : array
    {
        $rows = [];
        
        foreach ($this->getValues() as $qpart) {
            $field = $this->buildGqlField($qpart, $createOrUpdate);
            foreach ($qpart->getValues() as $rowNr => $val) {
                if (count($qpart->getUsedRelations()) > 0) {
                    throw new QueryBuilderException('GraphQL mutations with relations not yet supported!');
                }
                $rows[$rowNr][$field] = $this->buildGqlValue($qpart, $val);
            }
        }
        
        return $rows;
    }
    
}