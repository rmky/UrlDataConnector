<?php namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\Exceptions\DataConnectionError;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7;
use exface\UrlDataConnector\Psr7DataQuery;

class FileUriConnector extends AbstractUrlConnector {
	
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
	 * @see \exface\Core\CommonLogic\AbstractDataConnector::perform_query()
	 * 
	 * @param $query Psr7DataQuery
	 * @return Psr7DataQuery
	 */
	protected function perform_query($query, $options = null) {		
		if (!($query instanceof Psr7DataQuery)) throw new DataConnectionError('Connector "' . $this->get_alias_with_namespace() . '" expects a Psr7DataQuery as input, "' . get_class($query) . '" given instead!');
		
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
		
		$query->set_response(new Response(200, array(), Psr7\stream_for(fopen($file_path, 'r'))));
		return $query;
	}  
}
?>