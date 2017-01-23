<?php namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnector;
use GuzzleHttp\Client;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Exceptions\DataSources\DataConnectionQueryTypeError;
use exface\Core\Exceptions\DataSources\DataConnectionFailedError;
use GuzzleHttp\Psr7\Response;

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
		$this->use_cookies = $value ? true : false;
		return $this;
	}
          
}
?>