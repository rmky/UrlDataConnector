<?php
namespace exface\UrlDataConnector\Actions;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Actions\ServiceParameterInterface;
use exface\Core\Exceptions\Actions\ActionInputMissingError;

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
    
}