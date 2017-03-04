<?php namespace exface\UrlDataConnector\DataConnectors;

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
class HttpConnector extends AbstractUrlConnector {
	
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
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_connect()
	 */
	protected function perform_connect() {		
		$defaults = array();
		$defaults['verify'] = false;
		$defaults['base_uri'] = $this->get_url();
		// Proxy settings
		if ($this->get_proxy()){
			$defaults['proxy'] = $this->get_proxy();
		}
		
		// Basic authentication
		if ($this->get_user()){
			$defaults['auth'] = array($this->get_user(), $this->get_password());
		}
		
		// Cookies
		if ($this->get_use_cookies()){
			$cookieFile = str_replace(array(':', '/', '.') , '', $this->get_url()) . '.cookie';
			$cookieDir = $this->get_workbench()->context()->get_scope_user()->get_user_data_folder_absolute_path() . DIRECTORY_SEPARATOR . 'cookies';
			if (!file_exists($cookieDir)){
				mkdir($cookieDir);
			}
			$cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieDir . DIRECTORY_SEPARATOR . $cookieFile);
			$defaults['cookies'] = $cookieJar;
		}
		
		// Cache
		if ($this->get_cache_enabled()){
			// Create default HandlerStack
			$stack = HandlerStack::create();
			
			if ($this->get_cache_ignore_headers()){
				$cache_strategy_class = '\\Kevinrob\\GuzzleCache\\Strategy\\GreedyCacheStrategy';
			} else {
				$cache_strategy_class = '\\Kevinrob\\GuzzleCache\\Strategy\\PrivateCacheStrategy';
			}
			
			// Add cache middleware to the top with `push`
			$stack->push(
				new CacheMiddleware(
					new $cache_strategy_class (
						new Psr6CacheStorage(
							new FilesystemAdapter('', 0, $this->get_cache_absolute_path())
						),
						$this->get_cache_lifetime_in_seconds()
					)
				),
				'cache'
			);
			
			// Initialize the client with the handler option
			$defaults['handler'] = $stack;
		}
		
		try {
			$this->client = new Client($defaults);
		} catch (\Throwable $e){
			throw new DataConnectionFailedError($this, "Failed to instantiate HTTP client: " . $e->getMessage(), '6T4RAVX', $e);
		}
 
	}
	

	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_query()
	 * 
	 * @param Psr7DataQuery $query
	 * @return Psr7DataQuery
	 */
	protected function perform_query(DataQueryInterface $query) {
		if (!($query instanceof Psr7DataQuery)) throw new DataConnectionQueryTypeError($this, 'Connector "' . $this->get_alias_with_namespace() . '" expects a Psr7DataQuery as input, "' . get_class($query) . '" given instead!');
		/* @var $query \exface\UrlDataConnector\Psr7DataQuery */
		if (!$query->get_request()->getUri()->__toString()){
			$query->set_response(new Response());
		} else {
			if (!$this->client) {
				$this->connect();
			}
			$query->set_response($this->client->send($query->get_request()));
		}
		return $query;
	}

	function get_insert_id() {
		// TODO
		return 0;
	}
	
	public function get_user() {
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
	public function set_user($value) {
		$this->user = $value;
		return $this;
	}
	
	public function get_password() {
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
	public function set_password($value) {
		$this->password = $value;
		return $this;
	}
	
	/**
	 * Returns the proxy address to be used in the name:port notation: e.g. 192.169.1.10:8080 or myproxy:8080.
	 * @return string
	 */
	public function get_proxy() {
		return $this->proxy;
	}
	
	/**
	 * Sets the proxy server address to be used. Use name:port notation like 192.169.1.10:8080 or myproxy:8080.
	 *
	 * @uxon-property proxy
	 * @uxon-type string
	 *
	 * @param string $value
	 * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
	 */
	public function set_proxy($value) {
		$this->proxy = $value;
		return $this;
	}
	
	/**
	 * 
	 * @return boolean
	 */
	public function get_use_cookies() {
		return $this->use_cookies;
	}
	
	/**
	 * Set to TRUE to use cookies for this connection. Defaults to FALSE.
	 * 
	 * Cookies will be stored in the data folder of the current user!
	 *
	 * @uxon-property use_cookies
	 * @uxon-type boolean
	 *
	 * @param boolean $value
	 * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
	 */
	public function set_use_cookies($value) {
		$this->use_cookies = \exface\Core\DataTypes\BooleanDataType::parse($value);
		return $this;
	}
	
	public function get_cache_enabled() {
		return $this->cache_enabled;
	}
	
	/**
	 * Enables or disables caching of HTTP requests. Default: FALSE.
	 * 
	 * @uxon-property cache_enabled
	 * @uxon-type boolean
	 * 
	 * @param boolean $value
	 * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
	 */
	public function set_cache_enabled($value) {
		$this->cache_enabled = \exface\Core\DataTypes\BooleanDataType::parse($value);
		return $this;
	}
	
	public function get_cache_ignore_headers() {
		return $this->cache_ignore_headers;
	}
	
	/**
	 * Makes all requests get cached regardless of their headers. Default: FALSE.
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
	public function set_cache_ignore_headers($value) {
		$this->cache_ignore_headers = \exface\Core\DataTypes\BooleanDataType::parse($value);
		if ($this->get_cache_ignore_headers()){
			$this->set_cache_lifetime_in_seconds(60);
		}
		return $this;
	}  
	
	public function get_cache_lifetime_in_seconds() {
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
	public function set_cache_lifetime_in_seconds($value) {
		$this->cache_lifetime_in_seconds = intval($value);
		return $this;
	}  
	
	protected function get_cache_absolute_path(){
		$path = Filemanager::path_join(array($this->get_workbench()->filemanager()->get_path_to_cache_folder(), $this->get_alias_with_namespace()));
		if (!file_exists($path)){
			mkdir($path);
		}
		return $path;
	}
}
?>