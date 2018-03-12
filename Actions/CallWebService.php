<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use Psr\Http\Message\RequestInterface;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;

class CallWebService extends AbstractAction {
    private $outputObject = null;
    
    private $outputObjectAlias = null;
    
    private $url = null;
    
    private $url_params = [];
    
    private $method = null;
    
    private $headers = [];
    
    private $body = null;
    
    /**
     * 
     * @return MetaObjectInterface
     */
    public function getOutputObject()
    {
        if (is_null($this->outputObject)) {
            if (! is_null($this->outputObjectAlias)) {
                return $this->getWorkbench()->model()->getObject($this->outputObjectAlias);
            } else {
                return $this->getInputDataPreset()->getMetaObject();
            }
        }
        return $this->outputObject;
    }

    /**
     * 
     * @param MetaObjectInterface $object
     * @return CallWebService
     */
    public function setOutputObject(MetaObjectInterface $object)
    {
        $this->outputObject = $object;
        return $this;
    }
    
    /**
     * @uxon-property method
     * @uxon-type string
     * 
     * @param string $aliasWithNamespace
     * @return CallWebService
     */
    public function setOutputObjectAlias($aliasWithNamespace)
    {
        $this->outputObjectAlias = $aliasWithNamespace;
        return $this;
    }

    /**
     * 
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * 
     * @uxon-property method
     * @uxon-type string
     * 
     * @param string $url
     * @return CallWebService
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * 
     * @return array
     */
    public function getUrlParams()
    {
        return $this->url_params;
    }

    /**
     * 
     * @uxon-property method
     * @uxon-type array
     * 
     * @param UxonObject|array
     */
    public function setUrlParams($uxon_or_array)
    {
        if ($uxon_or_array instanceof UxonObject) {
            $this->url_params = $uxon_or_array->toArray();
        } elseif (is_array($uxon_or_array)) {
            $this->url_params = $uxon_or_array;
        } else {
            throw new ActionConfigurationError($this, 'Invalid format for url_params property of action ' . $this->getAliasWithNamespace() . ': expecting UXON or PHP array, ' . gettype($uxon_or_array) . ' received.');
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * 
     * @uxon-property method
     * @uxon-type string
     * 
     * @param string
     */
    public function setMethod($method)
    {
        $this->method = $method;
        return $this;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * 
     * @uxon-property method
     * @uxon-type string
     * 
     * @param UxonObject|array $uxon_or_array
     */
    public function setHeaders($uxon_or_array)
    {
        if ($uxon_or_array instanceof UxonObject) {
            $this->headers = $uxon_or_array->toArray();
        } elseif (is_array($uxon_or_array)) {
            $this->headers = $uxon_or_array;
        } else {
            throw new ActionConfigurationError($this, 'Invalid format for headers property of action ' . $this->getAliasWithNamespace() . ': expecting UXON or PHP array, ' . gettype($uxon_or_array) . ' received.');
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @uxon-property method
     * @uxon-type string
     * 
     * @param string $body
     * @return $this;
     */
    public function setBody($body)
    {
        $this->body = $body;
        return $this;
    }
    
    /**
     * 
     * @return UriInterface
     */
    protected function getUri()
    {
        $uri = new Uri($this->getUrl());
        return $uri;
    }
    
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $input_data = $this->getInputDataSheet($task);
        $uri = $this->getUri();
        
        $static_params = '';
        $row_params = [];
        foreach ($this->getUrlParams() as $param => $value) {
            $is_static = true;
            foreach (StringDataType::findPlaceholders($value) as $ph) {
                $is_static = false;
                if ($col = $input_data->getColumns()->get($ph)) {
                    foreach ($col->getValues(false) as $row => $ph_val) {
                        if (! array_key_exists($row, $row_params)) {
                            $row_params[$row] = [];
                        }
                        if (! array_key_exists($param, $row_params[$row])) {
                            $row_params[$row][$param] = $value;
                        }
                        $row_params[$row][$param] = str_replace('[#' . $ph . '#]', $ph_val, $row_params[$row][$param]);
                    }
                }
            }
            
            if ($is_static) {
                $static_params[$param] = $value;
            }
        }
        
        $queries = [];
        $errors = [];
        if (! empty($row_params)) {
            foreach ($row_params as $row => $params) {
                $queries[$row] = array_merge($params, $static_params);
            }
        } else {
            $queries[] = $static_params;
        }
        
        foreach ($queries as $query) {
            $query_string = '';
            foreach ($query as $param => $value) {
                $query_string .= ($query_string ? '&' : '') . urlencode($param) . '=' . urlencode($value);
            }
            $uri = $uri->withQuery($query_string);
            $request = new Request($this->getMethod(), $uri);
            $conn = $input_data->getMetaObject()->getDataConnection();
            if (! ($conn instanceof HttpConnectionInterface)) {
                throw new ActionConfigurationError($this, 'Cannot use data connection "' . $conn->getAliasWithNamespace() . '" with action ' . $this->getAliasWithNamespace() . ': only connectors implementing the HttpConnectionInterface allowed!');
            }
            try {
                $result = $this->send($conn, $request);
            } catch (\Throwable $e) {
                $errors[$e];
            }
        }
        
        return ResultFactory::createMessageResult($task, (count($queries)-count($errors)) . ' requests completed successfully.' . (! empty($errors) ? count($errors) . ' errors' : ''));
    }
    
    protected function send(HttpConnectionInterface $connection, RequestInterface $request)
    {
        return $connection->query(new Psr7DataQuery($request));
    }

}
