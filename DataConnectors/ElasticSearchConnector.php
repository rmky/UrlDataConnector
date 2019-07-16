<?php
namespace exface\UrlDataConnector\DataConnectors;

use function GuzzleHttp\Psr7\_caseless_remove;
use function GuzzleHttp\Psr7\modify_request;
use exface\UrlDataConnector\ModelBuilders\GraphQLModelBuilder;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\UrlDataConnector\ModelBuilders\ElasticSearchModelBuilder;

/**
 * Connector for ElasticSearch REST API
 * 
 * The only required parameter is the `url`, which should point to the ElasticSearch API:
 * i.e. `localhost:9200` for a local installation with the default port. Don't forget the
 * port number in your URL!
 * 
 * For more options like authentification, etc. refer to the documentation of the generic 
 * `HttpConnector`. 
 *
 * @author Andrej Kabachnik
 *        
 */
class ElasticSearchConnector extends HttpConnector
{
    public function performQuery(DataQueryInterface $query)
    {
        $query = parent::performQuery($query);
        /* TODO
        $arr = json_decode($query->getResponse()->getBody(), true);
        if ($arr['errors'] !== null) {
            $err = $arr['errors'][0];
            $error = 'GraphQL error "' . $err['message'] . '" (path: ' . $err['path'] . ', locations: ' . json_encode($err['locations']) . ')';
            throw new DataQueryFailedError($query, $error);
        }*/
        
        return $query;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new ElasticSearchModelBuilder($this);
    }
}