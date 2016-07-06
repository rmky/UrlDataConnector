<?php namespace exface\HttpDataConnector\QueryBuilders;

use Symfony\Component\DomCrawler\Crawler;
use exface\Core\CommonLogic\AbstractDataConnector;
/**
 * This is a query builder for JSON-based REST APIs. It creates a sequence of URL parameters for a query and parses the JSON result.
 * 
 * The following custom data address properties are supported on attribute level:
 * - filter_query_parameter - used for filtering instead of the attributes data address: e.g. &[filter_query_parameter]=VALUE instead of &[data_address]=VALUE
 * - filter_query_prefix - prefix for the value in a filter query: e.g. &[data_address]=[filter_query_prefix]VALUE. Can be used to pass default operators etc.
 * 
 * The following custom data address properties are supported on object level:
 * - force_filtering - disables request withot at least a single filter (1). Some APIs disallow this!
 * - response_data_path - path to the array containing the items
 * - response_total_count_path - path to the total number of items matching the filter (used for pagination)
 * - request_offset_parameter - name of the URL parameter containing the page offset for pagination
 * - request_limit_parameter - name of the URL parameter holding the maximum number of returned items
 * 
 * @see REST_XML for XML-based APIs
 * @author aka
 *
 */
class HTML extends AbstractRest {
	
	/**
	 * {@inheritDoc}
	 * @see \exface\HttpDataConnector\QueryBuilders\AbstractRest::parse_response_data()
	 */
	protected function parse_response_data($data){
		$crawler = new Crawler($data[0]['body']);
		$column_attributes = array();
		$result_rows = array();
		
		/* @var $qpart \exface\Core\CommonLogic\QueryBuilder\queryPartAttribute */
		foreach ($this->get_attributes() as $qpart){
			// Ignore attributes, that have invalid data addresses (e.g. Formulas, syntax errors etc.)
			if (!$this->check_valid_data_address($qpart->get_data_address())) continue;
		
			// Determine, if we are interested in the entire node or only it's values
			if ($qpart->get_attribute()->get_data_type()->is(EXF_DATA_TYPE_HTML)){
				$get_html = true;
			} else {
				$get_html = false;
			}
		
			// See if the data is the text in the node, or a specific attribute
			$split_pos = strpos($qpart->get_data_address(), '->');
			if ($split_pos !== false){
				$css_selector = trim(substr($qpart->get_data_address(), 0, $split_pos));
				$get_attribute = trim(substr($qpart->get_data_address(), $split_pos+2));
				// If the selector is empty, the attribute will be taken from the entire document
				// This means, the value is the same for all rows!
				if (!$css_selector && $get_attribute){
					switch (strtolower($get_attribute)){
						case 'url': $column_attributes[$qpart->get_alias()] = $data[0]['url'];
					}
				}
			} else {
				$css_selector = $qpart->get_data_address();
				$get_attribute = false;
			}
		
			if ($css_selector){
				$col = $crawler->filter($css_selector);
				if (iterator_count($col) > 0){
					foreach ($col as $rownr => $node){
						if ($get_html){
							$value = $node->ownerDocument->saveHTML($node);
						} elseif ($get_attribute) {
							$value = $node->getAttribute($get_attribute);
						} else {
							$value = $node->textContent;
						}
						$result_rows[$rownr][$qpart->get_alias()] = $value;
					}
				}
			}
		}
			
		foreach ($column_attributes as $alias => $value){
			foreach ($result_rows as $rownr => $row){
				$result_rows[$rownr][$alias] = $value;
			}
		}
		
		return $result_rows;
	}
	
	/**
	 * {@inheritDoc}
	 * @see \exface\HttpDataConnector\QueryBuilders\AbstractRest::find_field_in_data()
	 */
	protected function find_field_in_data($data_address, $data){
		// TODO extract code for this function from parse_response_data()
	}
}
?>