<?php
namespace exface\UrlDataConnector\DataConnectors\Authentication;

use exface\Core\Interfaces\Security\PasswordAuthenticationTokenInterface;

/**
 * Digest access authentication for HTTP connectors.
 *
 * @author Andrej Kabachnik
 *
 */
class HttpDigestAuth extends HttpBasicAuth
{
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\Authentication\HttpBasicAuth::getAuthenticationRequestOptions()
     */
    protected function getAuthenticationRequestOptions(array $defaultOptions, PasswordAuthenticationTokenInterface $token) : array
    {
        $options = parent::getAuthenticationRequestOptions($defaultOptions, $token);
        $options['auth'][] = 'digest';
        return $options;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\DataConnectors\Authentication\HttpBasicAuth::getDefaultRequestOptions()
     */
    public function getDefaultRequestOptions(array $defaultOptions): array
    {
        $options = parent::getDefaultRequestOptions($defaultOptions);
        $options['auth'][] = 'digest';
        return $options;
    }
}