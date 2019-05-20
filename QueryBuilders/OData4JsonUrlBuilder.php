<?php
namespace exface\UrlDataConnector\QueryBuilders;

use exface\Core\CommonLogic\QueryBuilder\QueryPartFilter;
use exface\Core\DataTypes\NumberDataType;

/**
 * This is a query builder for JSON-based oData 4.0 APIs.
 * 
 * See the AbstractUrlBuilder for information about available data address properties.
 * 
 * @see JsonUrlBuilder for data address syntax
 * @see AbstractUrlBuilder for data source specific parameters
 * 
 * @author Andrej Kabachnik
 *        
 */
class OData4JsonUrlBuilder extends OData2JsonUrlBuilder
{
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::getODataVersion()
     */
    protected function getODataVersion() : string
    {
        return '4';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::getDefaultPathToResponseRows()
     */
    protected function getDefaultPathToResponseRows() : string
    {
        return 'value';
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
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::buildUrlFilterPredicate()
     */
    protected function buildUrlFilterPredicate(QueryPartFilter $qpart, string $property, string $escapedValue) : string
    {
        $comp = $qpart->getComparator();
        switch ($comp) {
            case EXF_COMPARATOR_IS:
            case EXF_COMPARATOR_IS_NOT:
                if ($qpart->getDataType() instanceof NumberDataType) {
                    $op = ($comp === EXF_COMPARATOR_IS_NOT ? 'ne' : 'eq');
                    return "{$property} {$op} {$escapedValue}";
                } else {
                    return ($comp === EXF_COMPARATOR_IS_NOT ? 'not ' : '') . "contains({$property},{$escapedValue})";
                }
            case EXF_COMPARATOR_IN:
            case EXF_COMPARATOR_NOT_IN:
                $values = is_array($qpart->getCompareValue()) === true ? $qpart->getCompareValue() : explode($qpart->getAttribute()->getValueListDelimiter(), $qpart->getCompareValue());
                if (count($values) === 1) {
                    // If there is only one value, it is better to treat it as an equals-condition because many oData services have
                    // difficulties in() or simply do not support it.
                    $qpart->setComparator($qpart->getComparator() === EXF_COMPARATOR_IN ? EXF_COMPARATOR_EQUALS : EXF_COMPARATOR_EQUALS_NOT);
                    // Rebuild the value because we changed the comparator!
                    $escapedValue = $this->buildUrlFilterValue($qpart);
                    // Continue with next case here.
                } else {
                    if ($qpart->getComparator() === EXF_COMPARATOR_IN) {
                        return "{$property} in {$this->buildUrlFilterValue($qpart)}";
                    } else {
                        return "not ({$property} in {$this->buildUrlFilterValue($qpart)})";
                    }
                }
            default:
                return parent::buildUrlFilterPredicate($qpart, $property, $escapedValue);
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder::buildUrlFilterValue()
     */
    protected function buildUrlFilterValue(QueryPartFilter $qpart, string $preformattedValue = null)
    {
        $value = $preformattedValue ?? $qpart->getCompareValue();
        $comparator = $qpart->getComparator();
        
        if ($comparator === EXF_COMPARATOR_IN || $comparator === EXF_COMPARATOR_NOT_IN) {
            $values = [];
            if (! is_array($value)) {
                $value = explode($qpart->getAttribute()->getValueListDelimiter(), $qpart->getCompareValue());
            }
            
            foreach ($value as $val) {
                $splitQpart = clone $qpart;
                $splitQpart->setCompareValue($val);
                $splitQpart->setComparator($comparator === EXF_COMPARATOR_IN ? EXF_COMPARATOR_EQUALS : EXF_COMPARATOR_EQUALS_NOT);
                $values[] = $this->buildUrlFilterValue($splitQpart);
            }
            return '(' . implode(',', $values) . ')';
        }
        
        return parent::buildUrlFilterValue($qpart);
    }
}