<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Calls a GraphQL mutation over HTTP.
 * 
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class CallGraphQLMutation extends CallGraphQLQuery 
{
    private $mutationName = null;
    
    /**
     * Name of the GraphQL mutation to execute.
     *
     * @uxon-property mutation_name
     * @uxon-type string
     *
     * @param string $value
     * @return CallGraphQLMutation
     */
    public function setMutationName(string $value) : CallGraphQLMutation
    {
        $this->mutationName = $value;
        return $this;
    }
    
    /**
     *
     * @return string
     */
    protected function getMutationName() : string
    {
        return $this->mutationName;
    }
    
    protected function buildGqlBody(DataSheetInterface $data, int $rowNr) : string
    {
        return <<<GraphQL
        
mutation {
    {$this->getMutationName()} (
        {$this->buildGqlFields($data, $rowNr)}
    )
    { }
}

GraphQL;
    }
        
    protected function buildHeaders() : array
    {
        return array_merge(['Content-Type'=> 'application/graphql'], parent::buildHeaders());
    }
}