<?php
namespace exface\UrlDataConnector\DataConnectors;

use exface\UrlDataConnector\ModelBuilders\GeoJsonModelBuilder;

/**
 * Connector for GeoJSON web services
 * 
 * For more options like authentification, etc. refer to the documentation of the generic 
 * `HttpConnector`. 
 *
 * @author Andrej Kabachnik
 *        
 */
class GeoJsonConnector extends HttpConnector
{
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractDataConnector::getModelBuilder()
     */
    public function getModelBuilder()
    {
        return new GeoJsonModelBuilder($this);
    }
}