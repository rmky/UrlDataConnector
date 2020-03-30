<?php
namespace exface\UrlDataConnector\DataConnectors\Authentication;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface;
use exface\Core\CommonLogic\Security\AuthenticationToken\UsernamePasswordAuthToken;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\DataSources\DataConnectionConfigurationError;
use GuzzleHttp\Psr7\Request;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\Selectors\UserSelectorInterface;
use exface\Core\Interfaces\Security\PasswordAuthenticationTokenInterface;
use exface\UrlDataConnector\DataConnectors\HttpConnector;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;

/**
 * HTTP basic authentication for HTTP connectors
 * 
 * @author Andrej Kabachnik
 *
 */
class HttpBasicAuth implements HttpAuthenticationProviderInterface
{
    use ImportUxonObjectTrait;
    
    private $connection = null;
    
    private $constructorUxon = null;
    
    private $user = null;
    
    private $password = null;
    
    /**
     *
     * @var ?string
     */
    private $authentication_url = null;
    
    /**
     *
     * @var string
     */
    private $authentication_request_method = 'GET';
    
    public function __construct(HttpConnectionInterface $dataConnection, UxonObject $uxon = null)
    {
        $this->connection = $dataConnection;
        $this->constructorUxon = $uxon;
        if ($uxon !== null) {
            $this->importUxonObject($uxon, ['class']);
        }
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getUser()
     */
    public function getUser()
    {
        if ($this->user === null && ($this->connection instanceof HttpConnector)) {
            return $this->connection->getUser();
        }
        return $this->user;
    }
    
    /**
     * The user name for HTTP basic authentification.
     *
     * @uxon-property user
     * @uxon-type string
     *
     * @param string $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUser($value)
    {
        $this->user = $value;
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getPassword()
     */
    public function getPassword()
    {
        if ($this->user === null && ($this->connection instanceof HttpConnector)) {
            return $this->connection->getPassword();
        }
        return $this->password;
    }
    
    /**
     * Sets the password for basic HTTP authentification.
     *
     * @uxon-property password
     * @uxon-type password
     *
     * @param string $value
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setPassword($value)
    {
        $this->password = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->connection->getWorkbench();
    }
    
    /**
     * Set the authentication url.
     *
     * @uxon-property authentication_url
     * @uxon-type string
     *
     * @param string $string
     * @return HttpBasicAuth
     */
    public function setAuthenticationUrl(string $string) : HttpBasicAuth
    {
        $this->authentication_url = $string;
        return $this;
    }
    
    protected function getAuthenticationUrl() : ?string
    {
        return $this->authentication_url;
    }
    
    /**
     * Set the authentication request method. Default is 'GET'.
     *
     * @uxon-property authentication_request_method
     * @uxon-type [GET,POST,CONNECT,HEAD,OPTIONS]
     *
     * @param string $string
     * @return HttpBasicAuth
     */
    public function setAuthenticationRequestMethod(string $string) : HttpBasicAuth
    {
        $this->authentication_request_method = $string;
        return $this;
    }
    
    protected function getAuthenticationRequestMethod() : ?string
    {
        return $this->authentication_request_method;
    }
    
    protected function getAuthenticationRequestOptions(array $defaultOptions, PasswordAuthenticationTokenInterface $token) : array
    {
        $options = $defaultOptions;
        
        // Basic authentication
        $options['auth'] = array(
            $token->getUsername(),
            $token->getPassword()
        );
        
        return $options;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\DataConnectionInterface::createLoginWidget()
     */
    public function createLoginWidget(iContainOtherWidgets $loginForm, bool $saveCredentials = true, UserSelectorInterface $credentialsOwner = null) : iContainOtherWidgets
    {
        $loginForm->addWidget(WidgetFactory::createFromUxonInParent($loginForm, new UxonObject([
            'attribute_alias' => 'USERNAME',
            'required' => true
        ])), 0);
        $loginForm->addWidget(WidgetFactory::createFromUxonInParent($loginForm, new UxonObject([
            'attribute_alias' => 'PASSWORD'
        ])), 1);
        
        return $loginForm;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Security\AuthenticationProviderInterface::authenticate()
     */
    public function authenticate(AuthenticationTokenInterface $token) : AuthenticationTokenInterface
    {
        if (! $token instanceof UsernamePasswordAuthToken) {
            throw new InvalidArgumentException('Invalid token class "' . get_class($token) . '" for authentication via data connection "' . $this->getAliasWithNamespace() . '" - only "UsernamePasswordAuthToken" and derivatives supported!');
        }
        
        $url = $this->getAuthenticationUrl() ?? $this->getDataConnection()->getUrl();
        if (! $url) {
            throw new DataConnectionConfigurationError($this, "Cannot perform authentication in data connection '{$this->getName()}'! Either provide authentication_url or a general url in the connection configuration.");
        }
        
        try {
            $request = new Request($this->getAuthenticationRequestMethod(), $url);
            $this->getDataConnection()->sendRequest($request, $this->getAuthenticationRequestOptions([], $token));
        } catch (\Throwable $e) {
            throw $e;
        }
        
        return $token;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return $this->constructorUxon;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::getCredentialsUxon()
     */
    public function getCredentialsUxon(AuthenticationTokenInterface $authenticatedToken) : UxonObject
    {
        return new UxonObject([
            'authentication' => [
                'class' => '\\' . get_class($this),
                'user' => $authenticatedToken->getUsername(),
                'password' => $authenticatedToken->getPassword()
            ]
        ]);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::getDefaultRequestOptions()
     */
    public function getDefaultRequestOptions(array $defaultOptions): array
    {
        $options = $defaultOptions;
        
        $options['auth'] = array(
            $this->getUser(),
            $this->getPassword()
        );
        
        return $options;
    }
    
    protected function getDataConnection() : HttpConnectionInterface
    {
        return $this->connection;
    }
}