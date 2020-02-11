<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\Exceptions\QueryBuilderException;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilterGroup;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use exface\Core\CommonLogic\DataQueries\DataQueryResultData;
use exface\Core\CommonLogic\QueryBuilder\QueryPartValue;
use exface\Core\CommonLogic\QueryBuilder\QueryPartAttribute;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\TimeDataType;
use exface\Core\Interfaces\Model\CompoundAttributeInterface;

/**
 * This is a query builder for JSON-based oData 2.0 APIs.
 * 
 * See the AbstractUrlBuilder for information about available data address properties.
 * In addition, this query builder provides the following options
 * 
 * ## On object level
 * 
 * - `odata_$inlinecount` - controls the inlinecount feature of OData. Set to `allpages`
 * to request an inlinecount from the server.
 *
 * @see AbstractUrlBuilder for data source specific parameters
 * 
 * @author Andrej Kabachnik
 *        
 */
class OData2JsonUrlBuilder extends JsonUrlBuilder
{
    /**
     * 
     * @return string
     */
    protected function getODataVersion() : string
    {
        return '2';
    }
    
    /**
     * 
     * @return string
     */
    protected function getDefaultPathToResponseRows() : string
    {
        return 'd';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\JsonUrlBuilder::buildPathToResponseRows()
     */
    protected function buildPathToResponseRows(Psr7DataQuery $query)
    {
        $path = parent::buildPathToResponseRows($query);
        
        if (is_null($path)) {
            $path = $this->getDefaultPathToResponseRows();
        }
        
        return $path;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\JsonUrlBuilder::buildResultRows()
     */
    protected function buildResultRows($parsed_data, Psr7DataQuery $query)
    {
        $rows = parent::buildResultRows($parsed_data, $query);
        
        foreach ($this->getAttributes() as $qpart) {
            $dataType = $qpart->getDataType();
            switch (true) {
                case $dataType instanceof TimeDataType:
                    foreach ($rows as $rowNr => $row) {
                        $val = $row[$qpart->getDataAddress()];
                        $timeParts = [];
                        if (preg_match('/PT(\d{1,2}H)?(\d{1,2}M)?(\d{1,2}S)?/', $val, $timeParts)) {
                            $hours = '00';
                            $minutes = '00';
                            $seconds = null;
                            for ($i = 1; $i <= 3; $i++) {
                                switch (strtoupper(substr($timeParts[$i], 0, -1))) {
                                    case 'H' : $hours = substr($timeParts[$i], 2); break;
                                    case 'M' : $minutes = substr($timeParts[$i], 2); break;
                                    case 'S' : $seconds = substr($timeParts[$i], 2); break;
                                }
                                
                            }
                            $rows[$rowNr][$qpart->getDataAddress()] = $hours . ':' . $minutes . ($seconds !== null ? ':' . $seconds : '');
                        }
                        
                    }
                    break;
                // Add more custom data type handling here
            }
        }
        
        return $rows;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::count()
     */
    public function count(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        $uri = $this->buildRequestGet()->getUri();
        $count_uri = $uri->withPath($uri->getPath() . '/$count');
        
        $count_url_params = $uri->getQuery();
        $count_url_params = preg_replace('/\&?' . preg_quote($this->buildUrlParamLimit($this->getMainObject())) . '=\d*/', "", $count_url_params);
        $count_url_params = preg_replace('/\&?' . preg_quote($this->buildUrlParamOffset($this->getMainObject())) . '=\d*/', "", $count_url_params);
        $count_url_params = preg_replace('/\&?\$format=.*/', "", $count_url_params);
        $count_uri = $count_uri->withQuery($count_url_params);
        $count_query = new Psr7DataQuery(new Request('GET', $count_uri));
        $count_query->setUriFixed(true);
        
        try {
            $count_query = $this->getMainObject()->getDataConnection()->query($count_query);
            $count = (string) $count_query->getResponse()->getBody();
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e, LoggerInterface::WARNING);
        }
        
        return new DataQueryResultData([], ($count ?? 0), false, $count);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildPathToTotalRowCounter()
     */
    protected function buildPathToTotalRowCounter(MetaObjectInterface $object)
    {
        return 'd/__count';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlParamOffset()
     */
    protected function buildUrlParamOffset(MetaObjectInterface $object)
    {
        $custom_param = parent::buildUrlParamOffset($object);
        return $custom_param ? $custom_param : '$skip';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlParamLimit()
     */
    protected function buildUrlParamLimit(MetaObjectInterface $object)
    {
        $custom_param = parent::buildUrlParamLimit($object);
        return $custom_param ? $custom_param : '$top';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlPagination()
     */
    protected function buildUrlPagination() : string
    {
        $params = parent::buildUrlPagination();
        if ($params !== '' && $inlinecount = $this->getMainObject()->getDataAddressProperty('odata_$inlinecount')) {
            $params .= '&$inlinecount=' . $inlinecount;
        }
        return $params;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlFilterGroup()
     */
    protected function buildUrlFilterGroup(QueryPartFilterGroup $qpart, bool $isNested = false)
    {
        $query = '';
        
        // If the filter group is just a wrapper, ignore it and build only the contents: e.g.
        // AND(AND(expr1=val1, expr2=val2)) -> AND(expr1=val1, expr2=val2)
        if (! $qpart->hasFilters() && count($qpart->getNestedGroups()) === 1) {
            return $this->buildUrlFilterGroup($qpart->getNestedGroups()[0]);
        }
        
        $op = ' ' . $this->buildUrlFilterGroupOperator($qpart->getOperator()) . ' ';
        
        foreach ($qpart->getFilters() as $filter) {
            if ($filter->isCompound() === true) {
                $stmt = $this->buildUrlFilterGroup($filter->getCompoundFilterGroup(), true);
            } else {
                $stmt = $this->buildUrlFilter($filter);
            }
            
            if ($stmt) {
                $query .= ($query ? $op : '') . $stmt;
            }
        }
        
        foreach ($qpart->getNestedGroups() as $group) {
            if ($stmt = $this->buildUrlFilterGroup($group, true)) {
                $query .= ($query ? $op.' ' : '') . '(' . $stmt . ')';
            }
        }
        
        if ($query !== '' && $isNested === false) {
            $query = '$filter=' . $query;
        }
        
        return $query;
    }
    
    protected function buildUrlFilterGroupOperator(string $logicalOperator) : string
    {
        switch (strtoupper($logicalOperator)) {
            case EXF_LOGICAL_XOR:
            case EXF_LOGICAL_NULL:
                throw new QueryBuilderException('Logical operator "' . $logicalOperator . '" not supported by query builder "' . get_class($this) . '"!');
            default:
                return strtolower($logicalOperator);
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlFilter()
     */
    protected function buildUrlFilter(QueryPartFilter $qpart)
    {
        $param = $this->buildUrlParamFilter($qpart);
        
        if (! $param) {
            return '';
        }
        
        $value = null;
        // Add a prefix to the value if needed
        if ($prefix = $qpart->getDataAddressProperty('filter_remote_prefix')) {
            $value = $prefix . $this->buildUrlFilterValue($qpart);
        }
        
        return $this->buildUrlFilterPredicate($qpart, $param, $value);
    }
    
    /**
     * Returns a filter predicate to be used in $filter (e.g. "Price le 100").
     * 
     * This method is separated from buildUrlFilter() in order be able to override just the
     * predicate generation in other OData builders, leaving common checks and enrichment
     * in buildUrlFilter().
     * 
     * @param QueryPartFilter $qpart
     * @param string $property
     * @param string $escapedValue
     * @return string
     */
    protected function buildUrlFilterPredicate(QueryPartFilter $qpart, string $property, string $preformattedValue = null) : string
    {
        $comp = $qpart->getComparator();
        $type = $qpart->getDataType();
        
        switch ($comp) {
            case EXF_COMPARATOR_IS:
            case EXF_COMPARATOR_IS_NOT:
                $escapedValue = $preformattedValue ?? $this->buildUrlFilterValue($qpart);
                switch (true) {
                    case $type instanceof NumberDataType:
                    case $type instanceof DateDataType:
                    case $type instanceof BooleanDataType:
                        $op = ($comp === EXF_COMPARATOR_IS_NOT ? 'ne' : 'eq');
                        return "{$property} {$op} {$escapedValue}";
                    default:
                        return "substringof({$escapedValue}, {$property})" . ($comp === EXF_COMPARATOR_IS_NOT ? ' ne' : ' eq') . ' true';
                }
            case EXF_COMPARATOR_IN:
            case EXF_COMPARATOR_NOT_IN:
                if ($comp === EXF_COMPARATOR_NOT_IN) {
                    $op = 'ne';
                    $glue = ' and ';
                } else {
                    $op = 'eq';
                    $glue = ' or ';
                }
                $values = is_array($qpart->getCompareValue()) === true ? $qpart->getCompareValue() : explode($qpart->getAttribute()->getValueListDelimiter(), $qpart->getCompareValue());
                $ors = [];
                foreach ($values as $val) {
                    $ors[] = $property . ' ' . $op . ' ' . $this->buildUrlFilterValue($qpart, $val);
                }
                if (empty($ors) === false) {
                    return '(' . implode($glue, $ors) . ')';
                } else {
                    return '';
                }
            default:
                $escapedValue = $preformattedValue ?? $this->buildUrlFilterValue($qpart);
                $operatior = $this->buildUrlFilterComparator($qpart);
                return "{$property} {$operatior} {$escapedValue}";
        }
    }
    
    /**
     * Returns the oData filter operator to use for the given filter query part.
     * 
     * @link http://www.odata.org/documentation/odata-version-2-0/uri-conventions/
     * 
     * @param QueryPartFilter $qpart
     * @throws QueryBuilderException
     * 
     * @return string
     */
    protected function buildUrlFilterComparator(QueryPartFilter $qpart)
    {
        switch ($qpart->getComparator()) {
            case EXF_COMPARATOR_EQUALS:
                $comp = 'eq';
                break;
            case EXF_COMPARATOR_EQUALS_NOT:
                $comp = 'ne';
                break;
            case EXF_COMPARATOR_GREATER_THAN: $comp = 'gt'; break;
            case EXF_COMPARATOR_GREATER_THAN_OR_EQUALS: $comp = 'ge'; break;
            case EXF_COMPARATOR_LESS_THAN: $comp = 'lt'; break;
            case EXF_COMPARATOR_LESS_THAN_OR_EQUALS: $comp = 'le'; break;
            default:
                throw new QueryBuilderException('Comparator "' . $qpart->getComparator() . '" not supported in oData URL filters');
        }
        return $comp;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlFilterValue()
     */
    protected function buildUrlFilterValue(QueryPartFilter $qpart, string $preformattedValue = null)
    {
        $value = $preformattedValue ?? $qpart->getCompareValue();
        
        if (is_array($value)) {
            $value = implode($qpart->getAttribute()->getValueListDelimiter(), $value);
        }
        
        if ($preformattedValue === null) {
            try {
                $value = $qpart->getDataType()->parse($value);
            } catch (\Throwable $e) {
                throw new QueryBuilderException('Cannot create OData filter for "' . $qpart->getCondition()->toString() . '" - invalid data type!', null, $e);
            }
        }
        
        switch (true) {
            // Wrap string data types in single quotes
            case ($qpart->getDataType() instanceof StringDataType): 
                $value = $this->buildUrlFilterValueEscapedString($qpart, $value); 
                break; 
        }
        
        return $this->buildODataValue($qpart, $value);
    }
    
    /**
     * Escapes a string value to be safe to use within a filter predicate.
     * 
     * @param QueryPartFilter $qpart
     * @param string $value
     * @return string
     */
    protected function buildUrlFilterValueEscapedString(QueryPartFilter $qpart, string $value) : string
    {
        return "'" . rawurlencode($value) . "'";
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlSorters()
     */
    protected function buildUrlSorters()
    {
        $url = '';
        $sort = [];
        
        foreach ($this->getSorters() as $qpart) {
            if ($sortParam = $this->buildUrlParamSorter($qpart)) {
                $sort[] = $sortParam . ' ' . $qpart->getOrder();
            }
        }
        
        if (! empty($sort)) {
            $url = '$orderby=' . implode(',', $sort);
        }
        
        return $url;
    }
    
    /**
     * 
     */
    protected function findRowData($parsed_data, $path)
    {
        if ($path) {
            $data = $this->findFieldInData($path, $parsed_data);
        }
        
        if ($data === null) {
            return [];
        }
        
        // OData v2 uses a strange return format: {d: {...}} for single values and {d: {results: [...]}} for collections.
        if (StringDataType::startsWith($this->getODataVersion(), '2')) {
            if ($data['results'] !== null) {
                return $data['results'];
            }
            
            return [$data];
        }
        
        return $data;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildDataAddressForObject()
     */
    protected function buildDataAddressForObject(MetaObjectInterface $object, $method = 'GET')
    {
        switch (strtoupper($method)) {
            case 'PUT':
            case 'PATCH':
            case 'MERGE':
            case 'DELETE':
                if (! $object->getDataAddressProperty('update_request_data_address')) {
                    if ($object->hasUidAttribute() === false) {
                        throw new QueryBuilderException('Cannot update object "' . $object->getName() . '" (' . $object->getAliasWithNamespace() . ') via OData: there is no UID attribute defined for this object!');
                    }
                    
                    $url = $object->getDataAddress();
                    $UidAttribute = $object->getUidAttribute();
                    if ($UidAttribute instanceof CompoundAttributeInterface) {
                        $url .="(";
                        foreach ($UidAttribute->getComponents() as $comp) {
                            $url .= "{$comp->getAttribute()->getAlias()}=[#{$comp->getAttribute()->getAlias()}#],";
                        }
                        $url = rtrim($url, ',');
                        $url .= ")";
                    } else {
                        $url .= "([#" . $object->getUidAttribute()->getAlias() . "#])";
                    }
                    return $url;
                }
        }
        return parent::buildDataAddressForObject($object, $method);
    }
    
    /**
     * Takes care of special OData type formatting like "date'2019-01-31'" or "guid'xxx'"
     * 
     * @param QueryPartAttribute $qpart
     * @param mixed $preformattedValue
     * @return string
     */
    protected function buildODataValue(QueryPartAttribute $qpart, $preformattedValue = null)
    {
        switch ($qpart->getAttribute()->getDataAddressProperty('odata_type')) {
            case 'Edm.Guid':
                $value = 'guid' . $preformattedValue;
                break;
            case 'Edm.DateTimeOffset':
            case 'Edm.DateTime':
                $date = new \DateTime(str_replace("'", '', $preformattedValue));
                $value = "datetime'" . $date->format('Y-m-d\TH:i:s') . "'";
                break;
            case 'Edm.Binary':
                $value = 'binary' . $preformattedValue;
                break;
            case 'Edm.Time':
                $date = new \DateTime(str_replace("'", '', $preformattedValue));
                $value = 'PT' . $date->format('H\Ti\M');
                break;
            default:
                $value = $preformattedValue;
        }
        return $value;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\JsonUrlBuilder::buildRequestBodyValue()
     */
    protected function buildRequestBodyValue(QueryPartValue $qpart, $value) : string
    {
        switch ($qpart->getAttribute()->getDataAddressProperty('odata_type')) {
            case 'Edm.Guid':
                $value = "'" . $value . "'";
                break;
            case 'Edm.DateTimeOffset':
            case 'Edm.DateTime':
                $date = new \DateTime(str_replace("'", '', $value));
                $value = "{$date->format('Y-m-d\TH:i:s')}";
                return $value;
            case 'Edm.Time':
                $date = new \DateTime(str_replace("'", '', $value));
                $value = 'PT' . $date->format('H\Hi\Ms\S');
                return $value;
        }        
        return $this->buildODataValue($qpart, $value);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::getHttpMethod()
     */
    protected function getHttpMethod(string $operation) : string
    {
        $o = $this->getMainObject();
        switch ($operation) {
            case static::OPERATION_CREATE: return $o->getDataAddressProperty('create_request_method') ? $o->getDataAddressProperty('create_request_method') : 'POST';
            case static::OPERATION_UPDATE: return $o->getDataAddressProperty('update_request_method') ? $o->getDataAddressProperty('update_request_method') : 'PATCH';
        }
        
        return parent::getHttpMethod($operation);
    }
}