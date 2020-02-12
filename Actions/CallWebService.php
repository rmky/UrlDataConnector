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
use exface\Core\Factories\DataSheetFactory;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Factories\DataSourceFactory;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\CommonLogic\Constants\Icons;
use exface\UrlDataConnector\Exceptions\HttpConnectorRequestError;

/**
 * Calls a generic web service using parameters to fill placeholders in the URL and body.
 * 
 * This action can fill placeholders in the URL and request body to call a web service.
 * The placeholders are replaced values from the action's input data: the placeholder's
 * name must match one of the data column names of the input data.
 * 
 * For multiple data items (e.g. a table with multi-select), the webservice is called
 * multiple times: once per data row, so to say.
 * 
 * You can customize the result message produced by the action using the following
 * properties:
 * 
 * - `result_message_pattern` - a regular expression to extract the result message from
 * the response - see examples below.
 * - `result_message_text` - a text or a static formula (e.g. `=TRANSLATE()`) to be
 * displayed if no errors occur. 
 * - If `result_message_text` and `result_message_pattern` are both specified, the static
 * text will be prepended to the extracted result. This is usefull for web services, that
 * respond with pure data - e.g. an importer serves, that returns the number of items imported.
 * 
 * Similarly, you can make error messages look for information in the error response
 * if the web service produce more informative errors than the generic errors in the
 * data connectors.
 * 
 * - `error_message_pattern` - a regular expression to find the error message (this will
 * make this error message visible to the users!)
 * - `error_code_pattern` - a regular expression to find the error code (this will
 * make this error code visible to the users!)
 * 
 * ## Examples
 * 
 * ### A simple GET-webservice with a required parameter 
 * 
 * The service returns the following JSON if successfull: `{"result": "Everything OK"}`.
 * 
 * ```
 * {
 *  "url": "http://url.toyouservice.com/service?param1=[#param1_data_column#]",
 *  "result_message_pattern": "\"result":"(?<message>[^"]*)\""
 * 
 * }
 * 
 * ```
 * 
 * The placeholder `[#param1_attribute_alias#]` in the URL will be automatically
 * transformed into a required service parameter, so we don't need to define any
 * `parameters` manually. When the action is performed, the system will look for
 * a data column named `param1_data_column` and use it's values to replace the
 * placeholder. If no such column is there, an error will be raised. 
 * 
 * The `result_message_pattern` will be used to extract the success message from 
 * the response body (i.e. "Everything OK"), that will be shown to the user once 
 * the service responds.
 * 
 * ### A GET-Service with typed and optional parameters
 * 
 * If you need optional URL parameters or require type checking, you can use the
 * `parameters` property of the action to add detailed information about each
 * parameter: in particular, it's data type.
 * 
 * Compared to the first example, the URL here does not have any placeholders.
 * Instead, there is the parameter `param1`, which will produce `&param1=...`
 * in the URL. The value will be expected in the input data column named `param1`.
 * You can use an `input_mapper` in the action's configuration to map a column
 * with a different name to `param1`.
 * 
 * The second parameter is optional and will only be appended to the URL if
 * the input data contains a matching column with non-empty values.
 * 
 * ```
 * {
 *  "url": "http://url.toyouservice.com/service",
 *  "result_message_pattern": "\"result":"(?<message>[^"]*)\"",
 *  "parameters": [
 *      {
 *          "name": "param1",
 *          "required": true,
 *          "data_type": {
 *              "alias": "exface.Core.Integer"
 *          }
 *      },
 *      {
 *          "name": "mode",
 *          "data_type": {
 *              "alias": "exface.Core.GenericStringEnum",
 *              "values": {
 *                  "mode1": "Mode 1",
 *                  "mode2": "Mode 2"
 *              }
 *          }
 *      }
 *  ]
 * }
 * 
 * ```
 * 
 * You can mix placeholders and explicitly defined parameters. In this case, if no parameter
 * name matches a placeholder's name, a new simple string parameter will be generated
 * automatically.
 * 
 * ### A POST-service with a body-template
 * 
 * Similarly to URLs in GET-services, placeholders can be used in the body of the request
 * to a POST-service. Since the generic `CallWebService` only supports plain text body
 * templates, placeholders must be used for every parameter! In contrast to the URL parameter,
 * body parameter cannot be added automatically and, thus, cannot be optional.
 * 
 * The following code shows a POST-version of the first GET-example above.
 * 
 * ```
 * {
 *  "url": "http://url.toyouservice.com/service",
 *  "result_message_pattern": "\"result":"(?<message>[^"]*)\"",
 *  "method": "POST",
 *  "Content-Type": "application/json",
 *  "body": "{\"param1\": \"[#param1_data_column#]\"}"
 * }
 * 
 * ```
 * 
 * Note the extra `Content-Type` header: most web services will require such a header, so it is
 * a good idea to set it in the action's configuration - in this case, the body is a JSON, so
 * we use the default JSON content type.
 * 
 * You can also used the detailed `parameters` definition with POST requests - just make sure,
 * the placeholder name matches the parameter name. Placeholders, that are not in the `parameters`
 * list will be automatically treated as additional string parameters.
 * 
 * @author Andrej Kabachnik
 *
 */
