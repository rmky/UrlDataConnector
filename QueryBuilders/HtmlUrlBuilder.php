<?php
namespace exface\UrlDataConnector\QueryBuilders;

use Symfony\Component\DomCrawler\Crawler;
use exface\Core\Exceptions\DataTypes\DataTypeCastingError;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\DataTypes\HtmlDataType;

/**
 * This is a crawler for HTML pages.
 * It extracts data via CSS queries similar to jQuery.
 *
 * # Syntax of data addresses
 * ==========================
 * 
 * The data addresses of attributes can be defined via CSS queries with some 
 * additions:
 * 
 * - **#some-id .class** - will act like $('#some-id .class').text() in jQuery
 * - **#some-id img ->src** - will extract the value of the src attribute of the img tag (any tag attributes can be accessed this way)
 * - **#some-id img ->srcset()** - will extract the first source in the srcset attribute
 * - **#some-id img ->srcset(2x)** - will extract the 2x-source in the srcset attribute
 * - **#some-id .class ->find(.another-class) - will act as $('#some-id .class').find('another-class') in jQuery
 * - **#some-id .class ->is(.another-class) - will act as $('#some-id .class').is('another-class') in jQuery
 * - **#some-id .class ->not(.another-class) - will act as $('#some-id .class').not('another-class') in jQuery
 * - **->url** - will take the URL of the HTML page as value
 *
 * # Data source options
 * =====================
 * 
 * See AbstracUrlBuilder for data source options.
 * 
 * @see AbstractUrlBuilder
 * @see JsonUrlBuilder
 * @see XmlUrlBuilder
 *
 * @author Andrej Kabachnik
 *        
 */
class HtmlUrlBuilder extends AbstractUrlBuilder
{

    private $cache = array();

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::buildResultRows()
     */
    protected function buildResultRows($parsed_data, Psr7DataQuery $query)
    {
        $crawler = $this->getCrawler($parsed_data);
        
        $column_attributes = array();
        $result_rows = array();
        
        /* @var $qpart \exface\Core\CommonLogic\QueryBuilder\queryPartAttribute */
        foreach ($this->getAttributes() as $qpart) {
            // Ignore attributes, that have invalid data addresses (e.g. Formulas, syntax errors etc.)
            if (! $this->checkValidDataAddress($qpart->getDataAddress()))
                continue;
            
            // Determine, if we are interested in the entire node or only it's values
            if ($qpart->getAttribute()->getDataType() instanceof HtmlDataType) {
                $get_html = true;
            } else {
                $get_html = false;
            }
            
            // Determine the data type for sanitizing values
            $data_type = $qpart->getAttribute()->getDataType();
            
            // See if the data is the text in the node, or a specific attribute
            $split_pos = strpos($qpart->getDataAddress(), '->');
            $get_attribute = false;
            $get_calculation = false;
            if ($split_pos !== false) {
                $css_selector = trim(substr($qpart->getDataAddress(), 0, $split_pos));
                $extension = trim(substr($qpart->getDataAddress(), $split_pos + 2));
                if (strpos($extension, '(') !== false && strpos($extension, ')') !== false) {
                    $get_calculation = $extension;
                } else {
                    $get_attribute = $extension;
                }
                // If the selector is empty, the attribute will be taken from the entire document
                // This means, the value is the same for all rows!
                if (! $css_selector) {
                    if ($get_attribute) {
                        switch (strtolower($get_attribute)) {
                            case 'url':
                                $column_attributes[$qpart->getAlias()] = $query->getRequest()->getUri()->__toString();
                        }
                    } elseif ($get_calculation) {
                        $column_attributes[$qpart->getAlias()] = $this->performCalculationOnNode($get_calculation, $crawler->getNode(0));
                    }
                }
            } else {
                $css_selector = $qpart->getDataAddress();
            }
            
            if ($css_selector) {
                $col = $crawler->filter($css_selector);
                if (iterator_count($col) > 0) {
                    foreach ($col as $rownr => $node) {
                        if ($get_html) {
                            $value = $node->ownerDocument->saveHTML($node);
                        } elseif ($get_attribute) {
                            $value = $node->getAttribute($get_attribute);
                        } elseif ($get_calculation) {
                            $value = $this->performCalculationOnNode($get_calculation, $node);
                        } else {
                            $value = $node->textContent;
                        }
                        
                        // Sanitize value in compilance with the expected data type in the meta model
                        try {
                            $value = $data_type->parse($value);
                        } catch (DataTypeCastingError $e) {
                            // ignore errors for now
                        }
                        
                        $result_rows[$rownr][$qpart->getColumnKey()] = $value;
                    }
                }
            }
        }
        
        foreach ($column_attributes as $alias => $value) {
            foreach (array_keys($result_rows) as $rownr) {
                $result_rows[$rownr][$alias] = $value;
            }
        }
        
        if ($this->getUseUidsAsRowNumbers() === true) {
            $result_rows_with_uid_keys = array();
            foreach ($result_rows as $row) {
                $result_rows_with_uid_keys[$row[$this->getMainObject()->getUidAttribute()->getDataAddress()]] = $row;
            }
            return $result_rows_with_uid_keys;
        }
        
        return $result_rows;
    }

    protected function performCalculationOnNode($expression, \DOMNode $node)
    {
        $result = '';
        $pos = strpos($expression, '(');
        $func = substr($expression, 0, $pos);
        $args = explode(',', substr($expression, $pos + 1, (strrpos($expression, ')') - $pos - 1)));
        
        switch ($func) {
            case 'is':
            case 'not':
                $crawler = new Crawler('<div>' . $node->ownerDocument->saveHTML($node) . '</div>');
                $result = $crawler->filter($args[0])->count() > 0 ? ($func == 'is') : ($func == 'not');
                break;
            case 'find':
                $result = $this->findFieldInData($args[0], $node->ownerDocument->saveHTML($node));
                break;
            case 'srcset':
                $src_vals = explode(',', $node->getAttribute('srcset'));
                if ($args[0]) {
                    foreach ($src_vals as $src) {
                        $src_parts = explode(' ', $src);
                        if ($src_parts[1] == $args[0]) {
                            $result = $src_parts[0];
                        }
                        break;
                    }
                } else {
                    $result = $src_vals[0];
                }
                break;
        }
        return $result;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder::findFieldInData()
     */
    protected function findFieldInData($data_address, $data)
    {
        $crawler = $this->getCrawler($data);
        $css_selector = $data_address;
        
        if ($css_selector) {
            $elements = $crawler->filter($css_selector);
            if (iterator_count($elements) > 0) {
                foreach ($elements as $nr => $node) {
                    $value = $node->textContent;
                }
            }
        }
        return $value;
    }

    /**
     * Creates a Symfony crawler instace for the given HTML.
     * Crawlers are cached in memory (within one request)
     *
     * @param string $parsed_data            
     * @return \Symfony\Component\DomCrawler\Crawler
     */
    protected function getCrawler($parsed_data)
    {
        $cache_key = md5($parsed_data);
        if (! $crawler = $this->cache[$cache_key]) {
            $crawler = new Crawler($parsed_data);
            $this->cache[$cache_key] = $crawler;
        }
        return $crawler;
    }
}
?>