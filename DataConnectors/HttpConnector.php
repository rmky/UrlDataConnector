<?php namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\Exceptions\DataSourceError;
use exface\Core\CommonLogic\AbstractDataConnectorWithoutTransactions;
use GuzzleHttp\Client;
use exface\Core\Exceptions\DataConnectionError;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 
 * @author aka
 *
 */
class HttpConnector extends AbstractDataConnectorWithoutTransactions {
	const XML = 'XML';
	const JSON = 'JSON';
	const PUT = 'PUT';
	const POST = 'POST';
	const GET = 'GET';
	const DELETE = 'DELETE';
	
	/** @var Client */
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
		$defaults['base_uri'] = $url;
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
		
		$this->client = new Client($defaults);
		
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
	protected function perform_query($query, $options = null) {
		/* @var $query \exface\UrlDataConnector\Psr7DataQuery */
		if (!$query->get_request()->getUri()){
			return array();
		}
				
		if (!$this->client) {
			$this->connect();
		}
		return $this->client->send($query->get_request());
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
	function get_last_error($conn=NULL) {
		if ($this->last_request){
			$error = "Status code " . $this->last_request->getStatusCode() . "\n" . $this->last_request->getBody();
		} else {
			$error = $this->last_error;
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
		if (!$this->get_response_type()){
			$rs_string = trim($rs->getBody());
			if (strpos($rs_string, '<') === 0){
				$this->set_response_type($this::XML);
			} elseif ((strpos($rs_string, '[') === 0) || (strpos($rs_string, '{') === 0)) {
				$this->set_response_type($this::JSON);
			} 
		}
		
		switch ($this->get_response_type()){
			case $this::XML: return $rs->xml(); break;
			case $this::JSON: return $rs->json(); break;
			default: return array('body' => (string) $rs->getBody());				
		}
	}  
	
	public function get_response_type() {
		return $this->response_type;
	}
	
	public function set_response_type($value) {
		$this->response_type = $value;
		return $this;
	}  
	
	public function get_current_connection(){
		return $this->client;
	}
}
?>