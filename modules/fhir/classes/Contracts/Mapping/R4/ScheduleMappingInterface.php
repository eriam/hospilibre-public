<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Contracts\Mapping\R4;

use Ox\Interop\Fhir\Contracts\Mapping\ResourceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypePositiveInt;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUnsignedInt;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Appointment\CFHIRDataTypeAppointmentParticipant;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Encounter\CFHIRDataTypeEncounterParticipant;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;

/**
 * Contrat de mapping pour la ressource FHIR R4 « Schedule ».
 *
 * Chaque methode expose la transformation d'un attribut de la ressource
 * vers son type de donnee FHIR correspondant.
 */
interface ScheduleMappingInterface extends ResourceMappingInterface
{
    /** @var string Type de ressource FHIR gere par ce contrat */
    public const RESOURCE_TYPE = "Schedule";

    /**
     * Transforme l'attribut « identifier ».
     *
     * @return CFHIRDataTypeIdentifier[]
     */
    public function mapIdentifier(): array;

    /**
     * Transforme l'attribut « active ».
     *
     * @return CFHIRDataTypeCode|null
     */
    public function mapActive(): ?CFHIRDataTypeBoolean;

    /**
     * Transforme l'attribut « serviceCategory ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapServiceCategory(): array;

    /**
     * Transforme l'attribut « serviceType ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapServiceType(): array;

    /**
     * Transforme l'attribut « specialty ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapSpecialty(): array;

    /**
     * Transforme l'attribut « actor ».
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapActor(): array;

    /**
     * Transforme l'attribut « planningHorizon ».
     *
     * @return CFHIRDataTypePeriod|null
     */
    public function mapPlanningHorizon(): ?CFHIRDataTypePeriod;

    /**
     * Transforme l'attribut « comment ».
     *
     * @return CFHIRDataTypeString|null
     */
    public function mapComment(): ?CFHIRDataTypeString;
}
