<?php namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\CommonLogic\DataSheets\DataColumn;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use exface\Core\Exceptions\Model\MetaAttributeNotFoundError;
/**
 * This is a query builder for JSON-based REST APIs. It creates a sequence of URL parameters for a query and parses the JSON result.
 * 
 * The following custom data address properties are supported on attribute level:
 * - filter_query_parameter - used for filtering instead of the attributes data address: e.g. &[filter_query_parameter]=VALUE instead of &[data_address]=VALUE
 * - sort_query_parameter - used for sorting instead of the data address
 * - create_query_parameter - used in the body of create queries (typically POST-queries) instead of the data address
 * - update_query_parameter - used in the body of update queries (typically PUT-queries) instead of the data address
 * - filter_query_prefix - prefix for the value in a filter query: e.g. &[data_address]=[filter_query_prefix]VALUE. Can be used to pass default operators etc.
 * 
 * The following custom data address properties are supported on object level:
 * - force_filtering - disables request withot at least a single filter (1). Some APIs disallow this!
 * - response_data_path - path to the array containing the items
 * - response_total_count_path - path to the total number of items matching the filter (used for pagination)
 * - request_offset_parameter - name of the URL parameter containing the page offset for pagination
 * - request_limit_parameter - name of the URL parameter holding the maximum number of returned items
 * - uid_request_data_address - used in requests with a filter on UID instead of the data address
 * - uid_response_data_path - used to find the data in the response for a request with a filter on UID (instead of response_data_path)
 * - create_request_data_address - used in create requests instead of the data address
 * - create_request_data_path - this is where the data is put in the body of create requests (if not specified the attributes are just put in the root object)
 * - update_request_data_address - used in update requests instead of the data address
 * - update_request_data_path - this is where the data is put in the body of update requests (if not specified the attributes are just put in the root object)
 * 
 * @see REST_XML for XML-based APIs
 * @author Andrej Kabachnik
 *
 */
class JsonUrlBuilder extends AbstractUrlBuilder {
	
	/**
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::create()
	 */
	function create(AbstractDataConnector $data_connection = null){
		// Create the request URI
		$uri = $this->get_main_object()->get_data_address_property('create_request_data_address') ? $this->get_main_object()->get_data_address_property('create_request_data_address') : $this->get_main_object()->get_data_address();
		
		// Create JSON objects from value query parts
		$json_objects = array(); 
		foreach ($this->get_values() as $qpart){
			try {
				$attr = $qpart->get_attribute();
			} catch (MetaAttributeNotFoundError $e){
				// Ignore values, that do not belong to attributes
				continue;
			}
			
			if ($attr->get_data_address() || $attr->get_data_address_property('create_query_parameter')){
				$json_attr = ($attr->get_data_address_property('create_query_parameter') ? $attr->get_data_address_property('create_query_parameter') : $attr->get_data_address());
				foreach ($qpart->get_values() as $row => $val){
					if (!$json_objects[$row]){
						$json_objects[$row] = new \stdClass();
					}
					if (!is_null($val) && $val !== ''){
						$json_objects[$row]->$json_attr = $val;
					}
				}
			}
		}
		
		$insert_ids = array();
		foreach ($json_objects as $obj){
			$json = new \stdClass();
			if ($data_path = $this->get_main_object()->get_data_address_property('create_request_data_path')){
				$level =& $json;
				foreach ($this->data_path_split($data_path) as $step){
					$level->$step = new \stdClass();
					$level =& $level->$step;
				}
				$level = $obj;
			} else {
				$json = $obj;
			}
			
			$query = new Psr7DataQuery(new Request('POST', $uri, array(), json_encode($json)));
			
			$result = $this->parse_response($data_connection->query($query));
			if (is_array($result)){
				$result_data = $this->find_row_data($result);
			}
			$insert_ids[] = $result_data[$this->get_main_object()->get_uid_attribute()->get_data_address()];
		}
		
		return $insert_ids;
	}
	
	/**
	 * {@inheritDoc}
	 * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::find_row_counter()
	 */
	protected function find_row_counter($data){
		return $data[$this->get_main_object()->get_data_address_property('response_total_count_path')];
	}
	
