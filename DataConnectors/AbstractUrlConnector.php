<?php namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnector;
use exface\Core\CommonLogic\AbstractDataConnectorWithoutTransactions;

/**
 * Connector for Websites, Webservices and other data sources accessible via HTTP, HTTPS, FTP, etc.
 * 
 * @author Andrej Kabachnik
 *
 */
abstract class AbstractUrlConnector extends AbstractDataConnectorWithoutTransactions {
	
	private $url = null;
	
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
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::get_insert_id()
	 */
	function get_insert_id() {
		// TODO
		return 0;
	}

	/**
	 * 
	 */
	function get_affected_rows_count() {
		// TODO
		return 0;
	}

	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::get_last_error()
	 */
	function get_last_error($conn=NULL) {
		if ($this->last_request){
			$error = "Status code " . $this->last_request->getStatusCode() . "\n" . $this->last_request->getBody();
		} else {
			$error = $this->last_error;
		}
		return $error;
	}
	
	public function get_url() {
		if (is_null($this->url)){
			$this->set_url($this->get_config_array()['URL']);
		}
		return $this->url;
	}
	
	/**
	 * Sets the base URL for this connection. It will be prepended to every data address accessed here.
	 * 
	 * If a base URL is set, data addresses of meta objects from this data source should be relative URL!
	 * 
	 * @uxon-property url
	 * @uxon-type string
	 * 
	 * @param string $value
	 * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
	 */
	public function set_url($value) {
		$this->url = $value;
		return $this;
	}          
}
?>