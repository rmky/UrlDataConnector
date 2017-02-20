<?php namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\CommonLogic\QueryBuilder\QueryPartSorter;
use exface\Core\CommonLogic\AbstractDataConnector;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use exface\Core\Exceptions\QueryBuilderException;

/**
 * This is an abstract query builder for REST APIs. It creates a sequence of URL parameters for a query. Parsing the results is done by
 * specific implementation (e.g. JSON vs. XML)
 * 
 * The following custom data address properties are supported on attribute level:
 * - filter_query_url - used to set a custom URL to be used if there is a filter over this attribute
 * - filter_query_parameter - used for filtering instead of the attributes data address: e.g. &[filter_query_parameter]=VALUE instead of &[data_address]=VALUE
 * - filter_query_prefix - prefix for the value in a filter query: e.g. &[data_address]=[filter_query_prefix]VALUE. Can be used to pass default operators etc.
 * - filter_locally - set to 1 to filter in ExFace after reading the data (if the data source does not support filtering over this attribute).
 * - sort_query_parameter - used for sorting instead of the attributes data address: e.g. &[sort_query_parameter]=VALUE instead of &[data_address]=VALUE
 * - sort_locally - set to 1 to sort in ExFace after reading the data (if the data source does not support filtering over this attribute).
 * 
 * The following custom data address properties are supported on object level:
 * - force_filtering - disables request withot at least a single filter (1). Some APIs disallow this!
 * - response_data_path - path to the array containing the items
 * - response_total_count_path - path to the total number of items matching the filter (used for pagination)
 * - response_group_by_attribute_alias - result rows will get resorted and grouped by values of the given attribute
 * - response_group_use_only_first - set to TRUE to return only the first group ignoring all rows with other values of the group attribute than the first row.
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
	private $endpoint_filter = null;
	private $request_split_filter = null;
	
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
				$this->set_request_split_filter($qpart);
			} 
			// Another way to set custom URLs is to give an attribute an explicit URL via filter_query_url address property.
			// This ultimately does the same thing, as uid_request_data_address on object level, but it's more general
			// because it can be set for every attribute.
			elseif($filter_endpoint = $qpart->get_data_address_property('filter_query_url')){
				if ($qpart->get_comparator() == EXF_COMPARATOR_IN){
					// FIXME this check prevents split filter collisions, but it can be greatly improved in two ways
					// - we should generally look for other custom URLs
					// - the final URL with all placeholders replaced should be compared
					if ($this->get_request_split_filter() && strcasecmp($this->get_request_split_filter()->get_data_address_property('filter_query_url'), $filter_endpoint)){
						throw new QueryBuilderException('Cannot use multiple filters requiring different custom URLs in one query: "' . $this->get_request_split_filter()->get_condition()->to_string() . '" AND "' . $qpart->get_condition()->to_string() . '"!');
					}
					
					$this->set_request_split_filter($qpart);
					$value = reset(explode(EXF_LIST_SEPARATOR, $qpart->get_compare_value()));
				} else {
					$value = $qpart->get_compare_value();
				}
				// The filter_query_url accepts the value placeholder along with attribute alias based placeholders. Since the value-placeholder
				// is not supported in the regular data_address or the uid_request_data_address (there simply is nothing to take the value from),
				// it must be replaced here already
				$endpoint = str_replace('[#value#]', $value, $filter_endpoint);
			} else {
				$params_string = $this->add_parameter_to_url($params_string, $this->build_url_filter($qpart));
			}
			
			// If the filter is to be applied in postprocessing, mark the respective query part and make sure, the attribute is always in the result
			// - otherwise there will be nothing to filter over ;) 
			if ($qpart->get_data_address_property('filter_locally')) {
				$qpart->set_apply_after_reading(true);
				if ($qpart->get_attribute()){
					$this->add_attribute($qpart->get_alias());
				}
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
		
		// Add attributes needed for address property logic
		if ($group_alias = $this->get_main_object()->get_data_address_property('response_group_by_attribute_alias')){
			$this->add_attribute($group_alias);
		}
		
		$endpoint = $this->replace_placeholders_in_url($endpoint);
		
		if ($endpoint !== false){
			$query_string = $endpoint . (strpos($endpoint, '?') !== false ? '&' : '?') . $params_string;
		}
		
		return new Psr7DataQuery(new Request('GET', $query_string));
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
	protected function replace_placeholders_in_url($url_string){
		foreach ($this->get_workbench()->utils()->find_placeholders_in_string($url_string) as $ph){
			if ($ph_filter = $this->get_filter($ph)){
				if (!is_null($ph_filter->get_compare_value())){
					if ($this->get_request_split_filter() == $ph_filter && $ph_filter->get_comparator() == EXF_COMPARATOR_IN){
						$ph_value = explode(EXF_LIST_SEPARATOR, $ph_filter->get_compare_value())[0];
					} else {
						$ph_value = $ph_filter->get_compare_value();
					}
					$url_string = str_replace('[#'.$ph.'#]', $ph_value, $url_string);
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
		if ($qpart->get_data_address_property('filter_locally')) {
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
		if ($qpart->get_data_address_property('sort_locally')){
			$qpart->set_apply_after_reading(true);
			$this->add_attribute($qpart->get_alias());
		}
		return ($qpart->get_data_address_property('sort_query_parameter') ? $qpart->get_data_address_property('sort_query_parameter') : $qpart->get_data_address());
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
			// Find data rows within the response and do the postprocessing
			$result_rows = $this->build_result_rows($data, $query);
			$result_rows = $this->apply_postprocessing($result_rows);
				
			// See if the query has an IN-filter, that is set to split requests. This is quite common for URLs like mydomain.com/get_something/id=XXX.
			// If we filter over ids and have multiple values, we will need to make as many identical requests as there are values and merge
			// the results together here. So the easiest thing to do is perform this query multiple times, changing the split filter value each time.
			if ($this->get_request_split_filter()
			&& $this->get_request_split_filter()->get_comparator() == EXF_COMPARATOR_IN){
				$split_values = explode(EXF_LIST_SEPARATOR, $this->get_request_split_filter()->get_compare_value());
				// skip the first UID as it has been fetched already
				$skip_val = true;
				foreach ($split_values as $val){
					if ($skip_val) {
						$skip_val = false;
						continue;
					}
					$this->get_request_split_filter()->set_compare_value($val);
					$subquery = $data_connection->query($this->build_query());
					if ($data = $this->parse_response($subquery)){
						$this->set_result_total_rows($this->get_result_total_rows() + $this->find_row_counter($data));
						$result_rows = array_merge($result_rows, $this->apply_postprocessing($this->build_result_rows($data, $subquery)));
					}
				}
				// Make sure, we give back the split filter it's initial value, in case any further code will be interested in filters.
				// This is particulary important if we need to apply additional filterin in-memory!
				$this->get_request_split_filter()->set_compare_value(implode(EXF_LIST_SEPARATOR,$split_values));
			}
			
			// Apply live filters, sorters and pagination
			$result_rows = $this->apply_filters($result_rows);
			$result_rows = $this->apply_sorting($result_rows);
			$result_rows = $this->apply_pagination($result_rows);
		}
	
		if (!$this->get_result_total_rows()){
			$this->set_result_total_rows(count($result_rows));
		}
		$this->set_result_rows(array_values($result_rows));
		return $this->get_result_total_rows();
	}
	
	protected function parse_response(Psr7DataQuery $query){
		return $query->get_response()->getBody()->getContents();
	}
	
	/**
	 * 
	 * @return \exface\Core\CommonLogic\QueryBuilder\QueryPartFilter
	 */
	protected function get_request_split_filter() {
		return $this->request_split_filter;
	}
	
	/**
	 * Marks the query as a UID-based request. The UID-filter is passed by reference, so it can be fetched and modified directly while
	 * processing the query. This is important for data sources, where UID-requests must be split or handled differently in any other way.
	 * 
	 * @param QueryPartFilter $value
	 * @return \exface\DataSources\QueryBuilders\REST_AbstractRest
	 */
	protected function set_request_split_filter(QueryPartFilter $value) {
		$this->request_split_filter = $value;
		return $this;
	}
	
	protected function apply_postprocessing($result_rows){
		if ($group_attribute_alias = $this->get_main_object()->get_data_address_property('response_group_by_attribute_alias')){
			if ($this->get_main_object()->get_data_address_property('response_group_use_only_first')){
				$qpart = $this->get_attribute($group_attribute_alias);
				$group_value = null;
				foreach ($result_rows as $row_nr => $row){
					if (!$group_value){
						$group_value = $row[$qpart->get_alias()];
						continue;
					}
					
					if ($row[$qpart->get_alias()] != $group_value){
						unset($result_rows[$row_nr]);
					}
				}
			}
		}
		return $result_rows;
	}
	  
}
?>