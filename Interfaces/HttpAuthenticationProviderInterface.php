<?php
namespace exface\UrlDataConnector\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\Security\AuthenticationProviderInterface;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\CommonLogic\UxonObject;

/**
 * Interface for HTTP-based authentication providers
 *
 * @author Andrej Kabachnik
 *        
 */
interface HttpAuthenticationProviderInterface extends iCanBeConvertedToUxon, AuthenticationProviderInterface
{
    /**
     * Adds Guzzle request options required for authentication to the default options array of the Guzzle client.
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
}