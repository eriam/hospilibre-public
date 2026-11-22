<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Device\Mapper;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\DeviceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\R4\Device\CFHIRResourceDevice;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Ox\Interop\Mes\CDevice;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Mapper FHIR R4 pour la ressource Device.
 */
class Device implements DelegatedObjectMapperInterface, DeviceMappingInterface
{
    use ResourceTrait;

    /** @var CDevice */
    protected $object;

    /** @var CFHIRResourceDevice */
    protected $resource;

    public function setResource(CFHIRResource $resource, CStoredObject $object): void
    {
        $this->resource = $resource;
        $this->object   = $object;
    }

    public function getProfiles(): array
    {
        return [CFHIR::class];
    }

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        // Aucune initialisation particuliere requise pour ce mapper.
    }

    /**
     * @inheritDoc
     */
    public function mapExtension(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     * @throws CFHIRException
     */
    public function mapIdentifier(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapDefinition(): ?CFHIRDataTypeReference
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapUdiCarrier(): ?array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapStatus(): ?CFHIRDataTypeCode
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapStatusReason(): ?CFHIRDataTypeCodeableConcept
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapDistinctIdentifier(): ?CFHIRDataTypeString
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapManufacturer(): ?CFHIRDataTypeString
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapManufactureDate(): ?CFHIRDataTypeDateTime
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapExpirationDate(): ?CFHIRDataTypeDateTime
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapLotNumber(): ?CFHIRDataTypeString
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapSerialNumber(): ?CFHIRDataTypeString
    {
        return null;
    }

    /**
     * @inheritDoc
     * @throws CFHIRException
     */
    public function mapDeviceName(): ?array
    {
        try {
            $deviceName = CFHIRDataTypeBackboneElement::build(
                [
                    "name" => $this->object->name,
                    "type" => new CFHIRDataTypeCode("patient-reported-name"),
                ]
            );

            return [$deviceName];
        } catch (InvalidArgumentException | ReflectionException $exception) {
            throw new CFHIRException($exception);
        }
    }

    /**
     * @inheritDoc
     */
    public function mapModelNumber(): ?CFHIRDataTypeString
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapPartNumber(): ?CFHIRDataTypeString
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapType(): ?CFHIRDataTypeCodeableConcept
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapSpecialization(): ?array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapVersion(): ?array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapProperty(): ?array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapPatient(): ?CFHIRDataTypeReference
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapOwner(): ?CFHIRDataTypeReference
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapContact(): ?array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapLocation(): ?CFHIRDataTypeReference
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapUrl(): ?CFHIRDataTypeUri
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapNote(): ?array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapSafety(): ?array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapParent(): ?CFHIRDataTypeReference
    {
        return null;
    }
}
