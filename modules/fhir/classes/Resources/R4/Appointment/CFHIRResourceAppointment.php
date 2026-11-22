<?php

/**
 * @package Hospilibre\fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Appointment;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\AppointmentMappingInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourceAppointmentInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypePositiveInt;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUnsignedInt;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Appointment\CFHIRDataTypeAppointmentParticipant;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Appointment\Mapper\Appointment;
use Ox\Interop\Fhir\Resources\R4\Slot\CFHIRResourceSlot;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\Cabinet\CConsultation;

/**
 * Description
 */
class CFHIRResourceAppointment extends CFHIRDomainResource implements ResourceAppointmentInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'Appointment';

    // attributes
    /** @var CFHIRDataTypeCode */
    public $status;

    /** @var CFHIRDataTypeCodeableConcept */
    public $cancelationReason;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $serviceCategory;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $serviceType;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $specialty;

    /** @var CFHIRDataTypeCodeableConcept */
    public $appointmentType;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $reasonCode;

    /** @var CFHIRDataTypeReference[] */
    public $reasonReference;

    /** @var CFHIRDataTypeUnsignedInt */
    public $priority;

    /** @var CFHIRDataTypeString */
    public $description;

    /** @var CFHIRDataTypeReference[] */
    public $supportingInformation;

    /** @var CFHIRDataTypeInstant */
    public $start;

    /** @var CFHIRDataTypeInstant */
    public $end;

    /** @var CFHIRDataTypePositiveInt */
    public $minutesDuration;

    /** @var CFHIRDataTypeReference[] */
    public $slot;

    /** @var CFHIRDataTypeDateTime */
    public $created;

    /** @var CFHIRDataTypeString */
    public $comment;

    /** @var CFHIRDataTypeString */
    public $patientInstruction;

    /** @var CFHIRDataTypeReference[] */
    public $basedOn;

    /** @var CFHIRDataTypeAppointmentParticipant[] */
    public $participant;

    /** @var CFHIRDataTypePeriod[] */
    public $requestedPeriod;

    /** @var CConsultation */
    protected $object;

    /** @var AppointmentMappingInterface */
    protected $object_mapping;

    /**
     * @inheritdoc
     */
    public function getClass(): ?string
    {
        return CConsultation::class;
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
        if (!$object->_id) {
            return;
        }

        parent::map($object);

        $this->mapStatus();

        $this->mapCancelationReason();

        $this->mapServiceCategory();

        $this->mapServiceType();

        $this->mapSpecialty();

        $this->mapAppointmentType();

        $this->mapReasonCode();

        $this->mapReasonReference();

        $this->mapPriority();

        $this->mapDescription();

        $this->mapSupportingInformation();

        $this->mapStart();

        $this->mapEnd();

        $this->mapMinutesDuration();

        $this->mapSlot();

        $this->mapCreated();

        $this->mapComment();

        $this->mapPatientInstruction();

        $this->mapBasedOn();

        $this->mapParticipant();

        $this->mapRequestedPeriod();
    }

    /**
     * @inheritdoc
     * @throws Exception
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CConsultation) {
            throw  new CFHIRException("Object is not an appointment");
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
        $mapping_object = Appointment::class;

        return new $mapping_object();
    }

    /**
     * Map property status
     */
    protected function mapStatus(): void
    {
        $this->status = $this->object_mapping->mapStatus();
    }

    /**
     * Map property cancelationReason
     */
    protected function mapCancelationReason(): void
    {
        $this->cancelationReason = $this->object_mapping->mapCancelationReason();
    }

    /**
     * Map property serviceCategory
     */
    protected function mapServiceCategory(): void
    {
        $this->serviceCategory = $this->object_mapping->mapServiceCategory();
    }

    /**
     * Map property serviceType
     * @throws Exception
     */
    protected function mapServiceType(): void
    {
        $this->serviceType = $this->object_mapping->mapServiceType();
    }

    /**
     * Map property specialty
     */
    protected function mapSpecialty(): void
    {
        $this->specialty = $this->object_mapping->mapSpecialty();
    }

    /**
     * Map property appointmentType
     */
    protected function mapAppointmentType(): void
    {
        // not implemented
        $this->appointmentType = $this->object_mapping->mapAppointmentType();
    }

    /**
     * Map property reasonCode
     */
    protected function mapReasonCode(): void
    {
        // not implemented
        $this->reasonCode = $this->object_mapping->mapReasonCode();
    }

    /**
     * Map property reasonReference
     */
    protected function mapReasonReference(): void
    {
        $this->reasonReference = $this->object_mapping->mapReasonReference();
    }

    /**
     * Map property priority
     */
    protected function mapPriority(): void
    {
        $this->priority = $this->object_mapping->mapPriority();
    }

    /**
     * Map property description
     */
    protected function mapDescription(): void
    {
        $this->description = $this->object_mapping->mapDescription();
    }

    /**
     * Map property supportingInformation
     */
    protected function mapSupportingInformation(): void
    {
        $this->supportingInformation = $this->object_mapping->mapSupportingInformation();
    }

    /**
     * Map property start
     */
    protected function mapStart(): void
    {
        $this->start = $this->object_mapping->mapStart();
    }

    /**
     * Map property end
     */
    protected function mapEnd(): void
    {
        $this->end = $this->object_mapping->mapEnd();
    }

    /**
     * Map property minutesDuration
     */
    protected function mapMinutesDuration(): void
    {
        $this->minutesDuration = $this->object_mapping->mapMinutesDuration();
    }

    /**
     * Map property slot
     */
    protected function mapSlot(string $resource_class = CFHIRResourceSlot::class): void
    {
        $this->slot = $this->object_mapping->mapSlot($resource_class);
    }

    /**
     * Map property created
     */
    protected function mapCreated(): void
    {
        $this->created = $this->object_mapping->mapCreated();
    }

    /**
     * Map property comment
     */
    protected function mapComment(): void
    {
        $this->comment = $this->object_mapping->mapComment();
    }

    /**
     * Map property patientInstruction
     */
    protected function mapPatientInstruction(): void
    {
        $this->patientInstruction = $this->object_mapping->mapPatientInstruction();
    }

    /**
     * Map property basedOn
     */
    protected function mapBasedOn(): void
    {
        $this->basedOn = $this->object_mapping->mapBasedOn();
    }

    /**
     * Map property participant
     */
    protected function mapParticipant(): void
    {
        $this->participant = $this->object_mapping->mapParticipant();
    }

    /**
     * Map property requestedPeriod
     * @throws Exception
     */
    protected function mapRequestedPeriod(): void
    {
        $this->requestedPeriod = $this->object_mapping->mapRequestedPeriod();
    }
}
