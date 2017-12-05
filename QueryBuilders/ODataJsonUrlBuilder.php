<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\Exceptions\QueryBuilderException;
use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\CommonLogic\DataSheets\DataColumn;
use exface\UrlDataConnector\Psr7DataQuery;
use GuzzleHttp\Psr7\Request;
use exface\Core\Exceptions\Model\MetaAttributeNotFoundError;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * This is a query builder for JSON-based oData APIs.
 *
 * @see JsonUrlBuilder
 * 
 * @author Andrej Kabachnik
 *        
 */
class ODataJsonUrlBuilder extends JsonUrlBuilder
{
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\JsonUrlBuilder::buildPathToResponseRows()
     */
    protected function buildPathToResponseRows(Psr7DataQuery $query)
    {
        $path = parent::buildPathToResponseRows($query);
        
        if (is_null($path)) {
            $path = 'value';
        }
        
        return $path;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findRowCounter()
     */
    protected function findRowCounter($data, Psr7DataQuery $query)
    {
        $count = parent::findRowCounter($data, $query);
        if (is_null($count)) {
            $uri = $query->getRequest()->getUri();
            $count_uri = $uri->withPath($uri->getPath() . '/$count');
            
            $count_query = $uri->getQuery();
            $count_query = preg_replace('/\&?' . preg_quote($this->buildUrlParamLimit($this->getMainObject())) . '=\d*/', "", $count_query);
            $count_query = preg_replace('/\&?' . preg_quote($this->buildUrlParamOffset($this->getMainObject())) . '=\d*/', "", $count_query);
            $count_uri = $count_uri->withQuery($count_query);
            
            try {
                $count_query = $this->getMainObject()->getDataConnection()->query(new Psr7DataQuery(new Request('GET', $count_uri)));
                $count = (string) $count_query->getResponse()->getBody();
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e, LoggerInterface::WARNING);
            }
        }
        return $count;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildPathToTotalRowCounter()
     */
    protected function buildPathToTotalRowCounter(MetaObjectInterface $object)
    {
        return '@odata.count';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlParamOffset()
     */
    protected function buildUrlParamOffset(MetaObjectInterface $object)
    {
        $custom_param = parent::buildUrlParamOffset($object);
        return $custom_param ? $custom_param : '$skiptoken';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlParamLimit()
     */
    protected function buildUrlParamLimit(MetaObjectInterface $object)
    {
        $custom_param = parent::buildUrlParamLimit($object);
        return $custom_param ? $custom_param : '$top';
    }
}
?>