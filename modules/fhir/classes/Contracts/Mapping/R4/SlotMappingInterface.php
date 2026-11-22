<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Contracts\Mapping\R4;

use Ox\Interop\Fhir\Contracts\Mapping\ResourceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;

/**
 * Contrat de mapping d'une ressource FHIR Slot (R4).
 *
 * Chaque methode ci-dessous projette une propriete metier vers son
 * equivalent dans la ressource FHIR Slot.
 */
interface SlotMappingInterface extends ResourceMappingInterface
{
    /** @var string */
    public const RESOURCE_TYPE = "Slot";

    /**
     * Projette la propriete « identifier ».
     *
     * @return CFHIRDataTypeIdentifier[]
     */
    public function mapIdentifier(): array;

    /**
     * Projette la propriete « serviceCategory ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapServiceCategory(): array;

    /**
     * Projette la propriete « serviceType ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapServiceType(): array;

    /**
     * Projette la propriete « specialty ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapSpecialty(): array;

    /**
     * Projette la propriete « appointmentType ».
     *
     * @return CFHIRDataTypeCodeableConcept|null
     */
    public function mapAppointmentType(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Projette la propriete « schedule ».
     *
     * @return CFHIRDataTypeReference|null
     */
    public function mapSchedule(): ?CFHIRDataTypeReference;

    /**
     * Projette la propriete « status ».
     *
     * @return CFHIRDataTypeCode|null
     */
    public function mapStatus(): ?CFHIRDataTypeCode;

    /**
     * Projette la propriete « start ».
     *
     * @return CFHIRDataTypeInstant|null
     */
    public function mapStart(): ?CFHIRDataTypeInstant;

    /**
     * Projette la propriete « end ».
     *
     * @return CFHIRDataTypeInstant|null
     */
    public function mapEnd(): ?CFHIRDataTypeInstant;

    /**
     * Projette la propriete « overbooked ».
     *
     * @return CFHIRDataTypeBoolean|null
     */
    public function mapOverbooked(): ?CFHIRDataTypeBoolean;

    /**
     * Projette la propriete « comment ».
     *
     * @return CFHIRDataTypeString|null
     */
    public function mapComment(): ?CFHIRDataTypeString;
}
