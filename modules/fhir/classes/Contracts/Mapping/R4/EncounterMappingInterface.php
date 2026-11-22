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
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Encounter\CFHIRDataTypeEncounterParticipant;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;

/**
 * Contrat de mapping pour la ressource FHIR R4 « Encounter ».
 *
 * Chaque methode traduit un attribut de la ressource vers le type de donnee
 * FHIR correspondant.
 */
interface EncounterMappingInterface extends ResourceMappingInterface
{
    /** @var string */
    public const RESOURCE_TYPE = "Encounter";

    /**
     * Traduit l'attribut « identifier ».
     *
     * @return CFHIRDataTypeIdentifier[]
     */
    public function mapIdentifier(): array;

    /**
     * Traduit l'attribut « status ».
     *
     * @return CFHIRDataTypeCode|null
     */
    public function mapStatus(): ?CFHIRDataTypeCode;

    /**
     * Traduit l'attribut « type ».
     *
     * @return CFHIRDataTypeCodeableConcept|null
     */
    public function mapType(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Traduit l'attribut « subject ».
     *
     * @return CFHIRDataTypeReference|null
     */
    public function mapSubject(): ?CFHIRDataTypeReference;

    /**
     * Traduit l'attribut « participant ».
     *
     * @return CFHIRDataTypeEncounterParticipant[]
     */
    public function mapParticipant(): array;

    /**
     * Traduit l'attribut « period ».
     *
     * @return CFHIRDataTypePeriod|null
     */
    public function mapPeriod(): ?CFHIRDataTypePeriod;
}
