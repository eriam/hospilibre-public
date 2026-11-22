<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Contracts\Mapping\R4;

use Ox\Interop\Fhir\Contracts\Mapping\ResourceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientCommunication;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientContact;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientLink;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAttachment;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeChoice;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;

/**
 * Contrat de projection d'une ressource FHIR R4 « Patient ».
 *
 * Chaque methode « map* » construit la valeur FHIR correspondant a un
 * element de la ressource Patient a partir de l'objet metier delegue.
 */
interface PatientMappingInterface extends ResourceMappingInterface
{
    /** @var string Type de ressource FHIR pris en charge par ce mapping. */
    public const RESOURCE_TYPE = "Patient";

    /**
     * Projette l'element « active ».
     *
     * @return CFHIRDataTypeBoolean
     */
    public function mapActive(): ?CFHIRDataTypeBoolean;

    /**
     * Projette l'element « name ».
     *
     * @return CFHIRDataTypeHumanName[]
     */
    public function mapName(): array;

    /**
     * Projette l'element « telecom ».
     *
     * @return CFHIRDataTypeContactPoint[]
     */
    public function mapTelecom(): array;

    /**
     * Projette l'element « gender ».
     *
     * @return CFHIRDataTypeCode
     */
    public function mapGender(): ?CFHIRDataTypeCode;

    /**
     * Projette l'element « birthDate ».
     *
     * @return CFHIRDataTypeDate
     */
    public function mapBirthDate(): ?CFHIRDataTypeDate;

    /**
     * Projette l'element « deceased ».
     *
     * @return CFHIRDataTypeChoice
     */
    public function mapDeceased(): ?CFHIRDataTypeChoice;

    /**
     * Projette l'element « address ».
     *
     * @return CFHIRDataTypeAddress[]
     */
    public function mapAddress(): array;

    /**
     * Projette l'element « maritalStatus ».
     *
     * @return CFHIRDataTypeCodeableConcept
     */
    public function mapMaritalStatus(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Projette l'element « multipleBirth ».
     *
     * @return CFHIRDataTypeChoice
     */
    public function mapMultipleBirth(): ?CFHIRDataTypeChoice;

    /**
     * Projette l'element « photo ».
     *
     * @return CFHIRDataTypeAttachment[]
     */
    public function mapPhoto(): array;

    /**
     * Projette l'element « contact ».
     *
     * @return CFHIRDataTypePatientContact[]
     */
    public function mapContact(): array;

    /**
     * Projette l'element « communication ».
     *
     * @return CFHIRDataTypePatientCommunication[]
     */
    public function mapCommunication(): array;

    /**
     * Projette l'element « generalPractitioner ».
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapGeneralPractitioner(string $resource_class): array;

    /**
     * Projette l'element « managingOrganization ».
     *
     * @return CFHIRDataTypeReference
     */
    public function mapManagingOrganization(): ?CFHIRDataTypeReference;

    /**
     * Projette l'element « link ».
     *
     * @return CFHIRDataTypePatientLink[]
     */
    public function mapLink(): array;
}
