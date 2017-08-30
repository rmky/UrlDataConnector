<?php
namespace exface\UrlDataConnector;

use exface\Core\CommonLogic\DataQueries\AbstractDataQuery;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;
use exface\Core\Widgets\DebugMessage;
use exface\Core\Factories\WidgetFactory;
use exface\Core\CommonLogic\Workbench;

class Psr7DataQuery extends AbstractDataQuery
{

    private $request;

    private $response;

    const MIME_TYPE_JSON = 'application/json';
    
    /**
     * Returns a fully instantiated data query with a PSR-7 request.
     * This is a shortcut for "new Psr7DataQuery(new Request)".
     *
     * @param string $method            
     * @param string|UriInterface $uri            
     * @param array $headers            
     * @param string|StreamInterface $body            
     * @param string $version            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public static function createRequest($method, $uri, array $headers = [], $body = null, $version = '1.1')
    {
        $request = new Request($method, $uri, $headers, $body, $version);
        return new self($request);
    }

    /**
     * Wraps a PSR-7 request in a data query, which can be used with the HttpDataConnector
     *
     * @param RequestInterface $request            
     */
    public function __construct(RequestInterface $request)
    {
        $this->setRequest($request);
    }

    /**
     *
     * @return \Psr\Http\Message\RequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     *
     * @param RequestInterface $value            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public function setRequest(RequestInterface $value)
    {
        $this->request = $value;
        return $this;
    }

    /**
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     *
     * @param ResponseInterface $value            
     * @return \exface\UrlDataConnector\Psr7DataQuery
     */
    public function setResponse(ResponseInterface $value)
    {
        $this->response = $value;
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\DataQueries\AbstractDataQuery::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debug_widget)
    {
        $page = $debug_widget->getPage();
        
        $request_tab = $debug_widget->createTab();
        $request_tab->setCaption('Request');
        
        // Request-Headers
        $request_headers_widget = WidgetFactory::create($page, 'Html', $request_tab);
        $request_headers_widget->setValue('<div style="padding:10px;"><h3>HTTP-Headers</h3>' . $this->generateRequestHeaders($debug_widget->getWorkbench()) . '</div>');
        $request_headers_widget->setWidth('100%');
        $request_tab->addWidget($request_headers_widget);
        
        // Request-Body
        $request_body_widget = WidgetFactory::create($page, 'Html', $request_tab);
        $request_body_widget->setValue('<div style="padding:10px;"><h3>HTTP-Body</h3>' . $this->generateMessageBody($debug_widget->getWorkbench(), $this->getRequest()) . '</div>');
        $request_body_widget->setWidth('100%');
        
        $request_tab->addWidget($request_body_widget);
        $debug_widget->addTab($request_tab);
        
        $response_tab = $debug_widget->createTab();
        $response_tab->setCaption('Response');
        
        // Response-Headers
        $response_headers_widget = WidgetFactory::create($page, 'Html', $response_tab);
        $response_headers_widget->setValue('<div style="padding:10px;"><h3>HTTP-Headers</h3>' . $this->generateResponseHeaders($debug_widget->getWorkbench()) . '</div>');
        $response_headers_widget->setWidth('100%');
        $response_tab->addWidget($response_headers_widget);
        
        // Response-Body
        $response_body_widget = WidgetFactory::create($page, 'Html', $response_tab);
        $response_body_widget->setValue('<div style="padding:10px;"><h3>HTTP-Body</h3>' . $this->generateMessageBody($debug_widget->getWorkbench(), $this->getResponse()) . '</div>');
        $response_body_widget->setWidth('100%');
        
        $response_tab->addWidget($response_body_widget);
        $debug_widget->addTab($response_tab);
        
        return $debug_widget;
    }

    // TODO Translations
    
    /**
     * Generates a HTML-representation of the request-headers.
     * 
     * @param Workbench $workbench
     * @return string
     */
    protected function generateRequestHeaders(Workbench $workbench) {
        if (!is_null($this->getRequest())) {
            try {
                $requestHeaders = $this->getRequest()->getMethod() . ' ' . $this->getRequest()->getRequestTarget() . ' HTTP/' . $this->getRequest()->getProtocolVersion();
                $requestHeaders .= $this->generateMessageHeaders($workbench, $this->getRequest());
            } catch (\Throwable $e) {
                $requestHeaders = 'Error reading message headers.';
            }
        } else {
            $requestHeaders = 'Message empty.';
        }
        
        return $requestHeaders;
    }
    
    /**
     * Generates a HTML-representation of the response-headers.
     * 
     * @param Workbench $workbench
     * @return string
     */
    protected function generateResponseHeaders(Workbench $workbench)
    {
        if (!is_null($this->getResponse())) {
            try {
                $responseHeaders = 'HTTP/' . $this->getResponse()->getProtocolVersion() . ' ' . $this->getResponse()->getStatusCode() . ' ' . $this->getResponse()->getReasonPhrase();
                $responseHeaders .= $this->generateMessageHeaders($workbench, $this->getResponse());
            } catch (\Throwable $e) {
                $responseHeaders = 'Error reading message headers.';
            }
        } else {
            $responseHeaders = 'Message empty.';
        }
        
        return $responseHeaders;
    }

    /**
     * Generates a HTML-representation of the request or response headers.
     * 
     * @return string
     */
    protected function generateMessageHeaders(Workbench $workbench, $message)
    {
        if (! is_null($message)) {
            try {
                $messageHeaders = '<table>';
                foreach ($message->getHeaders() as $header => $values) {
                    // Der Authorization-Header sollte weder angezeigt noch geloggt werden.
                    if (! ($header == 'Authorization')) {
                        foreach ($values as $value) {
                            $messageHeaders .= '<tr><td>' . $header . ': </td><td>' . $value . '</td></tr>';
                        }
                    }
                }
                $messageHeaders .= '</table>';
            } catch (\Throwable $e) {
                $messageHeaders = 'Error reading message headers.';
            }
        } else {
            $messageHeaders = 'Message empty.';
        }
        
        return $messageHeaders;
    }

    /**
     * Generates a HTML-representation of the request or response body.
     * 
     * @param Workbench $workbench
     * @return string
     */
    protected function generateMessageBody(Workbench $workbench, $message)
    {
        if (! is_null($message)) {
            try {
                if (is_null($bodySize = $message->getBody()->getSize()) || $bodySize > 1048576) {
                    // Groesse des Bodies unbekannt oder groesser 1Mb.
                    $messageBody = 'Message body is too big to display.';
                } else {
                    foreach ($message->getHeader('Content-Type') as $messageContentType) {
                        switch (true) {
                            case (stripos($messageContentType, $this::MIME_TYPE_JSON) !== false):
                                $contentType = $this::MIME_TYPE_JSON;
                        }
                    }
                    
                    switch ($contentType) {
                        case $this::MIME_TYPE_JSON:
                            $messageBody = '<pre>' . $workbench->getDebugger()->printVariable(json_decode($message->getBody()->__toString()), true, 4) . '</pre>';
                            break;
                        default:
                            $messageBody = urldecode(html_entity_decode(strip_tags($message->getBody())));
                    }
                }
            } catch (\Throwable $e) {
                $messageBody = 'Error reading message body.';
            }
        } else {
            $messageBody = 'Message empty.';
        }
        
        return $messageBody;
    }
}