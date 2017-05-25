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
 * It creates a sequence of URL parameters for a query and parses the JSON result.
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
            try {
                $attr = $qpart->getAttribute();
            } catch (MetaAttributeNotFoundError $e) {
                // Ignore values, that do not belong to attributes
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
     * {@inheritdoc}
     *
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::findRowCounter()
     */
    protected function findRowCounter($data)
    {
        return $data[$this->getMainObject()->getDataAddressProperty('response_total_count_path')];
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::buildResultRows()
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
     * {@inheritdoc}
     *
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::findRowData()
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
     * @param unknown $string            
     */
    protected function dataPathSplit($string)
    {
        return explode('/', $string);
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractRest::findFieldInData()
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

    function update(AbstractDataConnector $data_connection = null)
    {}

    function delete(AbstractDataConnector $data_connection = null)
    {}

    protected function parseResponse(Psr7DataQuery $query)
    {
        return json_decode($query->getResponse()->getBody(), true);
    }
}
?>