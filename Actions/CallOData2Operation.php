<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\TimeDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\TimestampDataType;
use exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder;

/**
 * Calls an OData service operation (FunctionImport).
 * 
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class CallOData2Operation extends CallWebService 
{
    private $serviceName = null;
    
    protected function init()
    {
        parent::init();
        // TODO name, icon
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::buildUrl()
     */
    protected function buildUrl(DataSheetInterface $data, int $rowNr) : string
    {
        $url = parent::buildUrl($data, $rowNr);
        return $url . (strpos($url, '?') === false ? '?' : '') . '&$format=json';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::prepareParamValue()
     */
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
        
        $dataType = $parameter->getDataType();
        $odataType = $parameter->getCustomProperty(OData2JsonUrlBuilder::DS_ODATA_TYPE);
        $val = $dataType->parse($val);
        
        return OData2JsonUrlBuilder::buildUrlFilterODataValue($val, $dataType, $odataType);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::parseResponse()
     */
    protected function parseResponse(ResponseInterface $response, DataSheetInterface $resultData) : DataSheetInterface
    {
        if ($response->getStatusCode() != 200) {
            return $resultData->setFresh(true);
        }
        
        $body = $response->getBody()->__toString();
        
        $json = json_decode($body);
        $result = $json->d;
        if ($result instanceof \stdClass) {
            if ($result->results && is_array($result->results)) {
                // Decode JSON as assoc array again because otherwise the rows will remain objects.
                $rows = json_decode($body, true)['d']['results'];
            } else {
                $rows = [(array) $result];
            }
        } elseif (is_array($result)) {
            // Decode JSON as assoc array again because otherwise the rows will remain objects.
            $rows = json_decode($body, true)['d'];
        } else {
            throw new \RuntimeException('Invalid result data of type ' . gettype($result) . ': JSON object or array expected!');
        }
        
        $resultData->addRows($rows);
        
        return $resultData;
    }
    
    /**
     *
     * @return string
     */
    protected function getFunctionImportName() : string
    {
        return $this->getUrl();
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
        return $this->setUrl($value);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::getMethod()
     */
    protected function getMethod($default = 'GET') : string
    {
        return parent::getMethod('GET');
    }
}