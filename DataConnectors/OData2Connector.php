<?php
namespace exface\UrlDataConnector\DataConnectors;

use function GuzzleHttp\Psr7\_caseless_remove;
use function GuzzleHttp\Psr7\modify_request;
use exface\UrlDataConnector\ModelBuilders\OData2ModelBuilder;

/**
 * Connector for oData 2.0 web services
 * 
 * Refer to the documentation of the HttpConnector for detailed information about connection settings. 
 *
 * @author Andrej Kabachnik
 *        
 */
class OData2Connector extends HttpConnector
{
    private $metadataUrl = null;

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new OData2ModelBuilder($this);
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