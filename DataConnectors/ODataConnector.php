<?php
namespace exface\UrlDataConnector\DataConnectors;

use exface\Core\CommonLogic\AbstractDataConnector;
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
use exface\UrlDataConnector\ModelBuilders\ODataModelBuilder;

/**
 * Connector for oData web services
 * 
 * Refer to the documentation of the HttpConnector for detailed information about connection settings. 
 *
 * @author Andrej Kabachnik
 *        
 */
class ODataConnector extends HttpConnector
{
    private $metadataUrl = null;

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new ODataModelBuilder($this);
    }
    
    /**
     * @return string
     */
    public function getMetadataUrl()
    {
        if (is_null($this->metadataUrl)) {
            return rtrim($this->getUrl(), '/') . '/$metadata';
        }
        return $this->metadataUrl;
    }

    /**
     * Specifies the URL of the $metadata endpoint.
     * 
     * If not set, url/$metadata will be used automatically.
     * 
     * @uxon-property metadata_url
     * @uxon-type string
     * 
     * @param string $metadataUrl
     */
    public function setMetadataUrl($metadataUrl)
    {
        $this->metadataUrl = $metadataUrl;
        return $this;
    }

    
    
}
?>