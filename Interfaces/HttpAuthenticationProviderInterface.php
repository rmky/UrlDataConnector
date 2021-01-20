<?php
namespace exface\UrlDataConnector\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\Security\AuthenticationProviderInterface;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\CommonLogic\UxonObject;
use Psr\Http\Message\RequestInterface;

/**
 * Interface for HTTP-based authentication providers
 *
 * @author Andrej Kabachnik
 *        
 */
interface HttpAuthenticationProviderInterface extends iCanBeConvertedToUxon, AuthenticationProviderInterface
{
    /**
     * Returns the Guzzle request options array with auth data to use with every regular request.
     * 
     * These options will normally be set as defaults for the Guzzle client
     * 
     * @link http://docs.guzzlephp.org/en/stable/request-options.html
     * 
     * @param array $defaultOptions
     * @return array
     */
    public function getDefaultRequestOptions(array $defaultOptions) : array;
    
    /**
     * Creates the user-specific data connection configuration to store in the credential storage.
     * 
     * @param AuthenticationTokenInterface $authenticatedToken
     * @return UxonObject
     */
    public function getCredentialsUxon(AuthenticationTokenInterface $authenticatedToken) : UxonObject;
    
    /**
     * 
     * @param RequestInterface $request
     * @return RequestInterface
     */
    public function signRequest(RequestInterface $request) : RequestInterface;
}