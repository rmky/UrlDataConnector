<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\CommonLogic\DataSheets\DataColumn;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use exface\Core\Exceptions\Model\MetaAttributeNotFoundError;

/**
 * This is a query builder for JSON-based REST APIs.
 * 
 * In addition to the logic of the AbstractUrlBuilder, the JsonUrlBuilder will
 * parse the responses and create request bodies as JSON.
 * 
 * # Syntax of data addresses
 * ==========================
 * 
 * - **my_field** will get the value from {"my_field": "value"}
 * - **address.street** will get the value from {"address": {"street": "value"}}
 * - **authors[1].name** will get the value from {"authors": [{...}, {"name: "value", ...}, {...}]}
 * - **barcodes[type=ean8].code** will get the value from {"barcodes": [{...}, {"type": "ean8", "code": "value"}]}
 *
 * # Data source options
 * =====================
 * 
 * In addition to the data source options of the AbstractUrlBuilder, the
 * JsonUrlBuilder supports the following options. 
 * 
 * ## On object level
 * ------------------
 *
 * - **create_request_data_path** - this is where the data is put in the body of 
 * create requests (if not specified the attributes are just put in the root 
 * object)
 * 
 * ## On attribute level
 * ---------------------
 * 
 * - **create_query_parameter** - used in the body of create queries (typically 
 * POST-queries) instead of the data address
 * 
 * - **update_query_parameter** - used in the body of update queries (typically 
 * PUT-queries) instead of the data address
 *
 * @see AbstractUrlBuilder for basic configuration
 * @see HtmlUrlBuilder for an HTML-parser
 * @see XmlUrlBuilder for XML-based APIs
 * 
 * @author Andrej Kabachnik
 *        
 */
