<?php
namespace exface\UrlDataConnector\DataConnectors;

use GuzzleHttp\Client;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Exceptions\DataSources\DataConnectionQueryTypeError;
use exface\Core\Exceptions\DataSources\DataConnectionFailedError;
use GuzzleHttp\Psr7\Response;
use exface\Core\CommonLogic\Filemanager;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\Psr6CacheStorage;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use GuzzleHttp\Exception\RequestException;
use exface\UrlDataConnector\Exceptions\HttpConnectorRequestError;
use function GuzzleHttp\Psr7\_caseless_remove;
use function GuzzleHttp\Psr7\modify_request;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use exface\Core\DataTypes\StringDataType;
use GuzzleHttp\Psr7\Uri;
use exface\Core\Exceptions\DataSources\DataConnectionConfigurationError;
use exface\UrlDataConnector\ModelBuilders\SwaggerModelBuilder;
use exface\Core\Exceptions\Security\AuthenticationFailedError;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\CommonLogic\Security\AuthenticationToken\UsernamePasswordAuthToken;
use exface\Core\Interfaces\UserInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\Core\CommonLogic\UxonObject;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;

/**
 * Connector for Websites, Webservices and other data sources accessible via HTTP, HTTPS, FTP, etc.
 * 
 * Performs data source queries by sending HTTP requests via Guzzle PHP library. Can use
 * Swagger/OpenAPI service descriptions to generate a metamodel.
 * 
 * ## URLs
 * 
 * A base `url` can be configured. It will be used whenever a query builder produces relative
 * URLs. If the connection can be used for multiple meta object, it is a good idea to use
 * a base `url` instead of absolute URLs in the object's data addresses.
 * 
 * Similarly, static URL parameters can be added via `fixed_url_params`.
 * 
 * ## Authentication
 * 
 * Supports basic HTTP authentication and digest authentication -uUse `authentication`,
 * `authentication_url` and `authentication_request_method` to configure.
 * 
 * ## Caching
 * 
 * Can cache responses. By default caching is disabled. Use `cache_enabled` to turn it on
 * and ther `cache_*` properties for configuration.
 * 
 * ## Error handling
 * 
 * Apart from the regular data source error handling, this connector can show server error
 * messages and codes directly (instead of using error messages from the model). This is
 * very helpful if the server application can yield well-readale (non-technical) error
 * messages. Set `error_text_use_as_message_title` to `true` to display server errors
 * directly.
 * 
 * Similarly, a static `error_code` can be configured for all errors from this connection
 * instead of using the built-in (very general) error codes.
 * 
 * ## Cookies
 * 
 * Cookies are disabled by default, but can be stored for logged-in users if `use_cookies`
 * is set to `true`.
 *
 * @author Andrej Kabachnik
 *        
 */
class HttpConnector extends AbstractUrlConnector implements HttpConnectionInterface
{
    const AUTH_TYPE_BASIC = 'basic';
    const AUTH_TYPE_DIGEST = 'digest';
    const AUTH_TYPE_NONE = 'none';

    private $user = null;

    private $password = null;

    private $proxy = null;

    private $charset = null;
    
    private $errorTextPattern = null;
    
    private $errorTextUseAsMessageTitle = null;
    
    private $errorCode = null;

    private $use_cookies = false;
    
    private $use_cookie_sessions = false;

    private $cache_enabled = false;

    private $cache_ignore_headers = false;

    private $cache_lifetime_in_seconds = 0;
    
    private $fixed_params = '';

    private $client;
    
    private $swaggerUrl = null;
    
