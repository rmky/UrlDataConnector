<?php namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\CommonLogic\AbstractDataConnector;
/**
 * TODO: This is a very early beta. Just a demo!
 * @author Andrej Kabachnik
 *
 */
class XmlUrlBuilder extends AbstractUrlBuilder {
	
	function create(AbstractDataConnector $data_connection = null){
	
	}
	
	/**
	 * FIXME use simpleXmlElement instead of arrays. The arrays were just a shortcut to keep this similar to the JSON query builder
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::read()
	 */
	function read(AbstractDataConnector $data_connection = null){
		$result_rows = array();
		if ($data = $data_connection->query($this->build_query())){
			$data_array = (array) $data;
			
			$total_count = $data_array;
			foreach (explode('/', $this->get_main_object()->get_data_address_property('response_total_count_path')) as $step){
				$total_count = $total_count[$step];
			}
			$this->set_result_total_rows($total_count);
			
			if ($this->get_main_object()->get_data_address_property('response_data_path')){
				$rows = (array) $data_array[$this->get_main_object()->get_data_address_property('response_data_path')];
				if (!is_array($rows)){
					$rows = array($rows);
				}
			} else {
				$rows = (array) $data_array;
			}
			if (is_array($rows)){
				foreach ($rows as $nr => $row){
					$row = (array) $row;
					/* @var $qpart \exface\Core\CommonLogic\QueryBuilder\QueryPartSelect */
					foreach ($this->get_attributes() as $qpart){
						$val = $row;
						if ($path = $qpart->get_data_address()){
							foreach (explode('/', $path) as $step){
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
								$val = $this->apply_aggretator($val, $qpart->get_aggregate_function());
							}
							$result_rows[$nr][$qpart->get_alias()] = $val;
						}
					}
				}
			}
		}
		$this->set_result_rows($result_rows);
		return $this->get_result_total_rows();
	}
	
	protected function parse_response_data($data){
		return $data;
	}
	
	function update(AbstractDataConnector $data_connection = null){}
	function delete(AbstractDataConnector $data_connection = null){}
}
?>