<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\CommonLogic\QueryBuilder\QueryPartSorter;
use exface\Core\CommonLogic\AbstractDataConnector;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use Psr\Http\Message\RequestInterface;
use exface\Core\CommonLogic\Model\Condition;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilterGroup;
use exface\Core\CommonLogic\Model\ConditionGroup;
use exface\Core\DataTypes\StringDataType;

/**
 * This is an abstract query builder for REST APIs.
 * It creates a sequence of URL parameters for a query. Parsing the results is done by
 * specific implementation (e.g. JSON vs. XML)
 * 
 * # Data source options
 * 
 * ## On object level
 * 
 * - **force_filtering** - disables request withot at least a single filter (1). 
 * Some APIs disallow this!
 * 
 * - **response_data_path** - path to the array containing the items
 * 
 * - **response_total_count_path** - path to the total number of items matching 
 * the filter (used for pagination)
 * 
 * - **response_group_by_attribute_alias** - result rows will get resorted and 
 * grouped by values of the given attribute
 * 
 * - **response_group_use_only_first** - set to TRUE to return only the first 
 * group ignoring all rows with other values of the group attribute than the 
 * first row.
 * 
 * - **request_remote_pagination** - set to false to disable remote pagination
 * 
 * - **request_offset_parameter** - name of the URL parameter containing the 
 * page offset for pagination
 * 
 * - **request_limit_parameter** - name of the URL parameter holding the 
 * maximum number of returned items
 * 
 * - **request_url_replace_pattern** - regular expression pattern for PHP 
 * preg_replace() function to be performed on the request URL
 * 
 * - **request_url_replace_with** - replacement string for PHP preg_replace() 
 * function to be performed on the request URL
 * 
 * - **uid_request_data_address** - makes requests with filters over the UID go 
 * to this URL instead of the one in the data address. The URL allows
 * attribute_alias as placeholders (incl. the UID itself - e.g. 
 * "me.com/service/[#UID#]"). Note, that if the URL does not have placeholders
 * it will be always the same - regardles of what the UID actually is.  
 * 
 * - **uid_response_data_path** - used to find the data in the response for a 
 * request with a filter on UID (instead of response_data_path)
 * 
 * - **create_request_data_address** - used in create requests instead of the 
 * data address
 * 
 * - **create_request_data_path** - path to the object/array holding the 
 * attributes of the instance to be created
 * 
 * - **update_request_data_address** - used in update requests instead of the 
 * data address
 * 
 * - **update_request_data_path** - this is where the data is put in the body 
 * of update requests (if not specified the attributes are just put in the root 
 * object)
 * 
 * ## On attribute level
 * 
 * - **filter_remote** - set to 1 to enable remote filtering (0 by default)
 * 
 * - **filter_remote_url** - used to set a custom URL to be used if there is a 
 * filter over this attribute. The URL accepts the placeholder [#~value#] which
 * will be replaced by the. Note, that if the URL does not have the placeholder,
 * it will be always the same - regardles of what the filter is actually set to. 
 * 
 * - **filter_remote_url_param** - used for filtering instead of the attributes 
 * data address: e.g. &[filter_remote_url_param]=VALUE instead of 
 * &[data_address]=VALUE
 * 
 * - **filter_remote_prefix** - prefix for the value in a filter query: e.g. 
 * &[data_address]=[filter_remote_prefix]VALUE. Can be used to pass default 
 * operators etc.
 * 
 * - **filter_locally** - set to 1 to filter in ExFace after reading the data
 * (e.g. if the data source does not support filtering over this attribute) or
 * set to 0 to take the data as it is. If not set, the data will be filtered
 * locally automatically if no remote filtering is configured.
 * 
 * - **sort_remote** - set to 1 to enable remote sorting (0 by default)
 * 
 * - **sort_remote_url_param** - used for sorting instead of the attributes 
 * data address: e.g. &[sort_remote_url_param]=VALUE instead of 
 * &[data_address]=VALUE
 * 
 * - **sort_locally** - set to 1 to sort in ExFace after reading the data (if 
 * the data source does not support filtering over this attribute).
 * 
 * - **create_data_address** - used in the body of create queries (typically 
 * POST-queries) instead of the data address
 * 
 * - **update_data_address** - used in the body of update queries (typically 
 * PUT/PATCH-queries) instead of the data address
 *
 * @author Andrej Kabachnik
 *        
 */
