<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Contracts\Mapping\R4;

use Ox\Interop\Fhir\Contracts\Mapping\ResourceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypePositiveInt;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUnsignedInt;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Appointment\CFHIRDataTypeAppointmentParticipant;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;

/**
 * Contrat de conversion d'un rendez-vous vers la ressource FHIR R4 « Appointment ».
 *
 * Chaque méthode « map* » produit la valeur d'un champ de la ressource : soit un
 * datatype FHIR (éventuellement nul), soit un tableau lorsque le champ est répété.
 */
interface AppointmentMappingInterface extends ResourceMappingInterface
{
    /** Type de ressource FHIR ciblé. */
    public const RESOURCE_TYPE = "Appointment";

    /**
     * Champ « status ».
     */
    public function mapStatus(): ?CFHIRDataTypeCode;

    /**
     * Champ « cancelationReason ».
     */
    public function mapCancelationReason(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Champ « serviceCategory » (répété).
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapServiceCategory(): array;

    /**
     * Champ « serviceType » (répété).
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapServiceType(): array;

    /**
     * Champ « specialty » (répété).
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapSpecialty(): array;

    /**
     * Champ « appointmentType ».
     */
    public function mapAppointmentType(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Champ « reasonCode » (répété).
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapReasonCode(): array;

    /**
     * Champ « reasonReference » (répété).
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapReasonReference(): array;

    /**
     * Champ « priority ».
     */
    public function mapPriority(): ?CFHIRDataTypeUnsignedInt;

    /**
     * Champ « description ».
     */
    public function mapDescription(): ?CFHIRDataTypeString;

    /**
     * Champ « supportingInformation » (répété).
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapSupportingInformation(): array;

    /**
     * Champ « start ».
     */
    public function mapStart(): ?CFHIRDataTypeInstant;

    /**
     * Champ « end ».
     */
    public function mapEnd(): ?CFHIRDataTypeInstant;

    /**
     * Champ « minutesDuration ».
     */
    public function mapMinutesDuration(): ?CFHIRDataTypePositiveInt;

    /**
     * Champ « slot » (répété).
     *
     * @param string $resource_class Classe de ressource associée au créneau.
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapSlot(string $resource_class): array;

    /**
     * Champ « created ».
     */
    public function mapCreated(): ?CFHIRDataTypeDateTime;

    /**
     * Champ « comment ».
     */
    public function mapComment(): ?CFHIRDataTypeString;

    /**
     * Champ « patientInstruction ».
     */
    public function mapPatientInstruction(): ?CFHIRDataTypeString;

    /**
     * Champ « basedOn » (répété).
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapBasedOn(): array;

    /**
     * Champ « participant » (répété).
     *
     * @return CFHIRDataTypeAppointmentParticipant[]
     */
    public function mapParticipant(): array;

    /**
     * Champ « requestedPeriod » (répété).
     *
     * @return CFHIRDataTypePeriod[]
     */
    public function mapRequestedPeriod(): array;
}
