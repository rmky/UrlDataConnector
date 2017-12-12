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
use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\DataTypes\StringDataType;

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
            
            $count_url_params = $uri->getQuery();
            $count_url_params = preg_replace('/\&?' . preg_quote($this->buildUrlParamLimit($this->getMainObject())) . '=\d*/', "", $count_url_params);
            $count_url_params = preg_replace('/\&?' . preg_quote($this->buildUrlParamOffset($this->getMainObject())) . '=\d*/', "", $count_url_params);
            $count_url_params = preg_replace('/\&?\$format=.*/', "", $count_url_params);
            $count_uri = $count_uri->withQuery($count_url_params);
            $count_query = new Psr7DataQuery(new Request('GET', $count_uri));
            $count_query->setUriFixed(true);
            
            try {
                $count_query = $this->getMainObject()->getDataConnection()->query($count_query);
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
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlFilters()
     */
    protected function buildUrlFilters(array $filters)
    {
        $query = '';
        foreach ($filters as $qpart) {
            $query .= ($query ? ' and ' : '') . $this->buildUrlFilter($qpart);
        }
        
        if ($query !== '') {
            $query = '$filter=' . $query;
        }
        
        return $query;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlFilter()
     */
    protected function buildUrlFilter(QueryPartFilter $qpart)
    {
        $param = $this->buildUrlParamFilter($qpart);
        
        if (! $param) {
            return '';
        }
        
        $comparator = $this->buildUrlFilterComparator($qpart);
        $value = $this->buildUrlFilterValue($qpart);
        
        // Add a prefix to the value if needed
        if ($prefix = $qpart->getDataAddressProperty('filter_remote_prefix')) {
            $value = $prefix . $value;
        }
        
        return $param . ' ' . $comparator . ' ' . $value;
    }
    
    /**
     * Returns the oData filter operator to use for the given filter query part.
     * 
     * @link http://www.odata.org/documentation/odata-version-2-0/uri-conventions/
     * 
     * @param QueryPartFilter $qpart
     * @throws QueryBuilderException
     * 
     * @return string
     */
    protected function buildUrlFilterComparator(QueryPartFilter $qpart)
    {
        switch ($qpart->getComparator()) {
            case EXF_COMPARATOR_IS:
            case EXF_COMPARATOR_EQUALS:
                $comp = 'eq';
                break;
            case EXF_COMPARATOR_IS_NOT:
            case EXF_COMPARATOR_EQUALS_NOT:
                $comp = 'ne';
                break;
            case EXF_COMPARATOR_GREATER_THAN: $comp = 'gt'; break;
            case EXF_COMPARATOR_GREATER_THAN_OR_EQUALS: $comp = 'ge'; break;
            case EXF_COMPARATOR_LESS_THAN: $comp = 'lt'; break;
            case EXF_COMPARATOR_LESS_THAN_OR_EQUALS: $comp = 'le'; break;
            default:
                throw new QueryBuilderException('Comparator "' . $qpart->getComparator() . '" not supported in oData URL filters');
        }
        return $comp;
    }
    
    /**
     * Returns a string representing the query part's value, that is usable in a filter expression.
     * 
     * @param QueryPartFilter $qpart
     * @return string
     */
    protected function buildUrlFilterValue(QueryPartFilter $qpart)
    {
        $value = $qpart->getCompareValue();
        
        if (is_array($qpart->getCompareValue())) {
            $value = implode($qpart->getAttribute()->getValueListDelimiter(), $value);
        }
        
        switch (true) {
            // Wrap string data types in single quotes
            // Since spaces are used as delimiters in oData filter expression, they need to be
            // replaced by x0020.
            case ($qpart->getDataType() instanceof StringDataType): $value = "'" . str_replace(' ', 'x0020', $value) . "'"; break; 
        }
        
        return $value;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildUrlSorters()
     */
    protected function buildUrlSorters()
    {
        $url = '';
        $sort = [];
        $order = [];
        
        foreach ($this->getSorters() as $qpart) {
            if ($sortParam = $this->buildUrlParamSorter($qpart)) {
                $sort[] = $sortParam;
                $order[] = $qpart->getOrder();
            }
        }
        
        if (! empty($sort)) {
            $url = '$orderby=' . implode(',', $sort);
        }
        
        if (! empty($order)) {
            $url .= ' ' . implode(',', $order);
        }
        
        return $url;
    }
}
?>