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
use exface\UrlDataConnector\QueryBuilders\JsonUrlBuilder;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\ComparatorDataType;

/**
 * Creates simple metamodels from a JSON containing an object or an array of objects. * 
 * 
 * @method HttpConnector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class GeoJsonModelBuilder extends GenericJsonModelBuilder
{   
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\ModelBuilders\GenericJsonModelBuilder::generateAttributesForObject()
     */
    public function generateAttributesForObject(MetaObjectInterface $meta_object, string $addressPattern = '') : DataSheetInterface
    {
        $transaction = $meta_object->getWorkbench()->data()->startTransaction();
        
        $json = json_decode($this->getExampleResponse($meta_object, $addressPattern)->getBody()->__toString(), true);
        if (! $meta_object->getDataAddressProperty(AbstractUrlBuilder::DAP_RESPONSE_DATA_PATH) && ArrayDataType::isAssociative($json)) {
            if ($json['features'] ?? null) {
                $meta_object->setDataAddressProperty(AbstractUrlBuilder::DAP_RESPONSE_DATA_PATH, 'features');
                $ds = DataSheetFactory::createFromObjectIdOrAlias($meta_object->getWorkbench(), 'exface.Core.OBJECT');
                $ds->getColumns()->addFromSystemAttributes();
                $ds->getFilters()->addConditionFromString('UID', $meta_object->getId(), ComparatorDataType::EQUALS);
                $ds->dataRead();
                $ds->setCellValue('DATA_ADDRESS_PROPS', 0, $meta_object->getDataAddressProperties()->toJson());
                $ds->dataUpdate(false, $transaction);
            }
        }
        
        $created_ds = $this->generateAttributes($meta_object, $addressPattern, $transaction);
        
        $transaction->commit();
        
        return $created_ds;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\ModelBuilders\GenericJsonModelBuilder::getAttributeData()
     */
    protected function getAttributeData(array $responseRows, MetaObjectInterface $object)
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($object->getWorkbench(), 'exface.Core.ATTRIBUTE');
        $sheet->setAutoCount(false);
        
        $responseRow = $responseRows[0];
        if (! is_array($responseRow)) {
            return $sheet;
        }
        
        $sheet->addRow([
            'NAME' => 'Geometry',
            'ALIAS' => 'geometry',
            'DATATYPE' => $this->getDataTypeId(DataTypeFactory::createFromPrototype($object->getWorkbench(), JsonDataType::class)),
            'DATA_ADDRESS' => 'geometry'
        ]);
        $sheet->addRow([
            'NAME' => 'Geometry type',
            'ALIAS' => 'geometry_type',
            'DATATYPE' => $this->getDataTypeId(DataTypeFactory::createFromPrototype($object->getWorkbench(), StringDataType::class)),
            'DATA_ADDRESS' => 'geometry/type'
        ]);
        
        if ($properties = $responseRow['properties'] ?? null) {
            $sheet = $this->addAttributeRows($properties, $sheet, 'properties/');
        }
        
        $sheet->getColumns()->addFromAttribute($sheet->getMetaObject()->getAttribute('OBJECT'))->setValueOnAllRows($object->getId());
        
        return $sheet;
    }
}