<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\DataSources\DataQueryResultDataInterface;
use exface\Core\CommonLogic\DataQueries\DataQueryResultData;
use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use Psr\Http\Message\RequestInterface;
use Elastica\Client;
use Elastica\Search;
use Elastica\Query;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use GuzzleHttp\Psr7\Uri;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\QueryBuilder\QueryPartAttribute;
use Elastica\ResultSet;
use Elastica\QueryBuilder;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilterGroup;
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;

/**
 * This is a query builder for GraphQL.
 * 
 * NOTE: this query builder is an early beta and has many limitations:
 * 
 * - TODO
 * 
 * # Data source options
 * 
 * ## On object level
 * 
 * - TODO
 * 
 * ## On attribute level
 * 
 * - TODO
 * 
 * @author Andrej Kabachnik
 *        
 */
class ElasticSearchUrlBuilder extends AbstractQueryBuilder
{   
    
    protected function getElaticaClient(HttpConnectionInterface $dataConnection) : Client
    {
        $url = new Uri($dataConnection->getUrl());
        $port = $url->getPort();
        $host = $url->getHost();
        return new Client([
            'host' => $host,
            'port' => $port
        ]);
    }
    
    protected function buildElasticQuerySearch(Client $client) : Search
    {
        $search = new Search($client);
        $search->addindex($this->buildElasticIndexName($this->getMainObject()));
        
        // Filtering
        $filters = $this->buildElasticFilters($this->getFilters());
        $query = new Query($filters === null ? null : ["query" => $filters]);
        
        // Sorting
        $query->setSort($this->buildElasticSorters());
        
        // Limit/Offset
        $query->setFrom($this->getOffset())->setSize($this->getLimit());
        
        $search->setQuery($query);
        return $search;
    }
    
    protected function buildElasticSorters() : array
    {
        $sorters = [];
        foreach ($this->getSorters() as $qpart) {
            $sorters[$qpart->getDataAddress()] = strtolower($qpart->getOrder());
        }
        return $sorters;
    }
    
    protected function buildElasticFilters(QueryPartFilterGroup $qpart) : ?array
    {
        $filters = [];
        foreach ($qpart->getFilters() as $qpartFilter) {
            $filters = array_merge($filters, $this->buildElasticFilter($qpartFilter));
        }
        if (empty($filters)) {
            return null;
        }
        return [
            "bool" => [
                "must" => [
                    [
                        "match" => $filters
                    ]
                ]
            ]
        ];
    }
    
    protected function buildElasticFilter(QueryPartFilter $qpart) : array
    {
        return [
            $qpart->getDataAddress() => [
                "query" => $qpart->getCompareValue(),
                "type" => "phrase"
            ]
        ];
    }
    
    protected function buildElasticSearchFields() : array
    {
        $fields = [];
        foreach ($this->getAttributes() as $qpart) {
            $fields[] = $this->buildElasticSearchField($qpart);
        }
        return $fields;
    }
    
    protected function buildElasticSearchField(QueryPartAttribute $qpart) : string
    {
        return $qpart->getDataAddress();
    }
    
    protected function buildElasticIndexName(MetaObjectInterface $object) : string
    {
        return $object->getDataAddress();
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::parseResponse()
     */
    protected function parseResponse(Psr7DataQuery $query)
    {
        return json_decode($query->getResponse()->getBody(), true);
    }
    
    protected function readResultRows(ResultSet $resultSet) : array
    {
        $rows = [];
        foreach ($resultSet->getResults() as $result) {
            $row = [];
            $data = $result->getData();
            foreach ($this->getAttributes() as $qpart) {
                $row[$qpart->getColumnKey()] = $data[$qpart->getDataAddress()];
            }
            $rows[] = $row;
        }
        
        return $rows;
    }
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::read()
     */
    public function read(DataConnectionInterface $data_connection) : DataQueryResultDataInterface
    {
        /* TODO query through the data connector
        $query = $data_connection->query(
            new Psr7DataQuery(
                $this->buildGqlRequest($this->buildGqlQueryRead())
            )
        );*/
        $client = $this->getElaticaClient($data_connection);
        $search = $this->buildElasticQuerySearch($client);
        $resultSet = $search->search();
        
        $rows = $this->readResultRows($resultSet);        
        $cnt = $resultSet->count();
        $cntTotal = $resultSet->getTotalHits();
        return new DataQueryResultData($rows, $cnt, ($cntTotal > $cnt), $cntTotal);
    }
    
    /**
     * Generally UrlBuilders can only handle attributes of one objects - no relations (JOINs) supported!
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder::canReadAttribute()
     */
    public function canReadAttribute(MetaAttributeInterface $attribute) : bool
    {
        return $attribute->getRelationPath()->isEmpty();
    }
}