<?php
namespace exface\UrlDataConnector\ModelBuilders;

use exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Exceptions\ModelBuilders\ModelBuilderRuntimeError;
use exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder;
use Psr\Http\Message\RequestInterface;
use exface\Core\Factories\QueryBuilderFactory;
use exface\Core\DataTypes\MimeTypeDataType;

/**
 * Attempts to create a metamodel stub from an example response from the server
 * 
 * @method HttpConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class GenericUrlModelBuilder extends AbstractModelBuilder {
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object, string $addressPattern = '') : DataSheetInterface
    {
        
        $response = $this->getDataConnection()->sendRequest($this->getExampleRequest($meta_object, $addressPattern));
        $contentType = $response->getHeader('Content-Type')[0];
        switch (true) {
            case MimeTypeDataType::detectJson($contentType):
                $modelBuilder = new GenericJsonModelBuilder($this->getDataConnection());
                $modelBuilder->setExampleResponse($response, $addressPattern);
                break;
            // TODO add model builder for XML and maybe other content types
            default:
                throw new ModelBuilderRuntimeError($this, 'Cannot find a generic model builder for content type "' . $contentType . '": please create a metamodel manually!');
        }
        
        return $modelBuilder->generateAttributesForObject($meta_object, $addressPattern);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataSources\ModelBuilderInterface::generateObjectsForDataSource()
     */
    public function generateObjectsForDataSource(AppInterface $app, DataSourceInterface $source, string $data_address_mask = null) : DataSheetInterface
    {
        throw new ModelBuilderRuntimeError($this, 'Cannot generate meta objects via GenericUrlModelBuilder: please create the meta object manually and use the model builder to generate attributes!');
    }
    
    /**
     * 
     * @param MetaObjectInterface $meta_object
     * @throws ModelBuilderRuntimeError
     * @return RequestInterface
     */
    protected function getExampleRequest(MetaObjectInterface $meta_object, string $addressPattern = '') : RequestInterface
    {
        $queryBuilder = QueryBuilderFactory::createForObject($meta_object);
        if (! ($queryBuilder instanceof AbstractUrlBuilder)) {
            throw new ModelBuilderRuntimeError($this, 'Query builder "' . get_class($queryBuilder) . '" not supported by GenericUrlModelBuilder!');
        }
        
        $request = $queryBuilder->buildRequestToRead();
        if ($addressPattern && $addressPattern !== $meta_object->getDataAddress()) {
            $uri = $request->getUri();
            
            list($addressPath, $addressQuery) = explode('?', $addressPattern);
            
            $uri = $uri->withPath($addressPath);
            if ($addressQuery) {
                $uri = $uri->withQuery($addressQuery);
            }
            $request = $request->withUri($uri);
        }
        
        return $request;
    }
}