<?php namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnectorWithoutTransactions;
use exface\Core\Exceptions\DataConnectionError;
use GuzzleHttp\Psr7\Response;

class FileUriConnector extends AbstractDataConnectorWithoutTransactions {
	
	protected $last_error = null;
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_connect()
	 */
	protected function perform_connect() {
		return;
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_disconnect()
	 */
	protected function perform_disconnect() {
		return;
	}
	

	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_query()
	 */
	protected function perform_query($query, $options = null) {		
		/* @var $query \exface\UrlDataConnector\Psr7DataQuery */
		if (!$file_path = $query->get_request()->getUri()->__toString()){
			return array();
		}
		
		if ($question_mark = strpos($file_path, '?')){
			$file_path = substr($file_path, 0, $question_mark);
		}
		
		if(!$this->get_workbench()->filemanager()->isAbsolutePath($file_path)){
			$file_path = $this->get_workbench()->get_installation_path() . DIRECTORY_SEPARATOR . $file_path;
		}
		
		if (!file_exists($file_path)){
			$error = 'File not found: ' . $file_path;
			$this->last_error = $error;
			throw new DataConnectionError($error);
		}
		
		$file_contents = file_get_contents($file_path);
		
		$response = new Response();
		$response->withBody($file_contents);
		return $response;
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
	  
}
?>