    // Authentication
    /**
     * 
     * @var ?string
     */
    private $authentication = null;
    
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
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\DataConnectionInterface::authenticate()
     */
    public function authenticate(AuthenticationTokenInterface $token, bool $updateUserCredentials = true, UserInterface $credentialsOwner = null) : AuthenticationTokenInterface
    {
        $authenticationType = $this->getAuthentication() ?? self::AUTH_TYPE_BASIC;
        switch ($authenticationType) {
            case self::AUTH_TYPE_DIGEST:
            case self::AUTH_TYPE_BASIC:
                $authenticatedToken =  $this->authenticateViaBasicAuth($token, $updateUserCredentials, $credentialsOwner);
                break;
            case self::AUTH_TYPE_NONE:
                return $token;
            default:
                throw new AuthenticationFailedError("Authentication failed as no supported authentication type was given. Please provide a supported authentication in the connection '{$this->getAlias()}'.");
        }
        
        if ($updateUserCredentials === true && $authenticatedToken) {
            $user = $credentialsOwner ?? $this->getWorkbench()->getSecurity()->getAuthenticatedUser();
            $uxon = new UxonObject([
                'user' => $authenticatedToken->getUsername(),
                'password' => $authenticatedToken->getPassword()
            ]);
            $credentialSetName = ($authenticatedToken->getUsername() ? $authenticatedToken->getUsername() : 'no username') . ' - ' . $this->getName();
            $this->updateUserCredentials($user, $uxon, $credentialSetName);
        }
        
        return $authenticatedToken;
    }
    