class CallWebService extends AbstractAction implements iCallService 
{
    
    /**
     * @var ServiceParameterInterface[]
     */
    private $parameters = [];
    
    /**
     * @var bool
     */
    private $parametersGeneratedFromPlaceholders = false;
    
    /**
     * @var string|NULL
     */
    private $url = null;
    
    /**
     * @var string|NULL
     */
    private $method = null;
    
    /**
     * @var string[]
     */
    private $headers = [];
    
    private $contentType = null;
    
    /**
     * @var string|NULL
     */
    private $body = null;
    
    /**
     * @var string|DataSourceInterface|NULL
     */
    private $dataSource = null;

    /**
     * @var string|NULL
     */
    private $resultMessagePattern = null;
    
    private $errorMessagePattern = null;
    
    private $errorCodePattern = null;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Actions\ShowWidget::init()
     */
    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::COGS);
    }

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
     * @uxon-type uri
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
        $headers = $this->getHeaders();
        
        if ($this->getContentType() !== null) {
            $headers['Content-Type'] = $this->getContentType();
        }
        
        return $headers;
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
     * Populates the request body with parameters from a given row by replaces body placeholders 
     * (if a body-template was specified) or creating a body according to the content type.
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildBody(DataSheetInterface $data, int $rowNr) : string
    {
        $body = $this->getBody();
        
        if ($body === null) {
            return $this->buildBodyFromParameters($data, $rowNr);
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
     * Returns the request body built from service parameters according to the content type.
     * 
     * @param DataSheetInterface $data
     * @param int $rowNr
     * @return string
     */
    protected function buildBodyFromParameters(DataSheetInterface $data, int $rowNr) : string
    {
        $str = '';
        $contentType = $this->getContentType();
        switch (true) {
            case stripos($contentType, 'json') !== false:
                $params = [];
                foreach ($this->getParameters() as $param) {
                    $name = $param->getName();
                    $val = $data->getCellValue($name, $rowNr);
                    $val = $this->prepareParamValue($param, $val);
                    $params[$name] = $val;
                }
                $str = json_encode($params);
                break;
            case strcasecmp($contentType, 'application/x-www-form-urlencoded') === 0:
                foreach ($this->getParameters() as $param) {
                    $name = $param->getName();
                    $val = $data->getCellValue($name, $rowNr);
                    $val = $this->prepareParamValue($param, $val);
                    $str .= '&' . urlencode($name) . '=' . urlencode($val);
                }
                break;
        }
        return $str;
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
        if ($rowCnt === 0 && $this->getInputRowsMin() === 0) {
            $rowCnt = 1;
        }
        for ($i = 0; $i < $rowCnt; $i++) {
            $request = new Request($this->getMethod(), $this->buildUrl($input, $i), $this->buildHeaders(), $this->buildBody($input, $i));
            $query = new Psr7DataQuery($request);
            try {
                $response = $this->getDataConnection()->query($query)->getResponse();
            } catch (\Throwable $e) {
                if ($eResponse = $this->getErrorResponse($e)) {
                    $message = $this->getErrorMessageFromResponse($eResponse);
                    $code = $this->getErrorCodeFromResponse($eResponse);
                    if ($code === null && $message) {
                        $code = '';
                    }
                    $statusCode = $eResponse->getStatusCode();
                    $reasonPhrase = $eResponse->getReasonPhrase();
                }
                if (! $e instanceof HttpConnectorRequestError) {
                    $ex = new HttpConnectorRequestError($query, $statusCode, $reasonPhrase, $message, $code, $e);
                } else {
                    $ex = new HttpConnectorRequestError($query, $statusCode, $reasonPhrase, $message, $e->getAlias(), $e->getPrevious());
                }
                $ex->setUseRemoteMessageAsTitle(($message !== null ? true : false));
                throw $ex;
            }
        }
        
        $resultData = $this->parseResponse($response, $resultData);
        $resultData->setCounterForRowsInDataSource($resultData->countRows());
        
        // If the input and the result are based on the same meta object, we can (and should!)
        // apply filters and sorters of the input to the result. Indeed, having the same object
        // merely means, we need to fill the sheet with data, which, of course, should adhere
        // to its settings.
        if ($input->getMetaObject()->is($resultData->getMetaObject())) {
            if ($input->getFilters()->isEmpty(true) === false) {
                $resultData = $resultData->extract($input->getFilters());
            }
            if ($input->hasSorters() === true) {
                $resultData->sort($input->getSorters());
            }
        }
        
        if ($this->getResultMessageText() && $this->getResultMessagePattern()) {
            $message = $this->getResultMessageText() . $this->getMessageFromResponse($response);
        } else {
            $message = $this->getResultMessageText() ?? $this->getMessageFromResponse($response);
        }
        
        if ($message === null || $message === '') {
            $message = $this->getWorkbench()->getApp('exface.UrlDataConnector')->getTranslator()->translate('ACTION.CALLWEBSERVICE.DONE');
        }
        
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
            return $this->dataSource->getConnection();
        }
        return $this->getMetaObject()->getDataConnection();
    }
    
    /**
     * Use this the connector of this data source to call the web service.
     * 
     * @uxon-property data_source_alias
     * @uxon-type metamodel:data_source
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
        
        return $url ?? '';
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
            $data->getFilters()->addConditionFromColumnValues($data->getUidColumn());
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
            $params .= '&' . urlencode($name) . '=' . urlencode($val);
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
     * A regular expression to retrieve the result message from the body - the first match is returned or one explicitly named "message".
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
    
    /**
     * 
     * @param ResponseInterface $response
     * @return string|NULL
     */
    protected function getMessageFromResponse(ResponseInterface $response) : ?string
    {
        $body = $response->getBody()->__toString();
        if ($this->getResultMessagePattern() === null) {
            return $body;
        }
        
        $matches = [];
        preg_match($this->getResultMessagePattern(), $body, $matches);
        
        if (empty($matches)) {
            return null;
        }
        
        return $matches['message'] ?? $matches[1];
    }
    
    /**
     * 
     * @return string
     */
    public function getContentType() : ?string
    {
        return $this->contentType;
    }
    
    /**
     * Set the content type for the request.
     * 
     * @uxon-property content_type
     * @uxon-type [application/x-www-form-urlencoded,application/json,text/plain,application/xml]
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setContentType(string $value) : CallWebService
    {
        $this->contentType = trim($value);
        return $this;
    }
    
    /**
     *
     * @return string
     */
    protected function getErrorMessagePattern() : ?string
    {
        return $this->errorMessagePattern;
    }
    
    /**
     * Use a regular expression to extract messages from error responses - the first match is returned or one explicitly named "message".
     * 
     * By default, the action will use the error handler of the data connection to
     * parse error responses from the web service. This will mostly produce general
     * errors like "500 Internal Server Error". Using the `error_message_pattern`
     * you can tell the action where to look for the actual error text.
     * 
     * For example, if the web service would return the following JSON
     * `{"error":"Sorry, you are out of luck!"}`, you could use this regex to get the
     * message: `/"error":"(?<message>[^"]*)"/`.
     * 
     * @uxon-property error_message_pattern
     * @uxon-type string
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setErrorMessagePattern(string $value) : CallWebService
    {
        $this->errorMessagePattern = $value;
        return $this;
    }
    
    /**
     *
     * @return string
     */
    protected function getErrorCodePattern() : ?string
    {
        return $this->errorMessageCode;
    }
    
    /**
     * Use a regular expression to extract error codes from error responses - the first match is returned or one explicitly named "code".
     * 
     * By default, the action will use the error handler of the data connection to
     * parse error responses from the web service. This will mostly produce general
     * errors like "500 Internal Server Error". Using the `error_code_pattern`
     * you can tell the action where to look for the actual error code an use
     * it in the error it produces.
     * 
     * For example, if the web service would return the following JSON
     * `{"errorCode":"2","error":"Sorry!"}`, you could use this regex to get the
     * message: `/"errorCode":"(?<code>[^"]*)"/`.
     * 
     * @uxon-property error_code_pattern
     * @uxon-type string
     * 
     * @param string $value
     * @return CallWebService
     */
    public function setErrorCodePattern(string $value) : CallWebService
    {
        $this->errorMessageCode = $value;
        return $this;
    }
    
    /**
     * 
     * @param \Throwable $e
     * @return ResponseInterface|NULL
     */
    protected function getErrorResponse(\Throwable $e) : ?ResponseInterface
    {
        if (method_exists($e, 'getResponse') === true) {
            $response = $e->getResponse();
            if ($response instanceof ResponseInterface) {
                return $response;
            }
        }
        return null;
    }
    
    /**
     *
     * @param ResponseInterface $response
     * @return string|NULL
     */
    protected function getErrorMessageFromResponse(ResponseInterface $response) : ?string
    {
        if ($this->getErrorMessagePattern() !== null) {
            $body = $response->getBody()->__toString();
            $matches = [];
            preg_match($this->getErrorMessagePattern(), $body, $matches);
            if (empty($matches) === false) {
                return $matches['message'] ?? $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     *
     * @param ResponseInterface $response
     * @return string|NULL
     */
    protected function getErrorCodeFromResponse(ResponseInterface $response) : ?string
    {
        if ($this->getErrorCodePattern() !== null) {
            $body = $response->getBody()->__toString();
            $matches = [];
            preg_match($this->getResultMessagePattern(), $body, $matches);
            if (empty($matches) === false) {
                return $matches['message'] ?? $matches[1];
            }
        }
        
        return null;
    }
}