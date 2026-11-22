<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Api\Transformers;

use Ox\Core\Api\Exceptions\ApiException;
use Ox\Core\Api\Resources\AbstractResource;
use Ox\Core\Api\Resources\Item;
use Ox\Core\CMbFieldSpec;
use Ox\Core\CModelObject;
use Ox\Core\CStoredObject;

class ModelObjectTransformer extends AbstractTransformer
{
    public const RELATION_NAME_KEYWORD = '_relation';

    /**
     * @return array
     * @throws ApiException
     */
    public function createDatas(): array
    {
        /** @var CModelObject $model */
        $model      = $this->item->getDatas();
        $this->type = $this->item->getType() ?? $model::RESOURCE_TYPE;
        $this->id   = $model->_id;
        if ($router = $this->item->getRouter()) {
            $this->links = [
                'self'   => $model->getApiLink($router),
                'schema' => $model->getApiSchemaLink($router, $this->item->getFieldsetsByRelation()),
            ];

            if ($history_link = $model->getApiHistoryLink($router)) {
                $this->links['history'] = $history_link;
            }
        }

        if ($this->item->getWithPermissions() && $model instanceof CStoredObject) {
            $this->meta[AbstractResource::PERMISSIONS_KEYWORD] = [
                'perm' => ($model->getPerm(PERM_EDIT)) ? 'edit' : (($model->getPerm(PERM_READ)) ? 'read' : 'denied'),
            ];
        }

        $this->links = array_merge($this->links, $this->item->getLinks());

        // Default fieldset for item
        if ($this->item->getFieldsetsByRelation() === null) {
            $this->item->addModelFieldset([$model::FIELDSET_DEFAULT]);
        }

        $mapping = $model->getFieldsSpecsByFieldsets($this->item->getFieldsetsByRelation());
        foreach ($mapping as $field_name => $spec) {
            // Access data
            $field_value = $model->$field_name === '' ? null : $model->$field_name;

            $this->attributes[$field_name] = $this->convertType($field_value, $spec->getPHPSpec());
        }

        // Relationships
        if ($this->item->getRecursionDepth() >= self::RECURSION_LIMIT) {
            return $this->render();
        }

        // Default relations
        if ($this->item->getModelRelations() === null) {
            $this->item->setModelRelations($model::RELATIONS_DEFAULT);
        }

        foreach ($this->item->getModelRelations() as $relation_name) {
            // Naming convention
            $method_name = 'getResource' . ucfirst($relation_name);

            if (!method_exists($model, $method_name)) {
                throw new ApiException("Invalid method name '{$method_name}' in class '{$model->_class}'");
            }

            /** @var AbstractResource $resource */
            $resource = $model->$method_name();
            if ($resource === null) {
                continue;
            }

            if (!$resource instanceof AbstractResource) {
                throw new ApiException("Invalid resource returned in class '{$model->_class}::{$method_name}'");
            }

            // Set recursion depth limit
            $resource->setRecursionDepth($this->item->getRecursionDepth() + 1);

            // Set fieldsets on relations
            if ($resource->isModelObjectResource()) {
                $resource->setModelFieldsets($this->item->getFieldsetsByRelation($relation_name) ?? []);
            }

            if ($resource instanceof Item) {
                // Item, only one by relation_name per object
                $this->relationships[$relation_name] = $resource->transform();
            } else {
                // Collection, multiple by relation_name per object
                $relation_datas = $resource->transform();
                foreach ($relation_datas as $relation_data) {
                    $this->relationships[$relation_name][] = $relation_data;
                }
            }
        }

        return $this->render();
    }

    /**
     * Convert the internal application type to an external type (string, bool, int, float).
     *
     * @param mixed        $field_value
     * @param CMbFieldSpec $spec
     *
     * @return mixed
     */
    private function convertType($field_value, string $spec)
    {
        if ($field_value !== null) {
            switch ($spec) {
                case CMbFieldSpec::PHP_TYPE_STRING:
                    // Do not touch strings
                    break;
                case CMbFieldSpec::PHP_TYPE_BOOL:
                case CMbFieldSpec::PHP_TYPE_INT:
                case CMbFieldSpec::PHP_TYPE_FLOAT:
                default:
                    // Force data type
                    settype($field_value, $spec);
            }
        }

        return $field_value;
    }
}