    /**
     * Authentication via basic_auth authentication method.
     * 
     * @param AuthenticationTokenInterface $token
     * @throws InvalidArgumentException
     * @throws AuthenticationFailedError
     * @return AuthenticationTokenInterface
     */
    protected function authenticateViaBasicAuth(AuthenticationTokenInterface $token) : AuthenticationTokenInterface
    {
        if (! $token instanceof UsernamePasswordAuthToken) {
            throw new InvalidArgumentException('Invalid token class "' . get_class($token) . '" for authentication via data connection "' . $this->getAliasWithNamespace() . '" - only "UsernamePasswordAuthToken" and derivatives supported!');
        }
        
        if (! $this->getAuthenticationUrl()) {
            throw new AuthenticationFailedError("Authentication failed for User '{$token->getUsername()}'! Either provide authentication_url or a general url in the connection '{$this->getAlias()}'.");
        }
        
        $defaults = array();
        
        // Basic authentication
        $defaults['auth'] = array(
            $token->getUsername(),
            $token->getPassword()
        );
        if ($this->getAuthentication() === self::AUTH_TYPE_DIGEST) {
            $defaults['auth'][] = 'digest';
        }
        
        $response = null;
        
        try {
            $request = new Request($this->getAuthenticationRequestMethod(), $this->getAuthenticationUrl());
            $request = $this->prepareRequest($request);
            $query = new Psr7DataQuery($request);
            $response = $this->getClient()->send($request, $defaults);
        } catch (\Throwable $e) {
            if ($e instanceof RequestException) {
                $response = $e->getResponse();
                $query->setResponse($response);
            } else {
                $response = null;
            }
            $queryError = $this->createResponseException($query, $response, $e);
            throw new AuthenticationFailedError("Authentication failed for User '{$token->getUsername()}' - {$queryError->getMessage()}", null, $queryError);
        }
        
        if ($response === null || $response->getStatusCode() >= 400) {
            if ($response !== null) {
                $query->setResponse($response);
            }
            $queryError = $this->createResponseException($query, $response);
            throw new AuthenticationFailedError("Authentication failed for User '{$token->getUsername()}' ", null, $queryError);
        }
        
        return $token;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\DataConnectionInterface::createLoginWidget()
     */
    public function createLoginWidget(iContainOtherWidgets $container) : iContainOtherWidgets
    {
        if ($this->getAuthentication() === null || $this->getAuthentication() === self::AUTH_TYPE_NONE) {
            return parent::createLoginWidget($container);
        }
        
        $container->setWidgets(new UxonObject([
            [
                'attribute_alias' => 'USERNAME',
                'required' => true
            ],[
                'attribute_alias' => 'PASSWORD'
            ]
        ]));
        return $container;
    }
    
    /**
     * Returns the initialized Guzzle client
     * 
     * @return Client
     */
    protected function getClient() : Client
    {
        if ($this->client === null) {
            $this->connect();
        }
        return $this->client;
    }
    
    /**
     * 
     * @param Client $client
     * @return HttpConnector
     */
    protected function setClient(Client $client) : HttpConnector
    {
        $this->client = $client;
        return $this;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performConnect()
     */
    protected function performConnect()
    {
        $defaults = array();
        $defaults['verify'] = false;
        $defaults['base_uri'] = $this->getUrl();
        // Proxy settings
        if ($this->getProxy()) {
            $defaults['proxy'] = $this->getProxy();
        }
        
        // Basic authentication
        if ($this->getAuthentication() === self::AUTH_TYPE_BASIC || $this->getAuthentication() === self::AUTH_TYPE_DIGEST) {
            $defaults['auth'] = array(
                $this->getUser(),
                $this->getPassword()
            );
            
            if ($this->getAuthentication() === self::AUTH_TYPE_DIGEST) {
                $defaults['auth'][] = 'digest';
            }
        }
        
        // Cookies
        if ($this->getUseCookies()) {
            $cookieFile = str_replace(array(
                ':',
                '/',
                '.'
            ), '', $this->getUrl()) . '.cookie';
            $cookieDir = $this->getWorkbench()->getContext()->getScopeUser()->getUserDataFolderAbsolutePath() . DIRECTORY_SEPARATOR . '.cookies';
            if (! file_exists($cookieDir)) {
                mkdir($cookieDir);
            }
            
            if ($this->getUseCookieSessions() === true) {
                if ($this->getWorkbench()->getCMS()->isUserLoggedIn() === false) {
                    $err = new DataConnectionFailedError($this, 'Cannot use session cookies for HTTP connection "' . $this->getAlias() . '": user not logged on!');
                    $this->getWorkbench()->getLogger()->logException($err);
                }
                $storeSessionCookies = true;
            } else {
                $storeSessionCookies = false;
            }
            $cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieDir . DIRECTORY_SEPARATOR . $cookieFile, $storeSessionCookies);
            $defaults['cookies'] = $cookieJar;
        } elseif ($this->getUseCookieSessions() === true) {
            throw new DataConnectionConfigurationError($this, 'Cannot set use_cookie_sessions=true if use_cookies=false for HTTP connection alias "' . $this->getAlias() . '"!');
        }
        
        // Cache
        if ($this->getCacheEnabled()) {
            // Create default HandlerStack
            $stack = HandlerStack::create();
            
            if ($this->getCacheIgnoreHeaders()) {
                $cache_strategy_class = '\\Kevinrob\\GuzzleCache\\Strategy\\GreedyCacheStrategy';
            } else {
                $cache_strategy_class = '\\Kevinrob\\GuzzleCache\\Strategy\\PrivateCacheStrategy';
            }
            
            // Add cache middleware to the top with `push`
            $stack->push(new CacheMiddleware(new $cache_strategy_class(new Psr6CacheStorage(new FilesystemAdapter('', 0, $this->getCacheAbsolutePath())), $this->getCacheLifetimeInSeconds())), 'cache');
            
            // Initialize the client with the handler option
            $defaults['handler'] = $stack;
        }
        
        try {
            $this->setClient(new Client($defaults));
        } catch (\Throwable $e) {
            throw new DataConnectionFailedError($this, "Failed to instantiate HTTP client: " . $e->getMessage(), '6T4RAVX', $e);
        }
    }
    
    /**
     * Returns TRUE if the client is initialized and ready to perform queries.
     * 
     * @return bool
     */
    public function isConnected() : bool
    {
        return $this->client !== null;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performQuery()
     *
     * @param Psr7DataQuery $query            
     * @return Psr7DataQuery
     */
    protected function performQuery(DataQueryInterface $query)
    {
        if (! ($query instanceof Psr7DataQuery)) {
            throw new DataConnectionQueryTypeError($this, 'Connector "' . $this->getAliasWithNamespace() . '" expects a Psr7DataQuery as input, "' . get_class($query) . '" given instead!');
        }
        /* @var $query \exface\UrlDataConnector\Psr7DataQuery */
        
        // Default Headers zur Request hinzufuegen, um sie im Tracer anzuzeigen.
        $this->addDefaultHeadersToQuery($query);
        if ($this->willIgnore($query) === true) {
            $query->setResponse(new Response());
        } else {
            if ($this->isConnected() === false) {
                $this->connect();
            }
            try {
                if (! $query->isUriFixed() && $this->getFixedUrlParams()) {
                    $uri = $query->getRequest()->getUri();
                    $query->setRequest($query->getRequest()->withUri($uri->withQuery($uri->getQuery() . $this->getFixedUrlParams())));
                }
                $request = $query->getRequest();
                $request = $this->prepareRequest($request);
                $response = $this->getClient()->send($request, $guzzleRequestOptions ?? []);
                $query->setResponse($response);
            } catch (RequestException $re) {
                if ($response = $re->getResponse()) {
                    $query->setResponse($response);
                } else {
                    $response = null;
                }
                $query->setRequest($re->getRequest());
                throw $this->createResponseException($query, $response, $re);
            }
        }
        return $query;
    }
    
    /**
     * 
     * @param RequestInterface $request
     * @return RequestInterface
     */
    protected function prepareRequest(RequestInterface $request) : RequestInterface
    {
        if ($request->getUri()->__toString() === '') {
            $baseUrl = $this->getUrl();
            if ($endpoint = StringDataType::substringAfter($baseUrl, '/', '', false, true)) {
                $request = $request->withUri(new Uri($endpoint));
            }
        }
        return $request;
    }
    
    /**
     * 
     * @param Psr7DataQuery $query
     * @param ResponseInterface $response
     * @param \Throwable $exceptionThrown
     * @return \exface\UrlDataConnector\Exceptions\HttpConnectorRequestError
     */
    protected function createResponseException(Psr7DataQuery $query, ResponseInterface $response = null, \Throwable $exceptionThrown = null)
    {
        if ($response !== null) {
            $message = $this->getResponseErrorText($response, $exceptionThrown);
            $code = $this->getResponseErrorCode($response, $exceptionThrown);
            $ex = new HttpConnectorRequestError($query, $response->getStatusCode(), $response->getReasonPhrase(), $message, $code, $exceptionThrown);
            $useAsTitle = false;
            if ($this->getErrorTextUseAsMessageTitle() === true) {
                $useAsTitle = true;
            } elseif ($this->getErrorTextUseAsMessageTitle() === null) {
                if ($exceptionThrown !== null && $exceptionThrown->getMessage() !== $message) {
                    $useAsTitle = true;
                }
            }
            if ($useAsTitle === true) {
                $ex->setUseRemoteMessageAsTitle(true);
            }
        } else {
            $ex = new HttpConnectorRequestError($query, 0, 'No Response from Server', $exceptionThrown->getMessage(), null, $exceptionThrown);
        }
        
        return $ex;
    }
    
    /**
     * Adds the default headers, which are defined on the client, to the request
     * to show them in the tracer or errors.
     * 
     * @param Psr7DataQuery $query
     */
    protected function addDefaultHeadersToQuery(Psr7DataQuery $query)
    {
        $requestHeaders = $query->getRequest()->getHeaders();
        $clientHeaders = $this->getClient()->getConfig('headers');
        $clientHeaders = _caseless_remove(array_keys($requestHeaders), $clientHeaders);
        $query->setRequest(modify_request($query->getRequest(), ['set_headers'=> $clientHeaders]));
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getUser()
     */
    public function getUser()
    {
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
     * Returns the proxy address to be used in the name:port notation: e.g.
     * 192.169.1.10:8080 or myproxy:8080.
     *
     * @return string
     */
    public function getProxy()
    {
        return $this->proxy;
    }

    /**
     * Proxy server address to be used - e.g. myproxy:8080 or 192.169.1.10:8080.
     *
     * @uxon-property proxy
     * @uxon-type string
     *
     * @param string $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setProxy($value)
    {
        $this->proxy = $value;
        return $this;
    }

    /**
     *
     * @return boolean
     */
    public function getUseCookies() : bool
    {
        return $this->use_cookies;
    }

    /**
     * Set to TRUE to use cookies for this connection.
     *
     * Cookies will be stored in the data folder of the current user!
     * 
     * NOTE: session cookies will not be stored unless `use_cookie_sessions` is
     * also explicitly set to `TRUE`!
     *
     * @uxon-property use_cookies
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUseCookies(bool $value) : HttpConnectionInterface
    {
        $this->use_cookies = $value;
        return $this;
    }
    
    /**
     *
     * @return bool
     */
    public function getUseCookieSessions() : bool
    {
        return $this->use_cookie_sessions;
    }
    
    /**
     * Set to TRUE to store session cookies too.
     * 
     * This option can only be used if `use_cookies` is `TRUE`.
     *
     * @uxon-property use_cookie_sessions
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setUseCookieSessions(bool $value) : HttpConnectionInterface
    {
        $this->use_cookie_sessions = $value;
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getCacheEnabled()
     */
    public function getCacheEnabled()
    {
        return $this->cache_enabled;
    }

    /**
     * Enables (TRUE) or disables (FALSE) caching of HTTP requests.
     * 
     * Use `cache_lifetime_in_seconds`, `cache_ignore_headers`, etc. for futher cache
     * customization.
     *
     * @uxon-property cache_enabled
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheEnabled($value)
    {
        $this->cache_enabled = \exface\Core\DataTypes\BooleanDataType::cast($value);
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getCacheIgnoreHeaders()
     */
    public function getCacheIgnoreHeaders()
    {
        return $this->cache_ignore_headers;
    }

    /**
     * Set to TRUE to cache all requests regardless of their cache-control headers.
     *
     * If set to TRUE, this automatically sets the default cache lifetime to 60 seconds. Use
     * "cache_lifetime_in_seconds" to specify a custom value.
     *
     * @uxon-property cache_ignore_headers
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param boolean $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheIgnoreHeaders($value)
    {
        $this->cache_ignore_headers = \exface\Core\DataTypes\BooleanDataType::cast($value);
        if ($this->getCacheIgnoreHeaders()) {
            $this->setCacheLifetimeInSeconds(60);
        }
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpConnectionInterface::getCacheLifetimeInSeconds()
     */
    public function getCacheLifetimeInSeconds()
    {
        return $this->cache_lifetime_in_seconds;
    }

    /**
     * How long a cached URL is concidered up-to-date.
     * 
     * NOTE: This only works if the cache is enabled via `cache_enabled: true`.
     *
     * @uxon-property cache_lifetime_in_seconds
     * @uxon-type integer
     *
     * @param integer $value            
     * @return \exface\UrlDataConnector\DataConnectors\HttpConnector
     */
    public function setCacheLifetimeInSeconds($value)
    {
        $this->cache_lifetime_in_seconds = intval($value);
        return $this;
    }

    /**
     * 
     * @return string
     */
    protected function getCacheAbsolutePath()
    {
        $path = Filemanager::pathJoin(array(
            $this->getWorkbench()->filemanager()->getPathToCacheFolder(),
            $this->getAliasWithNamespace()
        ));
        if (! file_exists($path)) {
            mkdir($path);
        }
        return $path;
    }
    
    /**
     * 
     * @return string
     */
    public function getFixedUrlParams() : string
    {
        return $this->fixed_params;
    }

    /**
     * Adds specified params to every request: e.g. &format=json&ignoreETag=false.
     * 
     * @uxon-property fixed_url_params
     * @uxon-type string
     * 
     * @param string $fixed_params
     * @return HttpConnectionInterface
     */
    public function setFixedUrlParams(string $fixed_params)
    {
        $this->fixed_params = $fixed_params;
        return $this;
    }
    
    /**
     * Extracts the message text from an error-response.
     * 
     * Override this method to get more error details from the response body or headers
     * depending on the protocol used in a specific connector.
     *
     * @param ResponseInterface $response
     * @return string
     */
    protected function getResponseErrorText(ResponseInterface $response, \Throwable $exceptionThrown = null) : string
    {
        if ($pattern = $this->getErrorTextPattern()) {
            $body = $response->getBody()->__toString();
            $matches = [];
            preg_match($pattern, $body, $matches);
            if (empty($matches) === false) {
                return $matches['message'] ?? $matches[1];
            }
        }
        
        if ($exceptionThrown !== null) {
            return $exceptionThrown->getMessage();
        }
        return $response->getReasonPhrase();
    }
    
    /**
     * Returns the application-specifc error code provided by the server or NULL if no meaningful error code was sent.
     * 
     * If static `error_code` is defined for this connection, it will be returned by default.
     * 
     * @param ResponseInterface $response
     * @param \Throwable $exceptionThrown
     * @return string|NULL
     */
    protected function getResponseErrorCode(ResponseInterface $response, \Throwable $exceptionThrown = null) : ?string
    {
        return $this->getErrorCode();
    }
    
    /**
     * 
     * @param Psr7DataQuery $query
     * @return bool
     */
    protected function willIgnore(Psr7DataQuery $query) : bool
    {
        return $query->getRequest()->getUri()->__toString() ? false : true;
    }
    
    /**
     *
     * @return string|NULL
     */
    public function getSwaggerUrl() : ?string
    {
        return $this->swaggerUrl;
    }
    
    /**
     * URL of the Swagger/OpenAPI description of the web service (if available).
     * 
     * If used, the metamodel can be generated from the Swagger description using
     * the SwaggerModelBuilder.
     * 
     * @uxon-property swagger_url
     * @uxon-type uri
     * 
     * @param string $value
     * @return HttpConnector
     */
    public function setSwaggerUrl(string $value) : HttpConnector
    {
        $this->swaggerUrl = $value;
        return $this;
    }
    
    /**
     * 
     * @return bool
     */
    public function hasSwagger() : bool
    {
        return $this->swaggerUrl !== null;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        if ($this->hasSwagger()) {
            return new SwaggerModelBuilder($this);
        }
        return parent::getModelBuilder();
    }
    
    /**
     * Returns the server root of the URL.
     * 
     * E.g. http://www.mydomain.com for http://www.mydomain.com/path.
     * 
     * @return string
     */
    public function getUrlServerRoot() : string
    {
        $parts = parse_url($this->getUrl());
        $port = ($parts['port'] ? ':' . $parts['port'] : '');
        if ($parts['user'] || $parts['pass']) {
            $auth = $parts['user'] . ($parts['pass'] ? ':' . $parts['pass'] : '') . '@';
        } else {
            $auth = '';
        }
        return $parts['scheme'] . '://' . $auth . $parts['host'] . $port;
    }
    
    /**
     *
     * @return string|NULL
     */
    public function getErrorTextPattern() : ?string
    {
        return $this->errorTextPattern;
    }
    
    /**
     * Regular expression to extract the error message from the error response.
     * 
     * By default, all server errors result in fairly general "webservice request failed" errors,
     * where the actual error message is part of the error details. This is good for technical APIs,
     * that provide exception-type errors or error codes. However, if the server can generate
     * error messages suitable for end users, you can extract them via `error_text_pattern` and
     * even use the as message titles, so they are always visible for users.
     * 
     * If an `error_text_pattern` is provided and a match was found, the remote error text
     * will be automatically placed in the title of the error message shown. To move it back to
     * the details, set `error_text_use_as_message_title` to `false` explicitly. In this case,
     * the remote error messages will still be only visible in the error details, but they will
     * be better formatted - this is a typical setting for technical APIs with structured errors
     * like `{"error": "message text"}`.
     * 
     * If a pattern is provided, it is applied to the body text of error responses and the first 
     * match or one explicitly named "message" is concidered to be the error text.
     * 
     * For example, if the web service would return the following JSON
     * `{"error":"Sorry, you are out of luck!"}`, you could use this regex to get the
     * message: `/"error":"(?<message>[^"]*)"/`.
     * 
     * @param string $value
     * @return HttpConnector
     */
    public function setErrorTextPattern(string $value) : HttpConnector
    {
        $this->errorTextPattern = $value;
        return $this;
    }
    
    /**
     *
     * @return bool
     */
    public function getErrorTextUseAsMessageTitle() : ?bool
    {
        return $this->errorTextUseAsMessageTitle;
    }
    
    /**
     * Set to TRUE/FALSE to place the remote error message in the title or the details of error messages displayed.
     * 
     * By default, all server errors result in fairly general "webservice request failed" errors,
     * where the actual error message is part of the error details. This is good for technical APIs,
     * that provide exception-type errors or error codes. However, if the server can generate
     * error messages suitable for end users, you can extract them via `error_text_pattern` and
     * even use the as message titles, so they are always visible for users.
     * 
     * @uxon-property error_text_use_as_message_title
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $value
     * @return HttpConnector
     */
    public function setErrorTextUseAsMessageTitle(bool $value) : HttpConnector
    {
        $this->errorTextUseAsMessageTitle = $value;
        return $this;
    }
    
    protected function getAuthentication() : ?string
    {
        if ($this->authentication === null) {
            if ($this->getUser() !== null) {
                return self::AUTH_TYPE_BASIC;
            }
        }
        return $this->authentication;
    }
    
    /**
     * Set the authentication method this connection should use.
     * 
     * @uxon-property authentication
     * @uxon-type [basic,digest,none]
     * 
     * @param string $value
     * @return HttpConnector
     */
    public function setAuthentication(string $value) : HttpConnector
    {
        if (defined('static::AUTH_TYPE_' . mb_strtoupper($value)) === false) {
            throw new DataConnectionConfigurationError($this, 'Invalid value "' . $value . '" for connection property "authentication".');
        }
        $this->authentication = mb_strtolower($value);
        return $this;
    }
    
    /**
     * Set the authentication url.
     * 
     * @uxon-property authentication_url
     * @uxon-type string
     * 
     * @param string $string
     * @return HttpConnectionInterface
     */
    public function setAuthenticationUrl(string $string) : HttpConnectionInterface
    {
        $this->authentication_url = $string;
        return $this;
    }
    
    protected function getAuthenticationUrl() : ?string
    {
        return $this->authentication_url ?? $this->getUrl();
    }
    
    /**
     * Set the authentication request method. Default is 'GET'.
     * 
     * @uxon-property authentication_request_method
     * @uxon-type [GET,POST,CONNECT,HEAD,OPTIONS]
     * 
     * @param string $string
     * @return HttpConnectionInterface
     */
    public function setAuthenticationRequestMethod(string $string) : HttpConnectionInterface
    {
        $this->authentication_request_method = $string;
        return $this;
    }
    
    protected function getAuthenticationRequestMethod() : ?string
    {
        return $this->authentication_request_method;
    }
    
    /**
     *
     * @return string|NULL
     */
    protected function getErrorCode() : ?string
    {
        return $this->errorCode;
    }
    
    /**
     * Set a static error code for all data source errors from this connection.
     * 
     * Sometimes it is very helpful to let the user know, that the error comes from a
     * specific connected system. Think of an error code like `SAP-ERROR`, `FACEBOOK-ERR`
     * or similar - something to give the user a hint of where the error happened.
     * 
     * You can even create an error message in the metamodel with this code and describe
     * there, what to do.
     * 
     * @uxon-property error_code
     * @uxon-type string
     * 
     * @param string $value
     * @return HttpConnector
     */
    public function setErrorCode(string $value) : HttpConnector
    {
        $this->errorCode = $value;
        return $this;
    }
}