<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Device;

use Exception;
use Ox\Core\CMbSecurity;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeId;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAnnotation;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Device\Mapper\Device;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Ox\Interop\Mes\CDevice;

/**
 * FHIR device resource
 */
class CFHIRResourceDevice extends CFHIRDomainResource
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'Device';

    // attributes
    public ?CFHIRDataTypeReference $definition = null;

    /** @var CFHIRDataTypeBackboneElement[]|null */
    public ?array $udiCarrier = [];

    public ?CFHIRDataTypeCode $status = null;

    /** @var CFHIRDataTypeCodeableConcept[]|null */
    public ?array $statusReason = [];

    public ?CFHIRDataTypeString $distinctIdentifier = null;

    public ?CFHIRDataTypeString $manufacturer = null;

    public ?CFHIRDataTypeDateTime $manufactureDate = null;

    public ?CFHIRDataTypeDateTime $expirationDate = null;

    public ?CFHIRDataTypeString $lotNumber = null;

    public ?CFHIRDataTypeString $serialNumber = null;

    /** @var CFHIRDataTypeBackboneElement[]|null */
    public ?array $deviceName = [];

    public ?CFHIRDataTypeString $modelNumber = null;

    public ?CFHIRDataTypeString $partNumber = null;

    public ?CFHIRDataTypeCodeableConcept $type = null;

    /** @var CFHIRDataTypeBackboneElement[]|null */
    public ?array $specialization = [];

    /** @var CFHIRDataTypeBackboneElement[]|null */
    public ?array $version = [];

    /** @var CFHIRDataTypeBackboneElement[]|null */
    public ?array $property = [];

    public ?CFHIRDataTypeReference $patient = null;

    public ?CFHIRDataTypeReference $owner = null;

    /** @var CFHIRDataTypeContactPoint[]|null */
    public ?array $contact = [];

    public ?CFHIRDataTypeReference $location = null;

    public ?CFHIRDataTypeUri $url = null;

    /** @var CFHIRDataTypeAnnotation[]|null */
    public ?array $note = [];

    /** @var CFHIRDataTypeCodeableConcept[]|null */
    public ?array $safety = [];

    public ?CFHIRDataTypeReference $parent = null;

    /** @var CDevice */
    protected $object;

    /** @var Device */
    protected $object_mapping;

    /**
     * return CStoredObject
     */
    public function getClass(): ?string
    {
        return CStoredObject::class;
    }

    /**
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return (parent::generateCapabilities())
            ->addInteractions(
                [
                    CFHIRInteractionCreate::NAME,
                    CFHIRInteractionRead::NAME,
                    CFHIRInteractionSearch::NAME,
                    CFHIRInteractionUpdate::NAME,
                    CFHIRInteractionDelete::NAME,
                ]
            );
    }

    /**
     * @inheritdoc
     *
     * @param CStoredObject $object
     */
    final protected function map(CStoredObject $object): void
    {
        if ($this->object_mapping) {
            if (!$object->_id) {
                return;
            }

            parent::map($object);

            $this->mapDefinition();

            $this->mapUdiCarrier();

            $this->mapStatus();

            $this->mapStatusReason();

            $this->mapDistinctIdentifier();

            $this->mapManufacturer();

            $this->mapManufactureDate();

            $this->mapExpirationDate();

            $this->mapLotNumber();

            $this->mapSerialNumber();

            $this->mapDeviceName();

            $this->mapModelNumber();

            $this->mapPartNumber();

            $this->mapType();

            $this->mapSpecialization();

            $this->mapVersion();

            $this->mapProperty();

            $this->mapPatient();

            $this->mapOwner();

            $this->mapContact();

            $this->mapLocation();

            $this->mapUrl();

            $this->mapNote();

            $this->mapSafety();

            $this->mapParent();
        }
    }

    /**
     * @throws Exception
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CStoredObject) {
            throw new CFHIRException("Object is not a device");
        }

        $this->map($object);
    }

    /**
     * @param CStoredObject $object
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        $mapping_object = Device::class;

        return new $mapping_object();
    }

    /**
     * Map property definition
     */
    protected function mapDefinition(): void
    {
        $this->definition = $this->object_mapping->mapDefinition();
    }

    /**
     * Map property udiCarrier
     */
    protected function mapUdiCarrier(): void
    {
        $this->udiCarrier = $this->object_mapping->mapUdiCarrier();
    }

    /**
     * Map property status
     */
    protected function mapStatus(): void
    {
        $this->status = $this->object_mapping->mapStatus();
    }

    /**
     * Map property statusReason
     */
    protected function mapStatusReason(): void
    {
        $this->statusReason = $this->object_mapping->mapStatusReason();
    }

    /**
     * Map property mapDistinctIdentifier
     */
    protected function mapDistinctIdentifier(): void
    {
        $this->distinctIdentifier = $this->object_mapping->mapDistinctIdentifier();
    }

    /**
     * Map property manufacturer
     */
    protected function mapManufacturer(): void
    {
        $this->manufacturer = $this->object_mapping->mapManufacturer();
    }

    /**
     * Map property manufactureDate
     */
    protected function mapManufactureDate(): void
    {
        $this->manufactureDate = $this->object_mapping->mapManufactureDate();
    }

    /**
     * Map property expirationDate
     */
    protected function mapExpirationDate(): void
    {
        $this->expirationDate = $this->object_mapping->mapExpirationDate();
    }

    /**
     * Map property lotNumber
     */
    protected function mapLotNumber(): void
    {
        $this->lotNumber = $this->object_mapping->mapLotNumber();
    }

    /**
     * Map property serialNumber
     */
    protected function mapSerialNumber(): void
    {
        $this->serialNumber = $this->object_mapping->mapSerialNumber();
    }

    /**
     * Map property deviceName
     */
    protected function mapDeviceName(): void
    {
        $this->deviceName = $this->object_mapping->mapDeviceName();
    }

    /**
     * Map property modelNumber
     */
    protected function mapModelNumber(): void
    {
        $this->modelNumber = $this->object_mapping->mapModelNumber();
    }

    /**
     * Map property partNumber
     */
    protected function mapPartNumber(): void
    {
        $this->partNumber = $this->object_mapping->mapPartNumber();
    }

    /**
     * Map property type
     */
    protected function mapType(): void
    {
        $this->type = $this->object_mapping->mapType();
    }

    /**
     * Map property specialization
     */
    protected function mapSpecialization(): void
    {
        $this->specialization = $this->object_mapping->mapSpecialization();
    }

    /**
     * Map property version
     */
    protected function mapVersion(): void
    {
        $this->version = $this->object_mapping->mapVersion();
    }

    /**
     * Map property property
     */
    protected function mapProperty(): void
    {
        $this->property = $this->object_mapping->mapProperty();
    }

    /**
     * Map property patient
     */
    protected function mapPatient(): void
    {
        $this->patient = $this->object_mapping->mapPatient();
    }

    /**
     * Map property owner
     */
    protected function mapOwner(): void
    {
        $this->owner = $this->object_mapping->mapOwner();
    }

    /**
     * Map property contact
     */
    protected function mapContact(): void
    {
        $this->contact = $this->object_mapping->mapContact();
    }

    /**
     * Map property location
     */
    protected function mapLocation(): void
    {
        $this->location = $this->object_mapping->mapLocation();
    }

    /**
     * Map property url
     */
    protected function mapUrl(): void
    {
        $this->url = $this->object_mapping->mapUrl();
    }

    /**
     * Map property note
     */
    protected function mapNote(): void
    {
        $this->note = $this->object_mapping->mapNote();
    }

    /**
     * Map property safety
     */
    protected function mapSafety(): void
    {
        $this->safety = $this->object_mapping->mapSafety();
    }

    /**
     * Map property parent
     */
    protected function mapParent(): void
    {
        $this->parent = $this->object_mapping->mapParent();
    }
}