abstract class AbstractUrlBuilder extends AbstractQueryBuilder
{

    private $result_rows = array();

    private $result_totals = array();

    private $result_total_rows = 0;

    private $endpoint_filter = null;

    private $request_split_filter = null;

    /**
     * Returns a PSR7 GET-Request for this query.
     * 
     * @throws QueryBuilderException
     * @return RequestInterface
     */
    protected function buildRequestGet()
    {
        $endpoint = $this->getMainObject()->getDataAddress();
        $params_string = '';
        $urlFilters = [];
        
        // Add filters
        foreach ($this->getFilters()->getFilters() as $qpart) {
            // In REST APIs it is common to have a special URL to fetch data by UID of the object:
            // e.g. /users/1.xml would be the URL to fetch data for the user with UID = 1. Since in ExFace
            // the UID filter can also be used in regular searches, we can tell ExFace to use a special
            // data address for UID-based queries. Other filters will get applied to, but most APIs will
            // probably ignore them. If the API can actually handle a regular UID-filter, the special
            // data address should be simply left empty - this gives much more flexibility!
            if ($this->getMainObject()->getUidAttributeAlias() == $qpart->getAlias() && $this->getMainObject()->getDataAddressProperty('uid_request_data_address')) {
                $endpoint = $this->getMainObject()->getDataAddressProperty('uid_request_data_address');
                $this->setRequestSplitFilter($qpart);
            } // Another way to set custom URLs is to give an attribute an explicit URL via filter_remote_url address property.
              // This ultimately does the same thing, as uid_request_data_address on object level, but it's more general
              // because it can be set for every attribute.
            elseif ($filter_endpoint = $qpart->getDataAddressProperty('filter_remote_url')) {
                if ($qpart->getComparator() == EXF_COMPARATOR_IN) {
                    // FIXME this check prevents split filter collisions, but it can be greatly improved in two ways
                    // - we should generally look for other custom URLs
                    // - the final URL with all placeholders replaced should be compared
                    if ($this->getRequestSplitFilter() && strcasecmp($this->getRequestSplitFilter()->getDataAddressProperty('filter_remote_url'), $filter_endpoint)) {
                        throw new QueryBuilderException('Cannot use multiple filters requiring different custom URLs in one query: "' . $this->getRequestSplitFilter()->getCondition()->toString() . '" AND "' . $qpart->getCondition()->toString() . '"!');
                    }
                    
                    $this->setRequestSplitFilter($qpart);
                    $value = reset(explode($qpart->getValueListDelimiter(), $qpart->getCompareValue()));
                } else {
                    $value = $qpart->getCompareValue();
                }
                // The filter_remote_url accepts the value placeholder along with attribute alias based placeholders. Since the value-placeholder
                // is not supported in the regular data_address or the uid_request_data_address (there simply is nothing to take the value from),
                // it must be replaced here already
                $endpoint = str_replace('[#~value#]', $value, $filter_endpoint);
            } else {
                // Remember filter query parts, that do not affect the endpoint to process them later.
                // This is important to process them in a single run, so complex filter expressions can be
                // built instead of an individual URL parameter for every filter.
                $urlFilters[] = $qpart;
            }
        }
        
        // Add the remaining filters to the URL
        if (! empty($urlFilters)) {
            $params_string = $this->addParameterToUrl($params_string, $this->buildUrlFilters($urlFilters));
        }
        
        // Add pagination
        if ($this->getLimit() || $this->getOffset()) {
            $params_string = $this->addParameterToUrl($params_string, $this->buildUrlPagination());
        }
        
        // Add sorters
        if ($sorters = $this->buildUrlSorters()) {
            $params_string = $this->addParameterToUrl($params_string, $sorters);
        }
        
        // Add attributes needed for address property logic
        if ($group_alias = $this->getMainObject()->getDataAddressProperty('response_group_by_attribute_alias')) {
            $this->addAttribute($group_alias);
        }
        
        // Replace placeholders in endpoint
        $endpoint = $this->replacePlaceholdersInUrl($endpoint);
        
        if ($endpoint !== false) {
            // Run custom regexp transformations
            if ($replace_pattern = $this->getMainObject()->getDataAddressProperty('request_url_replace_pattern')) {
                $replace_with = $this->getMainObject()->getDataAddressProperty('request_url_replace_with');
                $endpoint = preg_replace($replace_pattern, $replace_with, $endpoint);
            }
            
            // Build the resulting query string
            $query_string = $endpoint;
            if ($params_string) {
                $query_string .= (strpos($query_string, '?') !== false ? '&' : '?') . $params_string;
            }
        }
        
        return new Request('GET', $query_string);
    }
    
