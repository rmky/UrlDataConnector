<?php
namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnector;
use GuzzleHttp\Client;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Exceptions\DataSources\DataConnectionQueryTypeError;
use exface\Core\Exceptions\DataSources\DataConnectionFailedError;
use GuzzleHttp\Psr7\Response;
use exface\Core\CommonLogic\Filemanager;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\Psr6CacheStorage;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Connector for Websites, Webservices and other data sources accessible via HTTP, HTTPS, FTP, etc.
 *
 * @author Andrej Kabachnik
 *        
 */
class HttpConnector extends AbstractUrlConnector
{

    private $user = null;

    private $password = null;

    private $proxy = null;

    private $charset = null;

    private $use_cookies = false;

    private $cache_enabled = false;

    private $cache_ignore_headers = false;

    private $cache_lifetime_in_seconds = 0;

    /** @var Client */
    protected $client;

    protected $last_request = null;

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performConnect()
     */
    protected function performConnect()
    {
        $defaults = array();
        $defaults['verify'] = false;
        $defaults['base_uri'] = $this->getUrl();
        // Proxy settings
        if ($this->getProxy()) {
            $defaults['proxy'] = $this->getProxy();
        }
        
        // Basic authentication
        if ($this->getUser()) {
            $defaults['auth'] = array(
                $this->getUser(),
                $this->getPassword()
            );
        }
        
        // Cookies
        if ($this->getUseCookies()) {
            $cookieFile = str_replace(array(
                ':',
                '/',
                '.'
            ), '', $this->getUrl()) . '.cookie';
            $cookieDir = $this->getWorkbench()->context()->getScopeUser()->getUserDataFolderAbsolutePath() . DIRECTORY_SEPARATOR . 'cookies';
            if (! file_exists($cookieDir)) {
                mkdir($cookieDir);
            }
            $cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieDir . DIRECTORY_SEPARATOR . $cookieFile);
            $defaults['cookies'] = $cookieJar;
        }
        
        // Cache
        if ($this->getCacheEnabled()) {
            // Create default HandlerStack
            $stack = HandlerStack::create();
            
            if ($this->getCacheIgnoreHeaders()) {
                $cache_strategy_class = '\\Kevinrob\\GuzzleCache\\Strategy\\GreedyCacheStrategy';
            } else {
                $cache_strategy_class = '\\Kevinrob\\GuzzleCache\\Strategy\\PrivateCacheStrategy';
            }
            
            // Add cache middleware to the top with `push`
            $stack->push(new CacheMiddleware(new $cache_strategy_class(new Psr6CacheStorage(new FilesystemAdapter('', 0, $this->getCacheAbsolutePath())), $this->getCacheLifetimeInSeconds())), 'cache');
            
            // Initialize the client with the handler option
            $defaults['handler'] = $stack;
        }
        
        try {
            $this->client = new Client($defaults);
        } catch (\Throwable $e) {
            throw new DataConnectionFailedError($this, "Failed to instantiate HTTP client: " . $e->getMessage(), '6T4RAVX', $e);
        }
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performQuery()
     *
     * @param Psr7DataQuery $query            
     * @return Psr7DataQuery
     */
    protected function performQuery(DataQueryInterface $query)
    {
        if (! ($query instanceof Psr7DataQuery))
            throw new DataConnectionQueryTypeError($this, 'Connector "' . $this->getAliasWithNamespace() . '" expects a Psr7DataQuery as input, "' . get_class($query) . '" given instead!');
        /* @var $query \exface\UrlDataConnector\Psr7DataQuery */
        if (! $query->getRequest()->getUri()->__toString()) {
            $query->setResponse(new Response());
        } else {
            if (! $this->client) {
                $this->connect();
            }
            $query->setResponse($this->client->send($query->getRequest()));
        }
        return $query;
    }

    function getInsertId()
    {
        // TODO
        return 0;
    }

    public function getUser()
    {
        return $this->user;
    }

    /**
     * Sets the user name for basic authentification.
     *
     * @uxon-property user
     * @uxon-type string
     *
     * @param string $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUser($value)
    {
        $this->user = $value;
        return $this;
    }

    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Sets the password for basic authentification.
     *
     * @uxon-property password
     * @uxon-type string
     *
     * @param string $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setPassword($value)
    {
        $this->password = $value;
        return $this;
    }

    /**
     * Returns the proxy address to be used in the name:port notation: e.g.
     * 192.169.1.10:8080 or myproxy:8080.
     *
     * @return string
     */
    public function getProxy()
    {
        return $this->proxy;
    }

    /**
     * Sets the proxy server address to be used.
     * Use name:port notation like 192.169.1.10:8080 or myproxy:8080.
     *
     * @uxon-property proxy
     * @uxon-type string
     *
     * @param string $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setProxy($value)
    {
        $this->proxy = $value;
        return $this;
    }

    /**
     *
     * @return boolean
     */
    public function getUseCookies()
    {
        return $this->use_cookies;
    }

    /**
     * Set to TRUE to use cookies for this connection.
     * Defaults to FALSE.
     *
     * Cookies will be stored in the data folder of the current user!
     *
     * @uxon-property use_cookies
     * @uxon-type boolean
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUseCookies($value)
    {
        $this->use_cookies = \exface\Core\DataTypes\BooleanDataType::parse($value);
        return $this;
    }

    public function getCacheEnabled()
    {
        return $this->cache_enabled;
    }

    /**
     * Enables or disables caching of HTTP requests.
     * Default: FALSE.
     *
     * @uxon-property cache_enabled
     * @uxon-type boolean
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheEnabled($value)
    {
        $this->cache_enabled = \exface\Core\DataTypes\BooleanDataType::parse($value);
        return $this;
    }

    public function getCacheIgnoreHeaders()
    {
        return $this->cache_ignore_headers;
    }

    /**
     * Makes all requests get cached regardless of their headers.
     * Default: FALSE.
     *
     * If set to TRUE, this automatically sets the default cache lifetime to 60 seconds. Use
     * "cache_lifetime_in_seconds" to specify a custom value.
     *
     * @uxon-property cache_ignore_headers
     * @uxon-type boolean
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheIgnoreHeaders($value)
    {
        $this->cache_ignore_headers = \exface\Core\DataTypes\BooleanDataType::parse($value);
        if ($this->getCacheIgnoreHeaders()) {
            $this->setCacheLifetimeInSeconds(60);
        }
        return $this;
    }

    public function getCacheLifetimeInSeconds()
    {
        return $this->cache_lifetime_in_seconds;
    }

    /**
     * Sets the default lifetime for request cache items.
     *
     * @uxon-property cache_lifetime_in_seconds
     * @uxon-type number
     *
     * @param integer $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheLifetimeInSeconds($value)
    {
        $this->cache_lifetime_in_seconds = intval($value);
        return $this;
    }

    protected function getCacheAbsolutePath()
    {
        $path = Filemanager::pathJoin(array(
            $this->getWorkbench()->filemanager()->getPathToCacheFolder(),
            $this->getAliasWithNamespace()
        ));
        if (! file_exists($path)) {
            mkdir($path);
        }
        return $path;
    }
}
?>