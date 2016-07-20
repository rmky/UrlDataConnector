<?php namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\Exceptions\DataSourceError;
use exface\Core\CommonLogic\AbstractDataConnectorWithoutTransactions;
/* Datbase API object of Microsoft SQL Server
 * Written by Andrej Kabachnik, 2015
 *
 */

class WebsiteConnector extends AbstractDataConnectorWithoutTransactions {
	
	protected $client;
	protected $last_request = null;
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_connect()
	 */
	protected function perform_connect($url = '', $user = '', $pwd = '', $proxy = null, $charset = null, $use_cookies = false) {
		
		$url = $url ? $url : $this->get_config_array()['URL'];
		$user = $user ? $user : $this->get_config_array()['user'];
		$pwd = $pwd ? $pwd : $this->get_config_array()['password'];
		$proxy = $proxy ? $proxy : $this->get_config_array()['proxy'];
		$charset = $charset ? $charset : $this->get_config_array()['encoding'];
		$use_cookies = ($use_cookies || $this->get_config_array()['use_cookies']) ? true : false;
		
		$defaults = array();
		$defaults['verify'] = false;
		// Proxy settings
		if ($proxy){
			$defaults['proxy'] = $proxy;
		}
		// Basic authentication
		if ($user){
			$defaults['auth'] = array($user, $pwd);
		}
		// Cookies
		if ($use_cookies){
			$cookieFile = str_replace(array(':', '/', '.') , '', $url) . '.cookie';
			$cookieDir = $this->get_workbench()->context()->get_scope_user()->get_user_data_folder_absolute_path() . DIRECTORY_SEPARATOR . 'cookies';
			if (!file_exists($cookieDir)){
				mkdir($cookieDir);
			}
			$cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieDir . DIRECTORY_SEPARATOR . $cookieFile);
			$defaults['cookies'] = $cookieJar;
		}
		
		$this->client = new \GuzzleHttp\Client(['base_url' => $url, 'defaults' => $defaults]);
		
		if (!$this->client) {
			throw new DataSourceError("Failed to create the database connection! " . $this->get_last_error());
		} 
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_disconnect()
	 */
	protected function perform_disconnect() {
		// TODO	
	}
	

	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_query()
	 */
	protected function perform_query($uri, $options = null) {
		
		if (!$uri){
			return array();
		}
		
		if (!$this->client) {
			$this->connect();
		}
		
		// Strip off the base url from the given uri if it is there. This way, it is possible to use a placeholder with a
		// complete URL as the objects data address and it will still get processed as a relative path to the base URL
		if (strpos($uri, $this->get_config_array()['URL']) === 0){
			$uri = substr($uri, strlen($this->get_config_array()['URL']));
		}
		
		if (!$this->last_request = $this->client->get($uri)) {
			throw new DataSourceError("Execution of a query to the database failed - " . $this->get_last_error(), $uri);
		}
		
		return $this->make_array($this->last_request);
	}

	function get_insert_id() {
		// TODO
		return 0;
	}

	/**
	 * @name:  get_affected_rows_count
	 *
	 */
	function get_affected_rows_count() {
		// TODO
		return 0;
	}

	/**
	 * @name:  get_last_error
	 *
	 */
	function get_last_error() {
		if ($this->last_request){
			$error = "Status code " . $this->last_request->getStatusCode() . "\n" . $this->last_request->getBody();
		}
		return $error;
	}

	/**
	* @name:  make_array
	* @desc:  turns a recordset into a multidimensional array
	* @return: an array of row arrays from recordset, or empty array
	*			 if the recordset was empty, returns false if no recordset
	*			 was passed
	* @param: $rs Recordset to be packaged into an array
	*/
	function make_array($rs=''){
		if(!$rs) return false;
		return array(
			0 => array(
				'url' => $rs->getEffectiveUrl(),
				'body' => (string) $rs->getBody(true)
			)
		);
	}  
}
?>