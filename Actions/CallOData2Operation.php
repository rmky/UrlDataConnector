<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Actions\iCallService;
use exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use GuzzleHttp\Psr7\Request;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\UrlDataConnector\Psr7DataQuery;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Exceptions\Actions\ActionLogicError;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Exceptions\Actions\ActionInputMissingError;

/**
 * Calls an OData service operation (FunctionImport).
 * 
 * @author Andrej Kabachnik
 *
 */
class CallOData2Operation extends AbstractAction implements iCallService 
{
    private $serviceName = null;
    
    private $httpMethod = 'GET';
    
    private $parameters = [];
    
    protected function init()
    {
        parent::init();
        // TODO name, icon
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
            $request = new Request($this->getHttpMethod(), $this->buildUrl($input, $i));
            $query = new Psr7DataQuery($request);
            $response = $this->getDataConnection()->query($query)->getResponse();
            try {
                $resultData = $this->parseResponse($response, $resultData);
            } catch (\Throwable $e) {
                throw new DataQueryFailedError($query, $e->getMessage(), null, $e);
            }
        }
        
        $resultData->setCounterForRowsInDataSource($resultData->countRows());
        
        return ResultFactory::createDataResult($task, $resultData, $this->getResultMessageText() ?? $this->getWorkbench()->getApp('exface.SapConnector')->getTranslator()->translate('ACTION.CALLODATA2OPERATION.SUCCESS'));
    }
    
    protected function getDataConnection() : HttpConnectionInterface
    {
        return $this->getMetaObject()->getDataConnection();
    }
    
    protected function buildUrl(DataSheetInterface $data, int $rowNr) : string
    {
        $url = $this->getFunctionImportName() . '?';
        
        if ($this->getHttpMethod() === 'GET') {
            $url .= $this->buildUrlParams($data, $rowNr);
        }
        
        return $url . (strpos($url, '?') === false ? '?' : '') . '&$format=json';
    }
    
    protected function buildUrlParams(DataSheetInterface $data, int $rowNr) : string
    {
        $params = '';
        
        foreach ($this->getParameters() as $param) {
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
        
        foreach ($this->getParameters() as $param) {
            $val = $data->getCellValue($param->getName(), $rowNr);
            $params .= '&' . $param->getName() . '=' . $this->prepareParamValue($param, $val);
        }
        
        return $params;
    }
    
    protected function prepareParamValue(ServiceParameterInterface $parameter, $val) : string
    {
        if ($parameter->hasDefaultValue() === true && $val === null) {
            $val = $parameter->getDefaultValue();
        }
        
        if ($parameter->isRequired() === true && ($val === '' || $val === null)) {
            throw new ActionInputMissingError($this, 'Value of required parameter "' . $parameter->getName() . '" not set! Please include the corresponding column in the input data or use an input_mapper!', '75C7YOQ');
        }
        
        if ($val === null) {
            return "''";
        }
        
        $val = $parameter->getDataType()->parse($val);
        
        switch (true) {
            case ($parameter->getCustomProperty('odata_type') === 'Edm.Guid'):
                return "guid'" . $val . "'";
                break;
            default:
                return "'" . $val . "'";
        }
    }

    protected function getUrlBuilder() : OData2JsonUrlBuilder
    {
        return $this->getInputObjectExpected()->getQueryBuilder();
    }
    
    protected function parseResponse(ResponseInterface $response, DataSheetInterface $resultData) : DataSheetInterface
    {
        if ($response->getStatusCode() != 200) {
            return $resultData->setFresh(true);
        }
        
        $body = $response->getBody()->__toString();
        
        $json = json_decode($body);
        $result = $json->d;
        if ($result instanceof \stdClass) {
            $rows = [(array) $result];
        } elseif (is_array($result)) {
            $rows = json_decode($body, true)['d'];
        } else {
            throw new \RuntimeException('Invalid result data of type ' . gettype($result) . ': JSON object or array expected!');
        }
        
        $resultData->addRows($rows);
        
        return $resultData;
    }
    
    protected function getResultObject() : MetaObjectInterface
    {
        if ($this->hasResultObjectRestriction()) {
            return $this->getResultObjectExpected();
        }
        return $this->getMetaObject();
    }
    
    public function getServiceName() : string
    {
        return $this->getFunctionImportName();
    }
    
    /**
     *
     * @return string
     */
    public function getFunctionImportName() : string
    {
        return $this->serviceName;
    }
    
    /**
     * The URL endpoint of the opertation (name property of the FunctionImport).
     * 
     * @uxon-property function_import_name
     * @uxon-type string
     * 
     * @param string $value
     * @return CallOData2Operation
     */
    public function setFunctionImportName(string $value) : CallOData2Operation
    {
        $this->serviceName = $value;
        return $this;
    }
    
    /**
     *
     * @return string
     */
    public function getHttpMethod() : string
    {
        return $this->httpMethod;
    }
    
    /**
     * 
     * @param string $value
     * @return CallOData2Operation
     */
    public function setHttpMethod(string $value) : CallOData2Operation
    {
        $this->httpMethod = $value;
        return $this;
    }
    
    /**
     *
     * @return UxonObject
     */
    public function getParameters() : array
    {
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
     * @return CallOData2Operation
     */
    public function setParameters(UxonObject $uxon) : CallOData2Operation
    {
        foreach ($uxon as $paramUxon) {
            $this->parameters[] = new ServiceParameter($this, $paramUxon);
        }
        return $this;
    }
    
    public function getParameter(string $name) : ServiceParameterInterface
    {
        foreach ($this->getParameters() as $arg) {
            if ($arg->getName() === $name) {
                return $arg;
            }
        }
    }
}