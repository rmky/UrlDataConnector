<?php
namespace exface\UrlDataConnector\Facades;

use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;

class OAuth2ClientFacade extends AbstractHttpFacade
{
    public function getUrlRouteDefault(): string
    {
        return 'api/oauth2client';
    }  
}