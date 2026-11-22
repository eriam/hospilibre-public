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
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Practitioner\CFHIRDataTypePractitionerQualification;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAttachment;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;

/**
 * Description
 */
interface PractitionerMappingInterface extends ResourceMappingInterface
{
    /** @var string */
    public const RESOURCE_TYPE = "Practitioner";

    /**
     * Map property active
     *
     * @return CFHIRDataTypeBoolean
     */
    public function mapActive(): ?CFHIRDataTypeBoolean;

    /**
     * Map property name
     *
     * @return CFHIRDataTypeHumanName[]
     */
    public function mapName(): array;

    /**
     * Map property telecom
     *
     * @return CFHIRDataTypeContactPoint[]
     */
    public function mapTelecom(): array;

    /**
     * Map property address
     *
     * @return CFHIRDataTypeAddress[]
     */
    public function mapAddress(): array;

    /**
     * Map property gender
     *
     * @return CFHIRDataTypeCode
     */
    public function mapGender(): ?CFHIRDataTypeCode;

    /**
     * Map property birthDate
     *
     * @return CFHIRDataTypeDate
     */
    public function mapBirthDate(): ?CFHIRDataTypeDate;

    /**
     * Map property photo
     *
     * @return CFHIRDataTypeAttachment[]
     */
    public function mapPhoto(): array;

    /**
     * Map property qualification
     *
     * @return CFHIRDataTypePractitionerQualification[]
     */
    public function mapQualification(): array;

    /**
     * Map property communication
     *
     * @return CFHIRDataTypePatientCommunication[]
     */
    public function mapCommunication(): array;
}
