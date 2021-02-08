<?php
namespace exface\UrlDataConnector\DataTypes;

use exface\Core\DataTypes\JsonDataType;
use exface\Core\Exceptions\DataTypes\DataTypeCastingError;

/**
 * Special data type for OData __deferred data.
 * 
 * This data type includes the method `findUri()` to extract the `uri` from a deferred entry.
 * 
 * @author Andrej Kabachnik
 *
 */
class ODataDeferredDataType extends JsonDataType
{
    /**
     * 
     * @param string|array|\stdClass $jsonStringOrArray
     * @throws DataTypeCastingError
     * @return string
     */
    public static function findUri($jsonStringOrArray) : string
    {
        switch (true) {
            case is_array($jsonStringOrArray):
                $json = $jsonStringOrArray;
                break;
            case is_string($jsonStringOrArray):
                $json = json_decode($jsonStringOrArray, true);
                break;
            case $jsonStringOrArray instanceof \stdClass:
                return $jsonStringOrArray->uri;
            default:
                throw new DataTypeCastingError('Cannot use "' . $jsonStringOrArray . '" as OData deferred entry: expecting JSON string or PHP array');
        }
        return $json['uri'];
    }
}