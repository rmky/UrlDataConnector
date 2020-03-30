<?php
namespace exface\UrlDataConnector\DataConnectors\Authentication;

use exface\Core\CommonLogic\UxonObject;
use exface\UrlDataConnector\Interfaces\UrlConnectionInterface;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\Interfaces\Security\AuthenticationTokenInterface;
use exface\Core\Interfaces\Widgets\iContainOtherWidgets;
use exface\UrlDataConnector\Facades\OAuth2ClientFacade;
use exface\Core\Factories\FacadeFactory;
use exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface;

class OAuth2 implements HttpAuthenticationProviderInterface
{
    use ImportUxonObjectTrait;
    
    private $connection = null;
    
    private $clientId = null;
    
    private $scope = null;
    
    private $authorizationPageUrl = null;
    
    private $clientFacade = null;
    
    public function __construct(UrlConnectionInterface $dataConnection, UxonObject $uxon)
    {
        $this->connection = $dataConnection;
        $this->importUxonObject($uxon);
    }
    
    public function exportUxonObject()
    {
        return new UxonObject();
    }
    
    public function authenticate(AuthenticationTokenInterface $token) : AuthenticationTokenInterface
    {
        // TODO
        return $token;
    }
    
    public function createLoginWidget(iContainOtherWidgets $container) : iContainOtherWidgets
    {
        $container->setWidgets(new UxonObject([
            [
                'widget_type' => 'browser',
                'url' => $this->getAuthorizationPageUrl()
            ]
        ]));
        return $container;
    }
    
    protected function getAuthorizationPageUrl() : string
    {
        return $this->authorizationPageUrl . '&client_id=' . $this->getClientId() . '&state=' . $this->getState() . '&redirect_uri=' . urlencode($this->getRedirectUrl());
    }
    
    protected function getClientId() : string
    {
        return $this->clientId;
    }
    
    protected function getState() : string
    {
        return uniqid(null, true);
    }
    
    protected function getConnection() : UrlConnectionInterface
    {
        return $this->connection;
    }
    
    protected function getClientFacade() : OAuth2ClientFacade
    {
        if ($this->clientFacade === null) {
            $this->clientFacade = FacadeFactory::createFromString(OAuth2ClientFacade::class, $this->getConnection()->getWorkbench());;
        }
        return $this->clientFacade;
    }
    
    protected function getRedirectUrl() : string
    {
        $this->getClientFacade()->buildUrlToFacade(false);
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
    
    public function getDefaultRequestOptions(array $defaultOptions): array
    {
        return $defaultOptions;
    }

    public function getCredentialsUxon(AuthenticationTokenInterface $authenticatedToken): UxonObject
    {
        return new UxonObject([
            'authentication' => [
                'class' => '\\' . get_class($this)
            ]
        ]);
    }

}