<?php namespace exface\UrlDataConnector;

use exface\Core\CommonLogic\AbstractDataQuery;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;

class Psr7DataQuery extends AbstractDataQuery  {
	
	private $request;
	private $response;
	
	/**
	 * Returns a fully instantiated data query with a PSR-7 request. This is a shortcut for "new Psr7DataQuery(new Request)".
	 * 
	 * @param string $method
	 * @param string|UriInterface $uri
	 * @param array $headers
	 * @param string|StreamInterface $body
	 * @param string $version
	 * @return \exface\UrlDataConnector\Psr7DataQuery
	 */
	public static function create_request($method, $uri, array $headers = [], $body = null, $version = '1.1'){
		$request = new Request($method, $uri, $headers, $body, $version);
		return new self($request);
	}
	
	/**
	 * Wraps a PSR-7 request in a data query, which can be used with the HttpDataConnector
	 * 
	 * @param RequestInterface $request
	 */
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