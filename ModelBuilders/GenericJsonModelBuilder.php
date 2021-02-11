<?php
namespace exface\UrlDataConnector\ModelBuilders;

use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use Psr\Http\Message\ResponseInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\DataTypes\ArrayDataType;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\UrlDataConnector\QueryBuilders\AbstractUrlBuilder;

/**
 * Creates simple metamodels from a JSON containing an object or an array of objects. * 
 * 
 * @method HttpConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class GenericJsonModelBuilder extends GenericUrlModelBuilder
{    
    private $responses = [];
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\ModelBuilders\AbstractModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object, string $addressPattern = '') : DataSheetInterface
    {
        $transaction = $meta_object->getWorkbench()->data()->startTransaction();
        
        $created_ds = $this->generateAttributes($meta_object, $addressPattern, $transaction);
        
        $transaction->commit();
        
        return $created_ds;
    }
    
    /**
     * Generates the attributes for a given meta object and saves them in the model.
     * 
     * @param MetaObjectInterface $meta_object
     * @param DataTransactionInterface $transaction
     * 
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function generateAttributes(MetaObjectInterface $meta_object, string $addressPattern = '', DataTransactionInterface $transaction = null)
    {
        $created_ds = DataSheetFactory::createFromObjectIdOrAlias($meta_object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $created_ds->setAutoCount(false);
        
        $response = $this->getExampleResponse($meta_object, $addressPattern);
        $responseData = json_decode($response->getBody()->__toString(), true);
        $rowData = $this->findRowData($responseData, $meta_object->getDataAddressProperty(AbstractUrlBuilder::DAP_RESPONSE_DATA_PATH));
        $imported_rows = $this->getAttributeData($rowData, $meta_object)->getRows();
        
        foreach ($imported_rows as $row) {
            if (count($meta_object->findAttributesByDataAddress($row['DATA_ADDRESS'])) === 0) {
                $created_ds->addRow($row);
            }
        }
        $created_ds->setCounterForRowsInDataSource(count($imported_rows));
        
        if (! $created_ds->isEmpty()) {
            $created_ds->dataCreate(false, $transaction);
            // Reload object model and recreate the data sheet, so it is based on the refreshed object
            $refreshed_object = $meta_object->getWorkbench()->model()->reloadObject($meta_object);
            $uxon = $created_ds->exportUxonObject();
            $created_ds = DataSheetFactory::createFromObject($refreshed_object);
            $created_ds->importUxonObject($uxon);
        }
        
        return $created_ds;
    }
    
    /**
     * 
     * @param array $responseRows
     * @param MetaObjectInterface $object
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function getAttributeData(array $responseRows, MetaObjectInterface $object)
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $sheet->setAutoCount(false);
        
        $responseRow = $responseRows[0];
        if (! is_array($responseRow)) {
            return $sheet;
        }
        
        $sheet = $this->addAttributeRows($responseRow, $sheet);
        $sheet->getColumns()->addFromAttribute($sheet->getMetaObject()->getAttribute('OBJECT'))->setValueOnAllRows($object->getId());
        
        return $sheet;
    }
    
    /**
     * 
     * @param array $data
     * @param DataSheetInterface $sheet
     * @param string $pathPrefix
     * @param string $aliasPrefix
     * @param string $namePrefix
     * 
     * @return DataSheetInterface
     */
    protected function addAttributeRows(array $data, DataSheetInterface $sheet, string $pathPrefix = '', string $aliasPrefix = '', string $namePrefix = '') : DataSheetInterface
    {
        foreach ($data as $key => $val) {
            $name = $this->generateLabel($key);
            
            switch (true) {
                case is_array($val) && ArrayDataType::isAssociative($val):
                    $sheet = $this->addAttributeRows($val, $sheet, $pathPrefix . $key . '/', $aliasPrefix . $key . '_', $namePrefix . $name . ' ');
                    break;
                default:
                    $dataType = $this->guessDataType($sheet->getWorkbench(), $val);
                
                    $row = [
                        'NAME' => $namePrefix . $this->generateLabel($key),
                        'ALIAS' => $aliasPrefix . $key,
                        'DATATYPE' => $this->getDataTypeId($dataType),
                        'DATA_ADDRESS' => $pathPrefix . $key
                    ];
                    
                    $sheet->addRow($row);
            }
        }
        
        return $sheet;
    }
    
    /**
     * Attempts to make a given XML node name a bit mor human readable.
     * 
     * @param string $nodeName
     * @return string
     */
    protected function generateLabel($nodeName) {
        $string = StringDataType::convertCasePascalToUnderscore($nodeName);
        $string = str_replace('_', ' ', $string);
        $string = ucwords($string);
        return $string;
    }
    
    /**
     * Returns the meta data type, that fit's the given XML node best.
     *
     * @param MetaObjectInterface $object
     * @param \DOMElement $node
     * @return DataTypeInterface
     */
    protected function guessDataType(WorkbenchInterface $workbench, $value) : DataTypeInterface
    {
        switch (true) {
            case is_bool($value):
                $type = DataTypeFactory::createFromString($workbench, BooleanDataType::class);
                break;
            case is_int($value):
                $type = DataTypeFactory::createFromString($workbench, IntegerDataType::class);
                break;
            case is_float($value):
                $type = DataTypeFactory::createFromString($workbench, NumberDataType::class);
                break;
            default:
                $type = DataTypeFactory::createFromString($workbench, StringDataType::class);
        }
        return $type;
    }
    
    /**
     * 
     * @param MetaObjectInterface $object
     * @return ResponseInterface
     */
    protected function getExampleResponse(MetaObjectInterface $object, string $addressPattern = '') : ResponseInterface
    {
        return $this->responses[$addressPattern] ?? $this->getDataConnection()->sendRequest($this->getExampleRequest($object, $addressPattern));
    }
    
    /**
     * 
     * @param ResponseInterface $value
     * @return GenericJsonModelBuilder
     */
    public function setExampleResponse(ResponseInterface $value, string $addressPattern = '') : GenericJsonModelBuilder
    {
        $this->responses[$addressPattern] = $value;
        return $this;
    }
    
    /**
     * 
     * @param array $parsed_data
     * @param string $path
     * @return array|NULL
     */
    protected function findRowData(array $parsed_data, string $path) : ?array
    {
        // Get the actual data
        if ($path) {
            // If a path could be determined, follow it
            // $rows = $parsed_data[$path];
            $rows = ArrayDataType::filterXPath($parsed_data, $path);
        } else {
            // If no path specified, try to find the data automatically
            if (count(array_filter(array_keys($parsed_data), 'is_string'))) {
                // If data is an assotiative array, it is most likely to represent one single row
                $rows = array(
                    $parsed_data
                );
            } else {
                // If the data is a sequential array with numeric keys, it is most likely to represent multiple rows
                $rows = $parsed_data;
            }
        }
        
        return $rows;
    }
}