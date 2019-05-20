<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use GuzzleHttp\Psr7\Request;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Actions\iCallService;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\Factories\DataSheetFactory;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Factories\DataSourceFactory;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Actions\ActionInputMissingError;

/**
 * Calls a generic web service using parameters to fill placeholders in the URL and body.
 * 
 * @author Andrej Kabachnik
 *
 */
class CallWebService extends AbstractAction implements iCallService 
{
    
    private $parameters = [];
    
    private $parametersGeneratedFromPlaceholders = false;
    
    private $url = null;
    
    private $method = null;
    
    private $headers = [];
    
    private $body = null;
    
    private $serviceName = null;
    
    private $dataSource = null;
    
    private $resultMessagePattern = null;

    /**
     * 
     * @return string|NULL
     */
    protected function getUrl() : ?string
    {
        return $this->url;
    }

    /**
     * The URL to call: absolute or relative to the data source.
     * 
     * If the data source is not specified directly via `data_source_alias`, the data source
     * of the action's meta object will be used.
     * 
     * @uxon-property url
     * @uxon-type url
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
     * @return string
     */
    protected function getMethod($default = 'GET') : string
    {
        return $this->method ?? $default;
    }

    /**
     * The HTTP method: GET, POST, etc.
     * 
     * @uxon-property method
     * @uxon-type [GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD,TRACE]
     * @uxon-default GET
     * 
     * @param string
     */
    public function setMethod(string $method) : CallWebService
    {
        $this->method = $method;
        return $this;
    }

    /**
     * 
     * @return array
     */
    protected function getHeaders() : array
    {
        return $this->headers;
    }
    
    /**
     * 
     * @return array
     */
    protected function buildHeaders() : array
    {
        return $this->getHeaders();
    }

    /**
     * Special HTTP headers to be sent: these headers will override the defaults of the data source.
     * 
     * @uxon-property headers
     * @uxon-type object
     * @uxon-template {"Content-Type": ""}
     * 
     * @param UxonObject|array $uxon_or_array
     */
    public function setHeaders($uxon_or_array) : CallWebService
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
     * Replaces body placeholders with parameter values from the given data sheet row.
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildBody(DataSheetInterface $data, int $rowNr) : string
    {
        $body = $this->getBody();
        
        if ($body === null) {
            return '';
        }
        
        $placeholders = StringDataType::findPlaceholders($body);
        if (empty($placeholders) === true) {
            return $body;
        }
        
        $requiredParams = [];
        foreach ($placeholders as $ph) {
            $requiredParams[] = $this->getParameter($ph);
        }
        $data = $this->getDataWithParams($data, $requiredParams);
        
        $phValues = [];
        foreach ($requiredParams as $param) {
            $name = $param->getName();
            $val = $data->getCellValue($name, $rowNr);
            $val = $this->prepareParamValue($param, $val);
            $phValues[$name] = $val;
        }
        
        return StringDataType::replacePlaceholders($body, $phValues);
    }

    /**
     * 
     * @return string
     */
    protected function getBody() : ?string
    {
        return $this->body;
    }

    /**
     * The body of the HTTP request; [#placeholders#] are supported.
     * 
     * @uxon-property body
     * @uxon-type string
     * 
     * @param string $body
     * @return $this;
     */
    public function setBody($body) : CallWebService
    {
        $this->body = $body;
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        $input = $this->getInputDataSheet($task);
        
        $resultData = DataSheetFactory::createFromObject($this->getResultObject());
        $resultData->setAutoCount(false);
        
        $rowCnt = $input->countRows();
        for ($i = 0; $i < $rowCnt; $i++) {
            $request = new Request($this->getMethod(), $this->buildUrl($input, $i), $this->buildHeaders(), $this->buildBody($input, $i));
            $query = new Psr7DataQuery($request);
            $response = $this->getDataConnection()->query($query)->getResponse();
            try {
                $resultData = $this->parseResponse($response, $resultData);
            } catch (\Throwable $e) {
                throw new DataQueryFailedError($query, $e->getMessage(), null, $e);
            }
        }
        
        $resultData->setCounterForRowsInDataSource($resultData->countRows());
        $message = $this->getMessageFromResponse($response) ?? $this->getResultMessageText() ?? $this->getWorkbench()->getApp('exface.SapConnector')->getTranslator()->translate('ACTION.CALLODATA2OPERATION.SUCCESS');
        
        return ResultFactory::createDataResult($task, $resultData, $message);
    }
    
    /**
     * 
     * @return HttpConnectionInterface
     */
    protected function getDataConnection() : HttpConnectionInterface
    {
        if ($this->dataSource !== null) {
            if (! $this->dataSource instanceof DataSourceInterface) {
                $this->dataSource = DataSourceFactory::createFromModel($this->getWorkbench(), $this->dataSource);
            }
            return $this->dataSource;
        }
        return $this->getMetaObject()->getDataConnection();
    }
    
    /**
     * Use this the connector of this data source to call the web service.
     * 
     * @uxon-property data_source_alias
     * @uxon-type metamodel:datasource
     * 
     * @param string $idOrAlias
     * @return CallWebService
     */
    public function setDataSourceAlias(string $idOrAlias) : CallWebService
    {
        $this->dataSource = $idOrAlias;
        return $this;
    }
    
