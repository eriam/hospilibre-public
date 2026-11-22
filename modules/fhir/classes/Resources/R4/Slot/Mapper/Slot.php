<?php

/**
 * @package Hospilibre
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Slot\Mapper;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\SlotMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Schedule\CFHIRResourceSchedule;
use Ox\Interop\Fhir\Resources\R4\Slot\CFHIRResourceSlot;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Cabinet\CSlot;

/**
 * Correspondance entre un creneau interne (CSlot) et la ressource FHIR Slot (R4).
 */
class Slot implements DelegatedObjectMapperInterface, SlotMappingInterface
{
    use ResourceTrait;

    /** @var CSlot */
    protected $object;

    /** @var CFHIRResourceSlot */
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
        // rien a preparer
    }

    public function mapExtension(): array
    {
        // non gere
        return [];
    }

    public function mapIdentifier(): array
    {
        // non gere
        return [];
    }

    public function mapServiceCategory(): array
    {
        $coding = CFHIRDataTypeCoding::addCoding(
            'http://terminology.hl7.org/CodeSystem/service-category',
            '35',
            'Hospital'
        );

        return [CFHIRDataTypeCodeableConcept::addCodeable($coding, 'Hospital')];
    }

    public function mapServiceType(): array
    {
        // non gere
        return [];
    }

    /**
     * @throws Exception
     */
    public function mapSpecialty(): array
    {
        return [];
    }

    public function mapAppointmentType(): ?CFHIRDataTypeCodeableConcept
    {
        // non gere
        return null;
    }

    public function mapSchedule(): ?CFHIRDataTypeReference
    {
        return $this->resource->addReference(CFHIRResourceSchedule::class, $this->object);
    }

    public function mapStatus(): ?CFHIRDataTypeCode
    {
        return new CFHIRDataTypeCode($this->object->status);
    }

    public function mapStart(): ?CFHIRDataTypeInstant
    {
        return new CFHIRDataTypeInstant($this->object->start);
    }

    public function mapEnd(): ?CFHIRDataTypeInstant
    {
        return new CFHIRDataTypeInstant($this->object->end);
    }

    public function mapOverbooked(): ?CFHIRDataTypeBoolean
    {
        return new CFHIRDataTypeBoolean($this->object->overbooked);
    }

    public function mapComment(): ?CFHIRDataTypeString
    {
        // non gere
        return null;
    }
}
