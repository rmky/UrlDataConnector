<?php
namespace exface\UrlDataConnector\DataConnectors;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7;
use exface\UrlDataConnector\Psr7DataQuery;
use exface\Core\Interfaces\DataSources\DataQueryInterface;
use exface\Core\Exceptions\DataSources\DataConnectionQueryTypeError;
use exface\Core\Exceptions\DataSources\DataQueryFailedError;

class FileUriConnector extends AbstractUrlConnector
{

    private $hideFileMissingError = null;
    
    /**
     *
     * {@inheritdoc}
     *
     * @see \exface\Core\CommonLogic\AbstractDataConnector::performConnect()
     */
    protected function performConnect()
    {
        return;
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
        if (! ($query instanceof Psr7DataQuery))
            throw new DataConnectionQueryTypeError($this, 'Connector "' . $this->getAliasWithNamespace() . '" expects a Psr7DataQuery as input, "' . get_class($query) . '" given instead!');
        
        /* @var $query \exface\UrlDataConnector\Psr7DataQuery */
        if (! $file_path = $query->getRequest()->getUri()->__toString()) {
            return array();
        }
        
        if ($question_mark = strpos($file_path, '?')) {
            $file_path = substr($file_path, 0, $question_mark);
        }
        
        if (! $this->getWorkbench()->filemanager()->isAbsolutePath($file_path)) {
            $file_path = $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $file_path;
        }
        
        if (! file_exists($file_path)) {
            if ($this->getHideFileMissingError()) {
                $query->setResponse(new Response(200, array()));
                return $query;
            }
            throw new DataQueryFailedError($query, 'File not found: "' . $file_path . '"!');
        }
        
        $query->setResponse(new Response(200, array(), Psr7\stream_for(fopen($file_path, 'r'))));
        return $query;
    }
    
    /**
     * Set if the error that the requested file doesn't exist should be hidden or not. If no error should be shown the response body is empty.
     * By default the error will be shown.
     * 
     * @uxon-property hide_file_missing_error
     * @uxon-type boolean
     * 
     * @param bool $trueOrFalse
     * @return FileUriConnector
     */
    public function setHideFileMissingError(bool $trueOrFalse) : FileUriConnector
    {
        $this->hideFileMissingError = $trueOrFalse;
        return $this;
    }
    
    protected function getHideFileMissingError() : bool
    {
        return $this->hideFileMissingError ?: false;
    }
}
?>