    /**
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildUrl(DataSheetInterface $data, int $rowNr) : string
    {
        $url = $this->getUrl();
        
        if ($this->getMethod() === 'GET') {
            $url = $this->buildUrlParams($url, $data, $rowNr);
        }
        
        return $url;
    }
    
    /**
     * 
     * @param DataSheetInterface $data
     * @return DataSheetInterface
     */
    protected function getDataWithParams(DataSheetInterface $data, array $parameters) : DataSheetInterface
    {
        foreach ($parameters as $param) {
            if (! $data->getColumns()->get($param->getName())) {
                if ($data->getMetaObject()->hasAttribute($param->getName()) === true) {
                    if ($data->hasUidColumn(true) === true) {
                        $attr = $data->getMetaObject()->getAttribute($param->getName());
                        $data->getColumns()->addFromAttribute($attr);
                    }
                }
            }
        }
        if ($data->isFresh() === false && $data->hasUidColumn(true)) {
            $data->addFilterFromColumnValues($data->getUidColumn());
            $data->dataRead();
        }
        return $data;
    }
    
    /**
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildUrlParams(string $url, DataSheetInterface $data, int $rowNr) : string
    {
        $params = '';
        $urlPlaceholders = StringDataType::findPlaceholders($url);
        $data = $this->getDataWithParams($data, $this->getParameters());        
        
        $urlPhValues = [];
        foreach ($this->getParameters() as $param) {
            $name = $param->getName();
            $val = $data->getCellValue($name, $rowNr);
            $val = $this->prepareParamValue($param, $val);
            if (in_array($param->getName(), $urlPlaceholders) === true) {
                $urlPhValues[$name] = $val;
            }
            $params .= '&' . $name . '=' . $val;
        }
        if (empty($urlPhValues) === false) {
            $url = StringDataType::replacePlaceholders($url, $urlPhValues);
        }
        
        return $url . (strpos($url, '?') === false ? '?' : '') . $params;
    }
    
    /**
     * 
     * @param ServiceParameterInterface $parameter
     * @param mixed $val
     * @return string
     */
    protected function prepareParamValue(ServiceParameterInterface $parameter, $val) : string
    {
        return $parameter->getDataType()->parse($val);
    }
    
    /**
     *
     * @return ServiceParameterInterface[]
     */
    public function getParameters() : array
    {
        if ($this->parametersGeneratedFromPlaceholders === false) {
            $this->parametersGeneratedFromPlaceholders = true;
            $phs = array_merge(StringDataType::findPlaceholders($this->getUrl()), StringDataType::findPlaceholders($this->getBody()));
            foreach ($phs as $ph) {
                try {
                    $this->getParameter($ph);
                } catch (ActionInputMissingError $e) {
                    $this->parameters[] = new ServiceParameter($this, new UxonObject([
                        "name" => $ph,
                        "required" => true
                    ]));
                }
            }
        }
        return $this->parameters;
    }
    
    /**
     * Defines parameters supported by the service.
     *
     * @uxon-property parameters
     * @uxon-type \exface\Core\CommonLogic\Actions\ServiceParameter[]
     * @uxon-template [{"name": ""}]
     *
     * @param UxonObject $value
     * @return CallWebService
     */
    public function setParameters(UxonObject $uxon) : CallWebService
    {
        foreach ($uxon as $paramUxon) {
            $this->parameters[] = new ServiceParameter($this, $paramUxon);
        }
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallService::getParameter()
     */
    public function getParameter(string $name) : ServiceParameterInterface
    {
        foreach ($this->getParameters() as $arg) {
            if ($arg->getName() === $name) {
                return $arg;
            }
        }
        throw new ActionInputMissingError($this, 'Parameter "' . $name . '" not found in action "' . $this->getAliasWithNamespace() . '"!');
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallService::getServiceName()
     */
    public function getServiceName() : string
    {
        return $this->getUrl();
    }
    
    /**
     * 
     * @param ResponseInterface $response
     * @param DataSheetInterface $resultData
     * @return DataSheetInterface
     */
    protected function parseResponse(ResponseInterface $response, DataSheetInterface $resultData) : DataSheetInterface
    {
        return $resultData;
    }
    
    /**
     * 
     * @return MetaObjectInterface
     */
    protected function getResultObject() : MetaObjectInterface
    {
        if ($this->hasResultObjectRestriction()) {
            return $this->getResultObjectExpected();
        }
        return $this->getMetaObject();
    }
    
    /**
     *
     * @return string|NULL
     */
    protected function getResultMessagePattern() : ?string
    {
        return $this->resultMessagePattern;
    }
    
    /**
     * A regular expression to retrieve the result message from the body.
     * 
     * Extracts a result message from the response body.
     * 
     * For example, if the web service would return the following JSON
     * `{"result": "Everything OK"}`, you could use this regex to get the
     * message: `/"result":"(?<message>[^"]*)"/`.
     * 
     * @uxon-property result_message_pattern
     * @uxon-type string
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setResultMessagePattern(string $value) : CallWebService
    {
        $this->resultMessagePattern = $value;
        return $this;
    }
    
    protected function getMessageFromResponse(ResponseInterface $response) : ?string
    {
        if ($this->getResultMessagePattern() === null) {
            return null;
        }
        
        $body = $response->getBody()->__toString();
        $matches = [];
        preg_match($this->getResultMessagePattern(), $body, $matches);
        
        if (empty($matches)) {
            return null;
        }
        
        return $matches['message'] ?? $matches[1];
    }
    
}