	/**
	 * {@inheritDoc}
	 * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::build_result_rows()
	 */
	protected function build_result_rows($parsed_data, Psr7DataQuery $query){
		$result_rows = array();
		$rows = $this->find_row_data($parsed_data);
		if (count($rows) > 0){
			if (is_array($rows)){
				foreach ($rows as $nr => $row){
					/* @var $qpart \exface\Core\CommonLogic\QueryBuilder\QueryPartSelect */
					foreach ($this->get_attributes() as $qpart){
						$val = $row;
						if ($path = $qpart->get_data_address()){
							foreach ($this->data_path_split($path) as $step){
								if ($cond_start = strpos($step, '[')){
									if (substr($step, -1) != ']') throw new QueryBuilderException('Invalid conditional selector in attribute "' . $qpart->get_alias() . '": "' . $step . '"!');
									$cond = explode('=', substr($step, $cond_start+1, -1));
									if ($val = $val[substr($step, 0, $cond_start)]){
										foreach ($val as $v){
											if ($v[$cond[0]] == $cond[1]){
												$val = $v;
												break;
											}
										}
									}
								} else {
									$val = $val[$step];
								}
							}
								
							// Check if the value is still an array and an aggregator must be applied
							if (is_array($val)){
								$val = DataColumn::aggregate_values($val, $qpart->get_aggregate_function());
							}
							$result_rows[$nr][$qpart->get_alias()] = $val;
						}
					}
				}
			}
		}
		return $result_rows;
	}
	
	/**
	 * {@inheritDoc}
	 * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::find_row_data()
	 */
	protected function find_row_data($parsed_data){
		// Get the response data path from the meta model
		// TODO make work with any request_split_filter, not just the UID
		if ($this->get_request_split_filter() && $this->get_request_split_filter()->get_attribute()->is_uid_for_object() && !is_null($this->get_main_object()->get_data_address_property('uid_response_data_path'))){
			$path = $this->get_main_object()->get_data_address_property('uid_response_data_path');
		} else {
			$path = $this->get_main_object()->get_data_address_property('response_data_path');
		}
		
		// Get the actual data
		if ($path){
			// If a path could be determined, follow it
			// $rows = $parsed_data[$path];
			$rows = $this->find_field_in_data($path, $parsed_data);
			
			// If it is a UID-request and the data is an assotiative array, it probably represents one single row, so wrap it in an
			// array to make it compatible to the logic of fetching multiple rows
			// TODO make work with any request_split_filter, not just the UID
			if ($this->get_request_split_filter() && $this->get_request_split_filter()->get_attribute()->is_uid_for_object() && count(array_filter(array_keys($parsed_data), 'is_string'))){
				$rows = array($rows);
			}
		} else {
			// If no path specified, try to find the data automatically
			if (count(array_filter(array_keys($parsed_data), 'is_string'))){
				// If data is an assotiative array, it is most likely to represent one single row
				$rows = array($parsed_data);
			} else {
				// If the data is a sequential array with numeric keys, it is most likely to represent multiple rows
				$rows = $parsed_data;
			}
		}
		
		return $rows;
	}
	
	/**
	 * Converts a data path string to an array (e.g. issue/status/id to [issue, status, id]
	 * @param unknown $string
	 */
	protected function data_path_split($string){
		return explode('/', $string);
	}
	
	/**
	 * {@inheritDoc}
	 * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::find_field_in_data()
	 */
	protected function find_field_in_data($data_address, $data){
		$val = (array) $data;
		foreach ($this->data_path_split($data_address) as $step){
			if ($cond_start = strpos($step, '[')){
				if (substr($step, -1) != ']') throw new QueryBuilderException('Invalid conditional selector in attribute "' . $qpart->get_alias() . '": "' . $step . '"!');
				$cond = explode('=', substr($step, $cond_start+1, -1));
				if ($val = $val[substr($step, 0, $cond_start)]){
					foreach ($val as $v){
						if ($v[$cond[0]] == $cond[1]){
							$val = $v;
							break;
						}
					}
				}
			} else {
				$val = $val[$step];
			}
		}
		return $val;
	}
	
	function update(AbstractDataConnector $data_connection = null){}
	function delete(AbstractDataConnector $data_connection = null){}
	
	protected function parse_response(Psr7DataQuery $query){
		return json_decode($query->get_response()->getBody(), true);
	}
}
?>