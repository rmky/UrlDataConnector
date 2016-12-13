<?php namespace exface\UrlDataConnector;

use exface\Core\CommonLogic\AbstractDataQuery;
use exface\Core\CommonLogic\QueryBuilder\AbstractQueryBuilder;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Psr7DataQuery extends AbstractDataQuery  {
	
	private $request;
	private $response;
	
	public function __construct(RequestInterface $request){
		$this->set_request($request);
	}
	
	/**
	 * 
	 * @return \Psr\Http\Message\RequestInterface
	 */
	public function get_request() {
		return $this->request;
	}
	
	/**
	 * 
	 * @param RequestInterface $value
	 * @return \exface\UrlDataConnector\Psr7DataQuery
	 */
	public function set_request(RequestInterface $value) {
		$this->request = $value;
		return $this;
	}
	
	/**
	 * 
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	public function get_response() {
		return $this->response;
	}
	
	/**
	 * 
	 * @param ResponseInterface $value
	 * @return \exface\UrlDataConnector\Psr7DataQuery
	 */
	public function set_response(ResponseInterface $value) {
		$this->response = $value;
		return $this;
	}  
	 
}