    /**
     * Returns the part of the URL query responsible for sorting (without a leading "&"!)
     * 
     * @return string
     */
    protected function buildUrlSorters()
    {
        $url = '';
        $sorters = array();
        
        foreach ($this->getSorters() as $qpart) {
            $sorters[] = $this->buildUrlParamSorter($qpart);
        }
        if (! empty($sorters)) {
            $url = $this->addParameterToUrl($url, 'sort', implode(',', $sorters));
        }
        
        return $url;
    }
    
    /**
     * Returns a URL query string with parameters for the given filters  (without a leading "&"!).
     * 
     * By default, this method will treat each condition as a separate URL parameter: e.g.
     * &filter1=value1, etc.. Override this method to switch to a single URL param containing
     * a complex filter expression like the oData "$filter=cond1 eq val1 and cond2 eq val2".
     * 
     * @param QueryPartFilter[] $filters
     * @return string
     */
    protected function buildUrlFilters(array $filters)
    {
        $query = '';
        foreach ($filters as $qpart) {
            $query .= $this->addParameterToUrl($query, $this->buildUrlFilter($qpart));
        }
        return $query;
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
        // If there are options for remote filtering set and the filter_remote address property is not explicitly off, enable it
        if ($qpart->getDataAddressProperty('filter_remote_url') || $qpart->getDataAddressProperty('filter_remote_url_param') || $qpart->getDataAddressProperty('filter_remote_prefix')) {
            if ($qpart->getDataAddressProperty('filter_remote') === '' || is_null($qpart->getDataAddressProperty('filter_remote'))) {
                $qpart->setDataAddressProperty('filter_remote', 1);
            }
        }
        
        // Enable local filtering if remote filters are not enabled and local filtering is not explicitly off
        if (! $qpart->getDataAddressProperty('filter_remote') && (is_null($qpart->getDataAddressProperty('filter_locally')) || $qpart->getDataAddressProperty('filter_locally') === '')) {
            $qpart->setDataAddressProperty('filter_locally', 1);
        }
        
        // If a local filter is to be applied in postprocessing, mark the respective query part and make sure, the attribute is always
        // in the result - otherwise there will be nothing to filter over ;)
        if ($qpart->getDataAddressProperty('filter_locally')) {
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
        if ($qpart->getDataAddressProperty('sort_remote_url_param')) {
            if ($qpart->getDataAddressProperty('sort_remote') === '' || is_null($qpart->getDataAddressProperty('sort_remote'))) {
                $qpart->setDataAddressProperty('sort_remote', 1);
            }
        }
        
        // Enable local sorting if remote sort is not enabled and local sorting is not explicitly off
        if (! $qpart->getDataAddressProperty('sort_remote') && (is_null($qpart->getDataAddressProperty('sort_locally')) || $qpart->getDataAddressProperty('sort_locally') === '')) {
            $qpart->setDataAddressProperty('sort_locally', 1);
        }
        
        // If a local sorter is to be applied in postprocessing, mark the respective query part and make sure, the attribute is always
        // in the result - otherwise there will be nothing to sort over ;)
        if ($qpart->getDataAddressProperty('sort_locally')) {
            $qpart->setApplyAfterReading(true);
            if ($qpart->getAttribute()) {
                $this->addAttribute($qpart->getAlias());
            }
        }
        
        return $qpart;
    }

    /**
     * Looks for placeholders in the give URL and replaces them with values from the corresponding filters.
     * Returns the given string with all placeholders replaced or FALSE if some placeholders could not be replaced.
     *
     * IDEA maybe throw an exception instead of returning false. If that exception is not caught, it might give
     * valuable information about what exactly went wrong.
     *
     * @param string $url_string            
     * @return string|boolean
     */
    protected function replacePlaceholdersInUrl($url_string)
    {
        foreach (StringDataType::findPlaceholders($url_string) as $ph) {
            if ($ph_filter = $this->getFilter($ph)) {
                if (! is_null($ph_filter->getCompareValue())) {
                    if ($this->getRequestSplitFilter() == $ph_filter && $ph_filter->getComparator() == EXF_COMPARATOR_IN) {
                        $ph_value = explode($ph_filter->getValueListDelimiter(), $ph_filter->getCompareValue())[0];
                    } else {
                        $ph_value = $ph_filter->getCompareValue();
                    }
                    $url_string = str_replace('[#' . $ph . '#]', $ph_value, $url_string);
                } else {
                    // If at least one filter does not have a value, return false
                    return false;
                }
            } else {
                // If at least one placeholder does not have a corresponding filter, return false
                return false;
            }
        }
        return $url_string;
    }

    protected function addParameterToUrl($url, $parameter, $value = null)
    {
        if (! $parameter)
            return $url;
        return $url . ($url ? '&' : '') . $parameter . (! is_null($value) ? '=' . $value : '');
    }

    public function getResultRows()
    {
        return $this->result_rows;
    }

    public function getResultTotals()
    {
        return $this->result_totals;
    }

    public function getResultTotalRows()
    {
        return $this->result_total_rows;
    }

    public function setResultRows(array $array)
    {
        $this->result_rows = $array;
        return $this;
    }

    public function setResultTotals(array $array)
    {
        $this->result_totals = $array;
        return $this;
    }

    public function setResultTotalRows($value)
    {
        $this->result_total_rows = $value;
        return $this;
    }

    /**
     * Builds a URL filter from a filter query part: e.g.
     * subject=word1+word2+word3
     *
     * @param QueryPartFilter $qpart            
     * @return string
     */
    protected function buildUrlFilter(QueryPartFilter $qpart)
    {
        $filter = '';
        $param = $this->buildUrlParamFilter($qpart);
        
        if ($param) {
            $filter = $param . '=';
            
            // Add a prefix to the value if needed
            if ($prefix = $qpart->getDataAddressProperty('filter_remote_prefix')) {
                $filter .= $prefix;
            }
            
            // Add the value
            if (is_array($qpart->getCompareValue())) {
                $filter .= implode('+', $qpart->getCompareValue());
            } else {
                $filter .= $qpart->getCompareValue();
            }
        }
        
        return $filter;
    }
    
    /**
     * Returns the URL parameter for the given filter query part
     * 
     * @param QueryPartFilter $qpart
     * @return string
     */
    protected function buildUrlParamFilter(QueryPartFilter $qpart)
    {
        if (! $this->isRemoteFilter($qpart)) {
            return '';
        }
        
        $filter = '';
        // Determine filter name (URL parameter name)
        if ($param = $qpart->getDataAddressProperty('filter_remote_url_param')) {
            // Use the filter_remote_url_param if explicitly defined
            $filter = $param;
        } elseif (stripos($qpart->getDataAddress(), '->') !== 0) {
            // Use the data_address if it is not a property itself (starts with ->)
            $filter = $qpart->getDataAddress();
        }
        return $filter;
    }
    
    /**
     * Returns TRUE if the given filter query part uses remote filtering and FALSE otherwise.
     * 
     * @param QueryPartFilter $qpart
     * @return boolean
     */
    protected function isRemoteFilter(QueryPartFilter $qpart)
    {
        return $qpart->getDataAddressProperty('filter_remote') || $qpart->getDataAddressProperty('filter_remote_url_param');
    }

    /**
     * Returns the sorting URL parameter for the given sorter query part.
     * 
     * @param QueryPartSorter $qpart
     * @return string
     */
    protected function buildUrlParamSorter(QueryPartSorter $qpart)
    {
        if (! $this->isRemoteSorter($qpart)) {
            return '';
        }
        return ($qpart->getDataAddressProperty('sort_remote_url_param') ? $qpart->getDataAddressProperty('sort_remote_url_param') : $qpart->getDataAddress());
    }
    
    /**
     * Returns TRUE if the given sorter query part uses remote sorting and FALSE otherwise.
     * 
     * @param QueryPartSorter $qpart
     * @return boolean
     */
    protected function isRemoteSorter(QueryPartSorter $qpart)
    {
        return $qpart->getDataAddressProperty('sort_remote') || $qpart->getDataAddressProperty('sort_remote_url_param');
    }

    /**
     * Returns TRUE if the given string is a valid data address and FALSE otherwise.
     *
     * @param string $data_address_string            
     * @return boolean
     */
    protected function checkValidDataAddress($data_address_string)
    {
        if (mb_stripos($data_address_string, '=') === 0)
            return false; // Formula
        return true;
    }

    /**
     * Extracts the actual data from the parsed response.
     * 
     * If the response contains metadata or any other overhead information, the actual
     * data rows must first be extracted from the response. This method will do to the
     * extraction based on the given response and data path.
     *
     * @param mixed $response            
     * @return mixed
     */
    protected function findRowData($parsed_response, $path)
    {
        // If the path is not empty, follow it. Otherwise just return the entire response.
        if ($path) {
            return $this->findFieldInData($path, $parsed_response);
        } else {
            return $parsed_response;
        }
    }
    
    /**
     * Builds the path from response root to the container with data rows.
     *
     * The path can be specified in each object using the data source options
     * uid_response_data_path and response_data_path. The former will be used
     * for signle-entity requests (typically a request with the UID as the only
     * filter), while the latter will be used for all other cases - that is,
     * whenever multiple entities are expected in the response.
     *
     * @return string
     */
    protected function buildPathToResponseRows(Psr7DataQuery $query)
    {
        switch ($query->getRequest()->getMethod()) {
            default:
                // TODO make work with any request_split_filter, not just the UID
                if ($this->getRequestSplitFilter() && $this->getRequestSplitFilter()->getAttribute()->isUidForObject() && ! is_null($this->getMainObject()->getDataAddressProperty('uid_response_data_path'))) {
                    $path = $this->getMainObject()->getDataAddressProperty('uid_response_data_path');
                } else {
                    $path = $this->getMainObject()->getDataAddressProperty('response_data_path');
                }
        }
        return $path;
    }

    protected abstract function findFieldInData($data_address, $data);

    /**
     * Parse the response data into an array of the following form: [ 1 => ["field1" => "value1", "field2" => "value 2"], 2 => [...], ...
     * ]
     *
     * @param mixed $data            
     * @return array
     */
    protected abstract function buildResultRows($parsed_data, Psr7DataQuery $query);

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::read()
     */
    public function read(AbstractDataConnector $data_connection = null)
    {
        $result_rows = array();
        // Check if force filtering is enabled
        if ($this->getMainObject()->getDataAddressProperty('force_filtering') && count($this->getFilters()->getFiltersAndNestedGroups()) < 1) {
            return false;
        }
        
        $query = $data_connection->query(new Psr7DataQuery($this->buildRequestGet()));
        if ($data = $this->parseResponse($query)) {
            // Find the total row counter within the response
            $this->setResultTotalRows($this->findRowCounter($data, $query));
            // Find data rows within the response and do the postprocessing
            $result_rows = $this->buildResultRows($data, $query);
            $result_rows = $this->applyPostprocessing($result_rows);
            
            // See if the query has an IN-filter, that is set to split requests. This is quite common for URLs like mydomain.com/get_something/id=XXX.
            // If we filter over ids and have multiple values, we will need to make as many identical requests as there are values and merge
            // the results together here. So the easiest thing to do is perform this query multiple times, changing the split filter value each time.
            if ($this->getRequestSplitFilter() && $this->getRequestSplitFilter()->getComparator() == EXF_COMPARATOR_IN) {
                $split_values = explode($this->getRequestSplitFilter()->getValueListDelimiter(), $this->getRequestSplitFilter()->getCompareValue());
                // skip the first UID as it has been fetched already
                $skip_val = true;
                foreach ($split_values as $val) {
                    if ($skip_val) {
                        $skip_val = false;
                        continue;
                    }
                    $this->getRequestSplitFilter()->setCompareValue($val);
                    $subquery = $data_connection->query($this->buildRequestGet());
                    if ($data = $this->parseResponse($subquery)) {
                        $this->setResultTotalRows($this->getResultTotalRows() + $this->findRowCounter($data, $query));
                        $subquery_rows = $this->buildResultRows($data, $subquery);
                        $result_rows = array_merge($result_rows, $this->applyPostprocessing($subquery_rows));
                    }
                }
                // Make sure, we give back the split filter it's initial value, in case any further code will be interested in filters.
                // This is particulary important if we need to apply additional filterin in-memory!
                $this->getRequestSplitFilter()->setCompareValue(implode($this->getRequestSplitFilter()->getValueListDelimiter(), $split_values));
            }
            
            // Apply live filters, sorters and pagination
            $cnt_before_filters = count($result_rows);
            $result_rows = $this->applyFilters($result_rows);
            $cnt_after_filters = count($result_rows);
            if ($cnt_before_filters !== $cnt_after_filters) {
                $this->setResultTotalRows($cnt_after_filters);
            }
            $result_rows = $this->applySorting($result_rows);
            
            if (! $this->isRemotePaginationConfigured()) {
                if (! $this->getResultTotalRows()) {
                    $this->setResultTotalRows(count($result_rows));
                }
                $result_rows = $this->applyPagination($result_rows);
            }
        }
        
        if (! $this->getResultTotalRows()) {
            $this->setResultTotalRows(count($result_rows));
        }
        $this->setResultRows(array_values($result_rows));
        return $this->getResultTotalRows();
    }

    protected function parseResponse(Psr7DataQuery $query)
    {
        return (string) $query->getResponse()->getBody();
    }

    /**
     *
     * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartFilter
     */
    protected function getRequestSplitFilter()
    {
        return $this->request_split_filter;
    }

    /**
     * Marks the query as a UID-based request.
     * The UID-filter is passed by reference, so it can be fetched and modified directly while
     * processing the query. This is important for data sources, where UID-requests must be split or handled differently in any other way.
     *
     * @param QueryPartFilter $value            
     * @return \exface\DataSources\QueryBuilders\REST_AbstractRest
     */
    protected function setRequestSplitFilter(QueryPartFilter $value)
    {
        $this->request_split_filter = $value;
        return $this;
    }

    protected function applyPostprocessing($result_rows)
    {
        if ($group_attribute_alias = $this->getMainObject()->getDataAddressProperty('response_group_by_attribute_alias')) {
            if ($this->getMainObject()->getDataAddressProperty('response_group_use_only_first')) {
                $qpart = $this->getAttribute($group_attribute_alias);
                $group_value = null;
                foreach ($result_rows as $row_nr => $row) {
                    if (! $group_value) {
                        $group_value = $row[$qpart->getAlias()];
                        continue;
                    }
                    
                    if ($row[$qpart->getAlias()] != $group_value) {
                        unset($result_rows[$row_nr]);
                    }
                }
            }
        }
        return $result_rows;
    }

    protected function isRemotePaginationConfigured()
    {
        if ($this->getMainObject()->getDataAddressProperty('request_remote_pagination') && $this->buildUrlParamOffset($this->getMainObject())) {
            return true;
        }
        return false;
    }
    
    /**
     * Returns the data address for the given attribute in the context of the specified http method.
     * 
     * Depending on the method, the custom data shource properties create_data_address, update_data_address,
     * etc. will be used instead of the regular data address of the attribute.
     * 
     * @param MetaAttributeInterface $attribute
     * @param string $method
     * @return string
     */
    protected function buildDataAddressForAttribute(MetaAttributeInterface $attribute, $method = 'GET')
    {
        $data_address = $attribute->getDataAddress();
        switch (strtoupper($method)) {
            case 'POST':
                return ($attribute->getDataAddressProperty('create_data_address') ? $attribute->getDataAddressProperty('create_data_address') : $data_address);
            case 'PUT':
            case 'PATCH':
                return ($attribute->getDataAddressProperty('update_data_address') ? $attribute->getDataAddressProperty('update_data_address') : $data_address);
        }
        return $data_address;
    }
    
    /**
     * Returns the data address for the given object in the context of the specified http method.
     *
     * Depending on the method, the custom data shource properties create_request_data_address, 
     * update_request_data_address, etc. will be used instead of the regular data address of the object.
     *
     * @param MetaObjectInterface $attribute
     * @param string $method
     * @return string
     */
    protected function buildDataAddressForObject(MetaObjectInterface $object, $method = 'GET')
    {
        $data_address = $object->getDataAddress();
        switch (strtoupper($method)) {
            case 'POST':
                return ($object->getDataAddressProperty('create_request_data_address') ? $object->getDataAddressProperty('create_request_data_address') : $data_address);
            case 'PUT':
            case 'PATCH':
                return ($object->getDataAddressProperty('update_request_data_address') ? $object->getDataAddressProperty('update_request_data_address') : $data_address);
        }
        return $data_address;
    }
    
    /**
     * Returns the total number of rows matching the request (without pagination).
     * 
     * @param mixed $data
     * @return number|null
     */
    protected function findRowCounter($data, Psr7DataQuery $query)
    {
        return $this->findFieldInData($this->buildPathToTotalRowCounter($this->getMainObject()), $data);
    }
    
    /**
     * Returns the path to the total row counter (e.g. the response_total_count_path of the object)
     * 
     * @param MetaObjectInterface $object
     * @return string
     */
    protected function buildPathToTotalRowCounter(MetaObjectInterface $object)
    {
        return $object->getDataAddressProperty('response_total_count_path');
    }
    
    protected function buildUrlPagination()
    {
        $params = '';
        if ($offsetParam = $this->buildUrlParamLimit($this->getMainObject())) {
            $params = $this->addParameterToUrl($params, $offsetParam, $this->getLimit());
        }
        if ($limitParam = $this->buildUrlParamOffset($this->getMainObject())) {
            $params = $this->addParameterToUrl($params, $limitParam, $this->getOffset());
        }
        return $params;
    }
    
    protected function buildUrlParamOffset(MetaObjectInterface $object)
    {
        return $object->getDataAddressProperty('request_offset_parameter');
    }
    
    protected function buildUrlParamLimit(MetaObjectInterface $object)
    {
        return $object->getDataAddressProperty('request_limit_parameter');
    }
}
?>