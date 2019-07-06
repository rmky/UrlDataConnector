<?php
namespace exface\UrlDataConnector\DataConnectors;

use function GuzzleHttp\Psr7\_caseless_remove;
use function GuzzleHttp\Psr7\modify_request;
use exface\UrlDataConnector\ModelBuilders\GraphQLModelBuilder;
use exface\UrlDataConnector\Psr7DataQuery;

/**
 * Connector for GraphQL web services
 * 
 * Refer to the documentation of the HttpConnector for detailed information about connection settings. 
 *
 * @author Andrej Kabachnik
 *        
 */
class GraphQLConnector extends HttpConnector
{
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new GraphQLModelBuilder($this);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\HttpConnector::willIgnore()
     */
    protected function willIgnore(Psr7DataQuery $query) : bool
    {
        return false;
    }
}