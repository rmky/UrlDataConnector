<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\CommonLogic\DataSheets\DataColumn;
use Psr\Http\Message\ResponseInterface;
use exface\UrlDataConnector\Psr7DataQuery;

/**
 * TODO: This is a very early beta.
 * Just a demo!
 *
 * @author Andrej Kabachnik
 *        
 */
class XmlUrlBuilder extends AbstractUrlBuilder
{

    function create(AbstractDataConnector $data_connection = null)
    {}

    /**
     * FIXME use simpleXmlElement instead of arrays.
     * The arrays were just a shortcut to keep this similar to the JSON query builder
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::read()
     */
    function read(AbstractDataConnector $data_connection = null)
    {
        $result_rows = array();
        if ($data = $this->parseResponse($data_connection->query($this->buildQuery()))) {
            $data_array = (array) $data;
            
            $total_count = $data_array;
            foreach (explode('/', $this->getMainObject()->getDataAddressProperty('response_total_count_path')) as $step) {
                $total_count = $total_count[$step];
            }
            $this->setResultTotalRows($total_count);
            
            if ($this->getMainObject()->getDataAddressProperty('response_data_path')) {
                $rows = (array) $data_array[$this->getMainObject()->getDataAddressProperty('response_data_path')];
                if (! is_array($rows)) {
                    $rows = array(
                        $rows
                    );
                }
            } else {
                $rows = (array) $data_array;
            }
            if (is_array($rows)) {
                foreach ($rows as $nr => $row) {
                    $row = (array) $row;
                    /* @var $qpart \exface\Core\CommonLogic\QueryBuilder\QueryPartSelect */
                    foreach ($this->getAttributes() as $qpart) {
                        $val = $row;
                        if ($path = $qpart->getDataAddress()) {
                            foreach (explode('/', $path) as $step) {
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
                                $val = DataColumn::aggregateValues($val, $qpart->getAggregator());
                            }
                            $result_rows[$nr][$qpart->getAlias()] = $val;
                        }
                    }
                }
            }
        }
        $this->setResultRows($result_rows);
        return $this->getResultTotalRows();
    }

    protected function buildResultRows($parsed_data, Psr7DataQuery $query)
    {
        return $parsed_data;
    }

    function update(AbstractDataConnector $data_connection = null)
    {}

    function delete(AbstractDataConnector $data_connection = null)
    {}

    protected function parseResponse(ResponseInterface $response)
    {
        return new \SimpleXMLElement($response->getBody()->getContents());
    }
}
?>