class JsonUrlBuilder extends AbstractUrlBuilder
{

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::create()
     */
    function create(AbstractDataConnector $data_connection = null)
    {
        // Create the request URI
        $uri = $this->getMainObject()->getDataAddressProperty('create_request_data_address') ? $this->getMainObject()->getDataAddressProperty('create_request_data_address') : $this->getMainObject()->getDataAddress();
        
        // Create JSON objects from value query parts
        $json_objects = array();
        foreach ($this->getValues() as $qpart) {
            // Ignore values, that do not belong to attributes
            try {
                $attr = $qpart->getAttribute();
            } catch (MetaAttributeNotFoundError $e) {
                continue;
            }
            
            // Ignore values of related attributes
            if (! $attr->getRelationPath()->isEmpty()){
                $this->getWorkbench()->getLogger()->notice('JsonUrlBuilder cannot perform create-operations on related attributes: skipping "' . $attr->getAliasWithRelationPath() . '" of object "' . $this->getMainObject()->getAliasWithNamespace() . '"!');
                continue;
            }
            
            if ($attr->getDataAddress() || $attr->getDataAddressProperty('create_query_parameter')) {
                $json_attr = ($attr->getDataAddressProperty('create_query_parameter') ? $attr->getDataAddressProperty('create_query_parameter') : $attr->getDataAddress());
                foreach ($qpart->getValues() as $row => $val) {
                    if (! $json_objects[$row]) {
                        $json_objects[$row] = new \stdClass();
                    }
                    if (! is_null($val) && $val !== '') {
                        $json_objects[$row]->$json_attr = $val;
                    }
                }
            }
        }
        
        $insert_ids = array();
        foreach ($json_objects as $obj) {
            $json = new \stdClass();
            if ($data_path = $this->getMainObject()->getDataAddressProperty('create_request_data_path')) {
                $level = & $json;
                foreach ($this->dataPathSplit($data_path) as $step) {
                    $level->$step = new \stdClass();
                    $level = & $level->$step;
                }
                $level = $obj;
            } else {
                $json = $obj;
            }
            
            $query = new Psr7DataQuery(new Request('POST', $uri, array(
                'Content-Type' => 'application/json'
            ), json_encode($json)));
            
            $result = $this->parseResponse($data_connection->query($query));
            if (is_array($result)) {
                $result_data = $this->findRowData($result, $data_path);
            }
            $insert_ids[] = $this->findFieldInData($this->getMainObject()->getUidAttribute()->getDataAddress(), $result_data);
        }
        
        return $insert_ids;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findRowCounter()
     */
    protected function findRowCounter($data)
    {
        return $data[$this->getMainObject()->getDataAddressProperty('response_total_count_path')];
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildResultRows()
     */
    protected function buildResultRows($parsed_data, Psr7DataQuery $query)
    {
        $result_rows = array();
        $rows = $this->findRowData($parsed_data);
        $has_uid_column = $this->getAttribute($this->getMainObject()->getUidAlias()) ? true : false;
        if (count($rows) > 0) {
            if (is_array($rows)) {
                foreach ($rows as $nr => $row) {
                    $result_row = array();
                    /* @var $qpart \exface\Core\CommonLogic\QueryBuilder\QueryPartSelect */
                    foreach ($this->getAttributes() as $qpart) {
                        $val = $row;
                        if ($path = $qpart->getDataAddress()) {
                            foreach ($this->dataPathSplit($path) as $step) {
                                if ($cond_start = strpos($step, '[')) {
                                    if (substr($step, - 1) != ']')
                                        throw new QueryBuilderException('Invalid conditional selector in attribute "' . $qpart->getAlias() . '": "' . $step . '"!');
                                    $cond = explode('=', substr($step, $cond_start + 1, - 1));
                                    if ($val = $val[substr($step, 0, $cond_start)]) {
                                        foreach ($val as $v) {
                                            if ($v[$cond[0]] == $cond[1]) {
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
                            if (is_array($val)) {
                                $val = DataColumn::aggregateValues($val, $qpart->getAggregateFunction());
                            }
                            $result_row[$qpart->getAlias()] = $val;
                        }
                    }
                    if ($has_uid_column) {
                        $result_rows[$result_row[$this->getMainObject()->getUidAlias()]] = $result_row;
                    } else {
                        $result_rows[] = $result_row;
                    }
                }
            }
        }
        return $result_rows;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findRowData()
     */
    protected function findRowData($parsed_data, $data_path = null)
    {
        // Get the response data path from the meta model
        if (is_null($data_path)) {
            // TODO make work with any request_split_filter, not just the UID
            if ($this->getRequestSplitFilter() && $this->getRequestSplitFilter()->getAttribute()->isUidForObject() && ! is_null($this->getMainObject()->getDataAddressProperty('uid_response_data_path'))) {
                $path = $this->getMainObject()->getDataAddressProperty('uid_response_data_path');
            } else {
                $path = $this->getMainObject()->getDataAddressProperty('response_data_path');
            }
        } else {
            $path = $data_path;
        }
        
        // Get the actual data
        if ($path) {
            // If a path could be determined, follow it
            // $rows = $parsed_data[$path];
            $rows = $this->findFieldInData($path, $parsed_data);
            
            // If it is a UID-request and the data is an assotiative array, it probably represents one single row, so wrap it in an
            // array to make it compatible to the logic of fetching multiple rows
            // TODO make work with any request_split_filter, not just the UID
            if ($this->getRequestSplitFilter() && $this->getRequestSplitFilter()->getAttribute()->isUidForObject() && count(array_filter(array_keys($rows), 'is_string'))) {
                $rows = array(
                    $rows
                );
            }
        } else {
            // If no path specified, try to find the data automatically
            if (count(array_filter(array_keys($parsed_data), 'is_string'))) {
                // If data is an assotiative array, it is most likely to represent one single row
                $rows = array(
                    $parsed_data
                );
            } else {
                // If the data is a sequential array with numeric keys, it is most likely to represent multiple rows
                $rows = $parsed_data;
            }
        }
        
        return $rows;
    }

    /**
     * Converts a data path string to an array (e.g.
     * issue/status/id to [issue, status, id]
     *
     * @param string $string            
     */
    protected function dataPathSplit($string)
    {
        return explode('/', $string);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findFieldInData()
     */
    protected function findFieldInData($data_address, $data)
    {
        $val = (array) $data;
        foreach ($this->dataPathSplit($data_address) as $step) {
            if ($cond_start = strpos($step, '[')) {
                if (substr($step, - 1) != ']')
                    throw new QueryBuilderException('Invalid conditional selector in attribute "' . $qpart->getAlias() . '": "' . $step . '"!');
                $cond = explode('=', substr($step, $cond_start + 1, - 1));
                if ($val = $val[substr($step, 0, $cond_start)]) {
                    foreach ($val as $v) {
                        if ($v[$cond[0]] == $cond[1]) {
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

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::update()
     */
    function update(AbstractDataConnector $data_connection = null)
    {}

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::delete()
     */
    function delete(AbstractDataConnector $data_connection = null)
    {}

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::parseResponse()
     */
    protected function parseResponse(Psr7DataQuery $query)
    {
        return json_decode($query->getResponse()->getBody(), true);
    }
}
?>