<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Actions\iCallRemoteFunction;
use exface\UrlDataConnector\QueryBuilders\OData2JsonUrlBuilder;
use exface\Core\CommonLogic\UxonObject;

class CallOData2Operation extends AbstractAction implements iCallRemoteFunction 
{
    private $serviceName = null;
    
    private $httpMethod = 'GET';
    
    private $parameters = [];
    
    protected function init()
    {
        parent::init();
        // TODO name, icon
    }
    
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        
    }

    protected function getUrlBuilder() : OData2JsonUrlBuilder
    {
        return $this->getInputObjectExpected()->getQueryBuilder();
    }
    
    public function getRemoteFunctionName() : string
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
    public function getParameters() : UxonObject
    {
        return $this->parameters;
    }
    
    /**
     * 
     * @param UxonObject $value
     * @return CallOData2Operation
     */
    public function setParameters(UxonObject $uxon) : CallOData2Operation
    {
        foreach ($uxon as $paramUxon) {
            $this->parameters = new RemoteFunctionParameter($this, $uxon);
        }
        return $this;
    }
}