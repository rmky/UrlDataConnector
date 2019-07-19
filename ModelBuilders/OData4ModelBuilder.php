<?php
namespace exface\UrlDataConnector\ModelBuilders;

use exface\Core\Interfaces\AppInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use Symfony\Component\DomCrawler\Crawler;
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;
use exface\Core\Exceptions\ModelBuilders\ModelBuilderRuntimeError;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;

/**
 * 
 * @method OData4Connector getDataConnection()
 * 
 * @author Andrej Kabachnik
 *
 */
class OData4ModelBuilder extends OData2ModelBuilder {
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\ModelBuilders\OData2ModelBuilder::generateRelations()
     */
    protected function generateRelations(AppInterface $app, MetaObjectInterface $targetObject = null, DataTransactionInterface $transaction = null)
    {
        // If no nodes specified, get all constraint nodes from the metadata
        if ($targetObject === null) {
            $referentialConstraints = $this->getMetadata()->filterXPath('//default:EntityType/default:NavigationProperty/default:ReferentialConstraint');
        } else {
            $entityType = $this->getEntityType($targetObject);
            $referentialConstraints = $this->findReferentialConstraints($entityType);
        }
        
        $new_relations = DataSheetFactory::createFromObjectIdOrAlias($app->getWorkbench(), 'exface.Core.ATTRIBUTE');
        foreach ($new_relations->getMetaObject()->getAttributes()->getSystem() as $sys) {
            $new_relations->getColumns()->addFromAttribute($sys);
        }
        $skipped = 0;
        
        foreach ($referentialConstraints as $node) {
            // Find object on both ends of the relation. If not there, log an info and skip the relation
            try {
                $relationAlias = $node->parentNode->attributes['Name']->nodeValue;
                $relationAddress = $node->getAttribute('Property');
                $entityType = $this->stripNamespace($node->parentNode->parentNode->attributes['Name']->nodeValue);
                $object = $app->getWorkbench()->model()->getObjectByAlias($entityType, $app->getAliasWithNamespace());
                
                $relatedEntityType = $this->stripNamespace($node->parentNode->attributes['Type']->nodeValue);
                $relatedObject = $app->getWorkbench()->model()->getObjectByAlias($relatedEntityType, $app->getAliasWithNamespace());
                $relatedObjectKeyAlias = $node->getAttribute('ReferencedProperty');
            } catch (MetaObjectNotFoundError $e) {
                $app->getWorkbench()->getLogger()->logException(new ModelBuilderRuntimeError($this, 'Cannot find related object for NavigationProperty ' . $relationAlias . ': EntityType ' . $relatedEntityType . ' not imported yet?', null, $e), LoggerInterface::INFO);
                continue;
            }
            
            // If the object has no relation matching the alias or the relation is not 
            $relationAttribute = null;
            foreach ($object->findAttributesByDataAddress($relationAddress) as $attr) {
                if ($attr->isRelation() && $attr->getRelation()->getRightObject()->isExactly($relatedObject)) {
                    $skipped++;
                    continue;
                } elseif (! is_null($relationAttribute)) {
                    throw new ModelBuilderRuntimeError($this, 'Cannot create relation for object ' . $object->getAliasWithNamespace() . ' automatically: found multiple attributes (' . $attr->getAlias() . ', ' . $relationAttribute->getAlias() . ') matching the data address and not being a relation - cannot decide, which one to make a relation!.');
                } else {
                    $relationAttribute = $attr;
                }
            }
            
            if ($skipped === 0) {
                if (is_null($relationAttribute)) {
                    throw new ModelBuilderRuntimeError($this, 'Cannot create relation for object ' . $object->getAliasWithNamespace() . ' automatically: no attribute found with relation property "' . $relationAddress . '" as data address - please rebuild the model for object ' . $object->getAliasWithNamespace() . '!');
                }
                
                // Add relation data to the data sheet: just those fields, that will mark the attribute as a relation
                $new_relations->addRow([
                    $new_relations->getMetaObject()->getUidAttributeAlias() => $relationAttribute->getId(),
                    'NAME' => $this->generateLabel($relationAlias),
                    'ALIAS' => $relationAlias,
                    'RELATED_OBJ_ATTR' => ($relatedObject->getUidAttributeAlias() !== $relatedObjectKeyAlias ? $relatedObjectKeyAlias : ''),
                    'RELATED_OBJ' => $relatedObject->getId()
                ]);
            }
        }
        
        $new_relations->setCounterForRowsInDataSource($new_relations->countRows() + $skipped);
        
        if (! $new_relations->isEmpty()) {
            $new_relations->dataUpdate(false, $transaction);
        }
        
        return $new_relations;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UrlDataConnector\ModelBuilders\OData2ModelBuilder::findReferentialConstraints()
     */
    protected function findReferentialConstraints(string $entityType) : Crawler
    {
        return $this->getMetadata()->filterXPath($this->getXPathToProperties($entityType))->siblings()->filterXPath('default:NavigationProperty/default:ReferentialConstraint');
    }
}