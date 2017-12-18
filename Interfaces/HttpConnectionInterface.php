<?php
namespace exface\UrlDataConnector\Interfaces;

/**
 * Interface for HTTP-based data connections, regulating headers, authentication, etc.
 *
 * @author Andrej Kabachnik
 *        
 */
interface HttpConnectionInterface extends UrlConnectionInterface
{

    public function getUser();
    
    /**
     * Sets the user name for basic authentification.
     *
     * @param string $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUser($value);
    
    public function getPassword();
    
    /**
     * Sets the password for basic authentification.
     *
     * @uxon-property password
     * @uxon-type string
     *
     * @param string $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setPassword($value);
    
    /**
     * Returns the proxy address to be used in the name:port notation: e.g.
     * 192.169.1.10:8080 or myproxy:8080.
     *
     * @return string
     */
    public function getProxy();
    
    /**
     * Sets the proxy server address to be used.
     * Use name:port notation like 192.169.1.10:8080 or myproxy:8080.
     *
     * @param string $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setProxy($value);
    
    /**
     *
     * @return boolean
     */
    public function getUseCookies();
    
    /**
     * Set to TRUE to use cookies for this connection.
     * Defaults to FALSE.
     *
     * Cookies will be stored in the data folder of the current user!
     *
     * @param boolean $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUseCookies($value);
    
    public function getCacheEnabled();
    
    /**
     * Enables or disables caching of HTTP requests.
     * Default: FALSE.
     *
     * @param boolean $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheEnabled($value);
    
    public function getCacheIgnoreHeaders();
    
    /**
     * Makes all requests get cached regardless of their headers.
     * Default: FALSE.
     *
     * If set to TRUE, this automatically sets the default cache lifetime to 60 seconds. Use
     * "cache_lifetime_in_seconds" to specify a custom value.
     *
     * @param boolean $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheIgnoreHeaders($value);
    
    public function getCacheLifetimeInSeconds();
    
    /**
     * Sets the default lifetime for request cache items.
     *
     * @param integer $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheLifetimeInSeconds($value);
    
    /**
     * @return string
     */
    public function getFixedUrlParams();
    
    /**
     * Adds specified params to every request: e.g. &format=json&ignoreETag=false.
     * 
     * @param string $fixed_params
     */
    public function setFixedUrlParams($fixed_params);
}
?>