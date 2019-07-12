<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Exceptions\Actions\ActionInputMissingError;

/**
 * Calls an OData service operation (FunctionImport).
 * 
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class CallGraphQLQuery extends CallWebService 
{
    private $queryName = null;
    
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
        
        return '"' . $val . '"';
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
        $json = json_decode($body, true);        
        $resultData->addRows($json['data']);
        
        return $resultData;
    }
    
    /**
     *
     * @return string
     */
    protected function getQueryName() : string
    {
        return $this->queryName;
    }
    
    /**
     * Name of the GraphQL query to execute.
     * 
     * @uxon-property query_name
     * @uxon-type string
     * 
     * @param string $value
     * @return CallGraphQLQuery
     */
    public function setQueryName(string $value) : CallGraphQLQuery
    {
        $this->queryName = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Actions\CallWebService::getMethod()
     */
    protected function getMethod($default = 'POST') : string
    {
        return parent::getMethod('POST');
    }
    
    protected function buildBody(DataSheetInterface $data, int $rowNr) : string
    {
        $body = parent::buildBody($data, $rowNr);
        if ($body === '') {
            $body = $this->buildGqlBody($data, $rowNr);
        }
        
        return $body;
    }
    
    protected function buildGqlBody(DataSheetInterface $data, int $rowNr) : string
    {
        return <<<GraphQL

query {
    {$this->getQueryName()} {
        {$this->buildGqlFields($data, $rowNr)}
    }
} 

GraphQL;
    }
        
    protected function buildGqlFields(DataSheetInterface $data, int $rowNr) : string
    {
        $fields = '';
        $row = $data->getRow($rowNr);
        foreach ($this->getParameters() as $parameter) {
            $fields .= "        {$parameter->getName()}: {$this->prepareParamValue($parameter, $row[$parameter->getName()])}\r\n";
        }
        return trim($fields);
    }
}