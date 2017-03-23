<?php namespace exface\UrlDataConnector\QueryBuilders;

use Symfony\Component\DomCrawler\Crawler;
use exface\Core\Exceptions\DataTypeValidationError;
use GuzzleHttp\Psr7\Request;
use exface\UrlDataConnector\Psr7DataQuery;

/**
 * This is a crawler for HTML pages. It extracts data via CSS queries similar to jQuery.
 * 
 * The data addresses of attributes can be defined via CSS queries with some additions:
 * - "#some-id .class" - will act like $('#some-id .class').text() in jQuery
 * - "#some-id img ->src" - will extract the value of the src attribute of the img tag (any tag attributes can be accessed this way)
 * - "#some-id img ->srcset()" - will extract the first source in the srcset attribute
 * - "#some-id img ->srcset(2x)" - will extract the 2x-source in the srcset attribute
 * - "#some-id .class ->find(.another-class) - will act as $('#some-id .class').find('another-class') in jQuery
 * - "#some-id .class ->is(.another-class) - will act as $('#some-id .class').is('another-class') in jQuery
 * - "#some-id .class ->not(.another-class) - will act as $('#some-id .class').not('another-class') in jQuery
 * - "->url" - will take the URL of the HTML page as value
 * 
 * The following custom data address properties are supported on attribute level:
 * - filter_remote - set to 1 to enable remote filtering (0 by default)
 * - filter_remote_url - used to set a custom URL to be used if there is a filter over this attribute
 * - filter_remote_url_param - used for filtering instead of the attributes data address: e.g. &[filter_remote_url_param]=VALUE instead of &[data_address]=VALUE
 * - filter_remote_prefix - prefix for the value in a filter query: e.g. &[data_address]=[filter_remote_prefix]VALUE. Can be used to pass default operators etc.
 * - filter_locally - set to 1 to filter in ExFace after reading the data (if the data source does not support filtering over this attribute).
 * - sort_remote - set to 1 to enable remote sorting (0 by default)
 * - sort_remote_url_param - used for sorting instead of the attributes data address: e.g. &[sort_remote_url_param]=VALUE instead of &[data_address]=VALUE
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
 * - request_url_replace_pattern - regular expression pattern for PHP preg_replace() function to be performed on the request URL
 * - request_url_replace_with - replacement string for PHP preg_replace() function to be performed on the request URL
 * 
 * @author Andrej Kabachnik
 *
 */
class HtmlUrlBuilder extends AbstractUrlBuilder {
	private $cache = array();
	
	/**
	 * {@inheritDoc}
	 * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::build_result_rows()
	 */
	protected function build_result_rows($parsed_data, Psr7DataQuery $query){
		$crawler = $this->get_crawler($parsed_data);
		
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
			
			// Determine the data type for sanitizing values
			$data_type = $qpart->get_attribute()->get_data_type();
		
			// See if the data is the text in the node, or a specific attribute
			$split_pos = strpos($qpart->get_data_address(), '->');
			$get_attribute = false;
			$get_calculation = false;
			if ($split_pos !== false){
				$css_selector = trim(substr($qpart->get_data_address(), 0, $split_pos));
				$extension = trim(substr($qpart->get_data_address(), $split_pos+2));
				if (strpos($extension, '(') !== false && strpos($extension, ')') !== false){
					$get_calculation = $extension;
				} else {
					$get_attribute = $extension;
				}
				// If the selector is empty, the attribute will be taken from the entire document
				// This means, the value is the same for all rows!
				if (!$css_selector){
					if ($get_attribute){
						switch (strtolower($get_attribute)){
							case 'url': $column_attributes[$qpart->get_alias()] = $query->get_request()->getUri()->__toString();
						}
					} elseif ($get_calculation){
						$column_attributes[$qpart->get_alias()] = $this->perform_calculation_on_node($get_calculation, $crawler->getNode(0));
					}
				}
			} else {
				$css_selector = $qpart->get_data_address();
			}
		
			if ($css_selector){
				$col = $crawler->filter($css_selector);
				if (iterator_count($col) > 0){
					foreach ($col as $rownr => $node){
						if ($get_html){
							$value = $node->ownerDocument->saveHTML($node);
						} elseif ($get_attribute) {
							$value = $node->getAttribute($get_attribute);
						} elseif ($get_calculation) { 
							$value = $this->perform_calculation_on_node($get_calculation, $node);
						} else {
							$value = $node->textContent;
						}
						
						// Sanitize value in compilance with the expected data type in the meta model
						try {
							$value = $data_type->parse($value);
						} catch (DataTypeValidationError $e){
							// ignore errors for now
						}
						
						$result_rows[$rownr][$qpart->get_alias()] = $value;
					}
				}
			}
		}
			
		foreach ($column_attributes as $alias => $value){
			foreach (array_keys($result_rows) as $rownr){
				$result_rows[$rownr][$alias] = $value;
			}
		}
		
		$result_rows_with_uid_keys = array();
		if ($this->get_attribute($this->get_main_object()->get_uid_alias())){
			foreach($result_rows as $row){
				$result_rows_with_uid_keys[$row[$this->get_main_object()->get_uid_alias()]] = $row;
			}
		} else {
			$result_rows_with_uid_keys = $result_rows;
		}
		
		return $result_rows_with_uid_keys;
	}
	
	protected function perform_calculation_on_node($expression, \DOMNode $node){
		$result = '';
		$pos = strpos($expression, '(');
		$func = substr($expression, 0, $pos);
		$args = explode(',', substr($expression, $pos+1, (strrpos($expression, ')')-$pos-1)));
		
		switch ($func){
			case 'is': 
			case 'not':
				$crawler = new Crawler('<div>' . $node->ownerDocument->saveHTML($node) . '</div>');
				$result = $crawler->filter($args[0])->count() > 0 ? ($func=='is') : ($func=='not');
				break;
			case 'find':
				$result = $this->find_field_in_data($args[0], $node->ownerDocument->saveHTML($node));
				break;
			case 'srcset':
				$src_vals = explode(',', $node->getAttribute('srcset'));
				if ($args[0]){
					foreach($src_vals as $src){
						$src_parts = explode(' ', $src);
						if ($src_parts[1] == $args[0]){
							$result = $src_parts[0];
						}
						break;
					}
				} else {
					$result = $src_vals[0];
				}
				break;
		}
		return $result;
	}
	
	/**
	 * {@inheritDoc}
	 * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::find_field_in_data()
	 */
	protected function find_field_in_data($data_address, $data){
		$crawler = $this->get_crawler($data);
		$css_selector = $data_address;
		
		if ($css_selector){
			$elements = $crawler->filter($css_selector);
			if (iterator_count($elements) > 0){
				foreach ($elements as $nr => $node){
					$value = $node->textContent;
				}
			}
		}
		return $value;
	}
	
	/**
	 * Creates a Symfony crawler instace for the given HTML. Crawlers are cached in memory (within one request)
	 * 
	 * @param string $parsed_data
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	protected function get_crawler($parsed_data) {
		$cache_key = md5($parsed_data);
		if (!$crawler = $this->cache[$cache_key]){
			$crawler = new Crawler($parsed_data);
			$this->cache[$cache_key] = $crawler;
		}
		return $crawler;
	}	
	  
}
?>