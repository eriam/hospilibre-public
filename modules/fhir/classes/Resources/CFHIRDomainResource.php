<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CMbSecurity;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeId;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeNarrative;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeResource;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * FHIR generic resource
 */
abstract class CFHIRDomainResource extends CFHIRResource
{
    /** @var CFHIRDataTypeNarrative */
    public $text;

    /** @var CFHIRDataTypeResource[] */
    public $contained;

    /** @var CFHIRDataTypeExtension[] */
    public $extension;

    /** @var CFHIRDataTypeExtension */
    public $modifierExtension;

    /** @var CFHIRDataTypeIdentifier[] */
    public $identifier;

    /** @var bool */
    public $_use_contained = false;

    /**
     * Build identifier field on resource
     *
     * @param string                       $identifier identifier
     * @param CFHIRDataTypeCodeableConcept $type       type
     * @param string                       $use        use value
     * @param string                       $system     system value
     * @param bool                         $merge
     *
     * @return CFHIRDataTypeIdentifier[]
     */
    public function addIdentifier(
        ?string $identifier = null,
        ?CFHIRDataTypeCodeableConcept $type = null,
        ?string $use = null,
        ?string $system = null,
        bool $merge = true
    ): array {
        if (!$this->identifier) {
            $this->identifier = [];
        }

        $data = CFHIRDataTypeIdentifier::build(
            [
                'type'   => $type ?: null,
                'use'    => $use ? new CFHIRDataTypeCode($use) : null,
                'system' => $system ? new CFHIRDataTypeUri($system) : null,
                'value'  => $identifier ? new CFHIRDataTypeString($identifier) : null,
            ]
        );

        if (!$merge) {
            return [$data];
        }

        return array_merge($this->identifier, [$data]);
    }

    /**
     * @param string|CFHIRResource $resource_or_class
     * @param CStoredObject $object
     *
     * @return CFHIRDataTypeReference
     * @throws InvalidArgumentException|ReflectionException
     */
    public function addReference($resource_or_class, ?CStoredObject $object = null): CFHIRDataTypeReference
    {
        $resource_reference = parent::addReference($resource_or_class, $object);
        if ($this->isContainedActivated()) {
            if ($should_make_mapping = is_string($resource_or_class)) {
                $resource_or_class = new $resource_or_class();
            }
            $contained_resource               = $this->buildFrom($resource_or_class);
            $contained_resource->summary      = true;
            $contained_resource->is_contained = true;

            // mapping only if class is given in parameter
            if ($should_make_mapping) {
                $contained_resource->mapFrom($object);
            }

            // force id for concordance between reference and resource
            $contained_resource->id = new CFHIRDataTypeId(substr($resource_reference->reference->getValue(), 1));

            // link reference and resource
            $resource_reference->setTargetResource($contained_resource);

            // add in contained resources
            $this->contained[] = new CFHIRDataTypeResource($contained_resource);
        }

        return $resource_reference;
    }

    /**
     * @param CFHIRResource $resource
     * @param CStoredObject        $object
     *
     * @return string
     * @throws Exception
     */
    protected function getResourceIdentifier(CFHIRResource $resource, ?CStoredObject $object): string
    {
        if ($this->isContainedActivated()) {
            $identifier = ($object && $object->_id) ? $this->getInternalId($object) : CMbSecurity::generateUUID();

            return "#" . $identifier;
        }

        return parent::getResourceIdentifier($resource, $object);
    }

    /**
     * @return bool
     */
    protected function isContainedActivated(): bool
    {
        // todo utiliser une config ? un params dans l'url ?
        return $this->_use_contained;
    }

    /**
     * Map property extension
     */
    protected function mapExtension(): void
    {
        if ($this->object_mapping) {
            $this->extension = $this->object_mapping->mapExtension();
        }
        // to implement in profiled resources
    }

    protected function mapIdentifier(): void
    {
        if ($this->object_mapping) {
            $this->identifier = $this->object_mapping->mapIdentifier();
        }

        if ($this->object && $this->object->_id) {
            $identifier         = CFHIRDataTypeIdentifier::build(
                [
                    'system' => 'urn:oid:' . CAppUI::conf('mb_oid'),
                    'value'  => $this->object->_id,
                ]
            );
            $this->identifier[] = $identifier;
        }
    }

    /**
     * @return bool
     */
    public function isSummary(): bool
    {
        return $this->summary;
    }

    protected function map(CStoredObject $object): void
    {
        parent::map($object);

        // extension
        $this->mapExtension();

        // identifiers
        $this->mapIdentifier();
    }

    /**
     * Search the first resource in contained field which match with the type
     *
     * @param string      $type
     * @param string|null $with_id
     *
     * @return CFHIRResource|null
     */
    public function getContainedOfType(string $type, string $with_id = null): ?CFHIRResource
    {
        foreach ($this->contained as $datatype_resource) {
            $resource = $datatype_resource->getValue();
            if ($resource instanceof $type) {
                if ($with_id) {
                    if ($resource->id->getValue() !== $with_id) {
                        continue;
                    }
                }

                return $resource;
            }
        }

        return null;
    }
}
