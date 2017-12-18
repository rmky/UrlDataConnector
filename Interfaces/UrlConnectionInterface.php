<?php
namespace exface\UrlDataConnector\Interfaces;

use exface\Core\Interfaces\DataSources\DataConnectionInterface;

/**
 * General interface for URL-based data connections: e.g. HTTP, HTTPS, FTP, etc.
 *
 * @author Andrej Kabachnik
 *        
 */
interface UrlConnectionInterface extends DataConnectionInterface
{

    public function getUrl();

    /**
     * Sets the base URL for this connection.
     * It will be prepended to every data address accessed here.
     *
     * If a base URL is set, data addresses of meta objects from this data source should be relative URL!
     *
     * @param string $value            
     * @return UrlConnectionInterface
     */
    public function setUrl($value);
}
?>