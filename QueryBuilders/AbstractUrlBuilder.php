<?php namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\CommonLogic\QueryBuilder\QueryPartSorter;
use exface\Core\CommonLogic\AbstractDataConnector;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * This is an abstract query builder for REST APIs. It creates a sequence of URL parameters for a query. Parsing the results is done by
 * specific implementation (e.g. JSON vs. XML)
 * 
 * The following custom data address properties are supported on attribute level:
 * - filter_query_parameter - used for filtering instead of the attributes data address: e.g. &[filter_query_parameter]=VALUE instead of &[data_address]=VALUE
 * - filter_query_prefix - prefix for the value in a filter query: e.g. &[data_address]=[filter_query_prefix]VALUE. Can be used to pass default operators etc.
 * - filter_localy - set to 1 to filter in ExFace after reading the data (if the data source does not support filtering over this attribute.
 * 
 * The following custom data address properties are supported on object level:
 * - force_filtering - disables request withot at least a single filter (1). Some APIs disallow this!
 * - response_data_path - path to the array containing the items
 * - response_total_count_path - path to the total number of items matching the filter (used for pagination)
 * - request_offset_parameter - name of the URL parameter containing the page offset for pagination
 * - request_limit_parameter - name of the URL parameter holding the maximum number of returned items
 * 
 * @see REST_XML for XML-based APIs
 * @author Andrej Kabachnik
 *
 */
abstract class AbstractUrlBuilder extends AbstractQueryBuilder {
	private $result_rows=array();
	private $result_totals=array();
	private $result_total_rows=0;
	private $request_uid_filter = null;
	
	protected function build_query(){
		$endpoint = $this->get_main_object()->get_data_address();
		$params_string = '';
		
		// Add filters
		foreach ($this->get_filters()->get_filters() as $qpart){
			// In REST APIs it is common to have a special URL to fetch data by UID of the object:
			// e.g. /users/1.xml would be the URL to fetch data for the user with UID = 1. Since in ExFace
			// the UID filter can also be used in regular searches, we can tell ExFace to use a special
			// data address for UID-based queries. Other filters will get applied to, but most APIs will
			// probably ignore them. If the API can actually handle a regular UID-filter, the special
			// data address should be simply left empty - this gives much more flexibility!
			if ($this->get_main_object()->get_uid_alias() == $qpart->get_alias() 
			&& $this->get_main_object()->get_data_address_property('uid_request_data_address')){
				$endpoint = $this->get_main_object()->get_data_address_property('uid_request_data_address');
				$this->set_request_uid_filter($qpart);
			} else {
				$params_string = $this->add_parameter_to_url($params_string, $this->build_url_filter($qpart));
			}
		}
		
		// Add the offset
		if ($this->get_offset() && $this->get_main_object()->get_data_address_property('request_offset_parameter')){
			$params_string = $this->add_parameter_to_url($params_string, $this->get_main_object()->get_data_address_property('request_offset_parameter'), $this->get_offset());
		}
		
		// Add the limit
		if ($this->get_limit() && $this->get_main_object()->get_data_address_property('request_limit_parameter')){
			$params_string = $this->add_parameter_to_url($params_string, $this->get_main_object()->get_data_address_property('request_limit_parameter'), $this->get_limit());
		}
		
		// Add sorting
		$sorters = array();
		foreach ($this->get_sorters() as $qpart){
			$sorters[] = $this->build_url_sorter($qpart);
		}
		if (count($sorters) > 0){
			$params_string = $this->add_parameter_to_url($params_string, 'sort', implode(',', $sorters));
		}
		
		// Check if the data source contains placeholders to be filled from filter
		foreach ($this->get_workbench()->utils()->find_placeholders_in_string($endpoint) as $ph){
			if ($ph_filter = $this->get_filter($ph)){
				if (!is_null($ph_filter->get_compare_value())){
					if ($this->get_request_uid_filter() == $ph_filter && $ph_filter->get_comparator() == EXF_COMPARATOR_IN){
						$ph_value = explode(',', $ph_filter->get_compare_value())[0];
					} else {
						$ph_value = $ph_filter->get_compare_value();
					}
					$endpoint = str_replace('[#'.$ph.'#]', $ph_value, $endpoint);
				} else {
					// If at least one filter does not have a value, return an empty query string, thus
					// preventing query execution
					$query_string = '';
				}
			} else {
				// If at least one placeholder does not have a corresponding filter, return an empty query string, thus
				// preventing query execution
				$query_string = '';
			}
		}
		if (is_null($query_string)){
			$query_string = $endpoint . (strpos($endpoint, '?') !== false ? '&' : '?') . $params_string;
		}
		
		return new Psr7DataQuery($this, new Request('GET', $query_string));
	}
	
	protected function add_parameter_to_url($url, $parameter, $value = null){
		if (!$parameter) return $url;
		return $url . ($url ? '&' : '') . $parameter . (!is_null($value) ? '=' . $value : '');
	}
	
	function get_result_rows(){
		return $this->result_rows;
	}
	
	function get_result_totals(){
		return $this->result_totals;
	}
	
	function get_result_total_rows(){
		return $this->result_total_rows;
	}
	
	function set_result_rows(array $array){
		$this->result_rows = $array;
		return $this;
	}
	
	function set_result_totals(array $array){
		$this->result_totals = $array;
		return $this;
	}
	
	function set_result_total_rows($value){
		$this->result_total_rows = $value;
		return $this;
	}
	
	/**
	 * Builds a URL filter from a filter query part: e.g. subject=word1+word2+word3
	 * @param QueryPartFilter $qpart
	 * @return string
	 */
	protected function build_url_filter(QueryPartFilter $qpart){
		if ($qpart->get_data_address_property('filter_localy')) {
			$qpart->set_apply_after_reading(true);
			return '';
		}
		
		$filter = '';
		// Determine filter name (URL parameter name)
		if ($param = $qpart->get_data_address_property('filter_query_parameter')){
			// Use the filter_query_parameter if explicitly defined
			$filter = $param;
		} elseif (stripos($qpart->get_data_address(), '->') === 0) {
			// Use the data_address if it is not a property itself (starts with ->)
			$filter = $qpart->get_data_address();
		}
		
		if ($filter){
			$filter .= '=';
			
			// Add a prefix to the value if needed
			if ($prefix = $qpart->get_data_address_property('filter_query_prefix')){
				$filter .= $prefix;
			}
			
			// Add the value
			if (is_array($qpart->get_compare_value())){
				$filter .= implode('+', $qpart->get_compare_value());
			} else {
				$filter .= $qpart->get_compare_value();
			}
		}
		
		return $filter;
	}
	
	protected function build_url_sorter(QueryPartSorter $qpart){
		return ($qpart->get_data_address_property('sort_query_parameter') ? $qpart->get_data_address_property('sort_query_parameter') : $qpart->get_data_address());
	}
	
	/**
	 * Returns the requested UID if this request is based on a single UID and FALSE otherwise. UID-requests
	 * are often treated differently: the have other data addresses and other response structures than regular
	 * list-requests. The response of a UID-request will typically contain more information about the single
	 * item, that is requested.
	 * @return QueryPartFilter
	 */
	public function get_request_uid_filter() {
		return $this->request_uid_filter;
	}
	
	/**
	 * Marks the query as a UID-based request. The UID-filter is passed by reference, so it can be fetched and modified directly while
	 * processing the query. This is important for data sources, where UID-requests must be split or handled differently in any other way.
	 * @param QueryPartFilter $value
	 * @return \exface\DataSources\QueryBuilders\REST_AbstractRest
	 */
	public function set_request_uid_filter(QueryPartFilter &$value) {
		$this->request_uid_filter = $value;
		return $this;
	}
	
	/**
	 * Returns TRUE if the given string is a valid data address and FALSE otherwise. 
	 * @param string $data_address_string
	 * @return boolean
	 */
	protected function check_valid_data_address($data_address_string){
		if (mb_stripos($data_address_string, '=') === 0) return false; // Formula
		return true;
	}
	
	/**
	 * Extracts the actual data from the parsed response. If not the entire response is usefull data, the useless parts can be ignored by
	 * setting the data source property 'response_data_path'. If this property is not set, the entire response is treated as data.
	 * @param mixed $response
	 * @return mixed
	 */
	protected function find_row_data($parsed_response){
		return $parsed_response;
	}
	
	protected function find_row_counter($parsed_data){
		return $this->find_field_in_data($this->get_main_object()->get_data_address_property('response_total_count_path'), $parsed_data);
	}
	
	protected abstract function find_field_in_data($data_address, $data);
	
	/**
	 * Parse the response data into an array of the following form: [ 1 => ["field1" => "value1", "field2" => "value 2"], 2 => [...], ... ]
	 * @param mixed $data
	 * @return array
	 */
	protected abstract function build_result_rows($parsed_data, Psr7DataQuery $query);
	
	/**
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::read()
	 */
	public function read(AbstractDataConnector $data_connection = null){
		$result_rows = array();
		// Check if force filtering is enabled
		if ($this->get_main_object()->get_data_address_property('force_filtering') && count($this->get_filters()->get_filters_and_nested_groups()) < 1){
			return false;
		}
		
		$query = $data_connection->query($this->build_query());
		if ($data = $this->parse_response($query)){
			// Find the total row counter within the response
			$this->set_result_total_rows($this->find_row_counter($data));
			// Find data rows within the response
			$result_rows = $this->build_result_rows($data, $query);
				
			// If this is a UID-request with multiple UIDs and there is a special data address for these requests, than the result will always
			// be a single object, not a list. In this case, we have to read the data multiple times (for every UID in the list) and accumulate
			// the results. This is important for many web services, that have different endpoint-addresses for searching objects via their
			// attributes and via the ID (= the property "uid_request_data_address" needs to be set for those data sources). This will no
			// have any effekt on data sources, were "uid_request_data_address" is not set and thus the normal searching also supports UID filters.
			if ($this->get_request_uid_filter()
			&& $this->get_request_uid_filter()->get_comparator() == EXF_COMPARATOR_IN
			&& $this->get_main_object()->get_data_address_property('uid_request_data_address')){
				$uid_values = explode(',', $this->get_request_uid_filter()->get_compare_value());
				// skip the first UID as it has been fetched already
				$uid_skip = true;
				foreach ($uid_values as $val){
					if ($uid_skip) {
						$uid_skip = false;
						continue;
					}
					$this->get_request_uid_filter()->set_compare_value($val);
					$subquery = $data_connection->query($this->build_query());
					if ($data = $this->parse_response($subquery)){
						$this->set_result_total_rows($this->get_result_total_rows() + $this->find_row_counter($data));
						$result_rows = array_merge($result_rows, $this->build_result_rows($data, $subquery));
					}
				}
			}
			
			// Apply live filters, sorters and pagination
			$result_rows = $this->apply_filters($result_rows);
			$result_rows = $this->apply_sorting($result_rows);
			$result_rows = $this->apply_pagination($result_rows);
		}
	
		if (!$this->get_result_total_rows()){
			$this->set_result_total_rows(count($result_rows));
		}
		$this->set_result_rows($result_rows);
		return $this->get_result_total_rows();
	}
	
	protected function parse_response(Psr7DataQuery $query){
		return $query->get_response()->getBody()->getContents();
	}
}
?>