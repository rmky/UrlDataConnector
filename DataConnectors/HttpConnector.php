<?php namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\Exceptions\DataSourceError;
use exface\Core\CommonLogic\AbstractDataConnectorWithoutTransactions;
/* Datbase API object of Microsoft SQL Server
 * Written by Andrej Kabachnik, 2015
 *
 */

class HttpConnector extends AbstractDataConnectorWithoutTransactions {
	const XML = 'XML';
	const JSON = 'JSON';
	const PUT = 'PUT';
	const POST = 'POST';
	const GET = 'GET';
	const DELETE = 'DELETE';
	
	protected $client;
	protected $last_request = null;
	
	private $response_type = null;
	private $last_error = null;
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_connect()
	 */
	protected function perform_connect($url = '', $user = '', $pwd = '', $proxy = null, $charset = null) {
		$url = $url ? $url : $this->get_config_array()['URL'];
		$user = $user ? $user : $this->get_config_array()['user'];
		$pwd = $pwd ? $pwd : $this->get_config_array()['password'];
		$proxy = $proxy ? $proxy : $this->get_config_array()['proxy'];
		$charset = $charset ? $charset : $this->get_config_array()['encoding'];
		
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
	protected function perform_query($uri, $request_type = 'GET', $body = null, $body_format = null) {
		if (!$uri){
			return array();
		}
		
		if (!$this->client) {
			$this->connect();
		}
		
		switch ($request_type){
			case $this::POST:
				try {
					$this->last_request = $this->client->post($uri, array(($body_format ? strtolower($body_format) : 'body') => $body));
				} catch (\GuzzleHttp\Exception\ServerException $e){
					$this->last_error = $e->getMessage();
					if (!$this->get_config_array()['ignore_errors_on_post']){
						throw new DataSourceError($e->getMessage());
					}
				}
				break;
			case $this::PUT:
				try {
					$this->last_request = $this->client->put($uri);
				} catch (\GuzzleHttp\Exception\ServerException $e){
					$this->last_error = $e->getMessage();
					if (!$this->get_config_array()['ignore_errors_on_put']){
						throw new DataSourceError($e->getMessage());
					}
				}
				break;
			case $this::DELETE:
				try {
					$this->last_request = $this->client->delete($uri);
				} catch (\GuzzleHttp\Exception\ServerException $e){
					$this->last_error = $e->getMessage();
					if (!$this->get_config_array()['ignore_errors_on_delete']){
						throw new DataSourceError($e->getMessage());
					}
				}
				break;
			default:
				try {
					$this->last_request = $this->client->get($uri);
				} catch (\GuzzleHttp\Exception\ServerException $e){
					$this->last_error = $e->getMessage();
					throw new DataSourceError($e->getMessage());
				}
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
			} else {
				$this->set_response_type($this::JSON);
			}
		}
		
		if ($this->get_response_type() == $this::XML){
			return $rs->xml();
		} else {
			return $rs->json();
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