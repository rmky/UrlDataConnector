<?php
namespace exface\UrlDataConnector\CommonLogic;

use exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface;
use exface\UrlDataConnector\Interfaces\HttpConnectionInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\UrlDataConnector\Uxon\HttpAuthenticationSchema;

/**
 * Base class to implement interface HttpAuthenticationProviderInterface
 * 
 * This class provides the constructor and methods depending on it as well
 * as the link to a custom UXON schema for HTTP authentication providers.
 * 
 * @author Andrej Kabachnik
 *
 */
abstract class AbstractHttpAuthenticationProvider implements HttpAuthenticationProviderInterface
{
    use ImportUxonObjectTrait;
    
    private $connection = null;
    
    private $constructorUxon = null;
    
    /**
     * 
     * @param HttpConnectionInterface $dataConnection
     * @param UxonObject $uxon
     */
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
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        return $this->constructorUxon ?? new UxonObject();
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\Interfaces\HttpAuthenticationProviderInterface::getConnection()
     */
    public function getConnection() : HttpConnectionInterface
    {
        return $this->connection;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\iCanBeConvertedToUxon::getUxonSchemaClass()
     */
    public static function getUxonSchemaClass() : ?string
    {
        return HttpAuthenticationSchema::class;
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
}