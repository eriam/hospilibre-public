<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Contracts\Mapping\R4;

use Ox\Interop\Fhir\Contracts\Mapping\ResourceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAnnotation;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;

/**
 * Contrat de correspondance FHIR R4 pour la ressource Device.
 *
 * Chaque methode expose la valeur d'une propriete FHIR de Device,
 * ou null lorsque la propriete n'est pas renseignee.
 */
interface DeviceMappingInterface extends ResourceMappingInterface
{
    /** @var string Type de ressource FHIR gere par ce mapping */
    public const RESOURCE_TYPE = "Device";

    /**
     * Propriete FHIR "definition".
     */
    public function mapDefinition(): ?CFHIRDataTypeReference;

    /**
     * Propriete FHIR "udiCarrier".
     *
     * @return CFHIRDataTypeBackboneElement[]|null
     */
    public function mapUdiCarrier(): ?array;

    /**
     * Propriete FHIR "status".
     */
    public function mapStatus(): ?CFHIRDataTypeCode;

    /**
     * Propriete FHIR "statusReason".
     */
    public function mapStatusReason(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Propriete FHIR "distinctIdentifier".
     */
    public function mapDistinctIdentifier(): ?CFHIRDataTypeString;

    /**
     * Propriete FHIR "manufacturer".
     */
    public function mapManufacturer(): ?CFHIRDataTypeString;

    /**
     * Propriete FHIR "manufactureDate".
     */
    public function mapManufactureDate(): ?CFHIRDataTypeDateTime;

    /**
     * Propriete FHIR "expirationDate".
     */
    public function mapExpirationDate(): ?CFHIRDataTypeDateTime;

    /**
     * Propriete FHIR "lotNumber".
     */
    public function mapLotNumber(): ?CFHIRDataTypeString;

    /**
     * Propriete FHIR "serialNumber".
     */
    public function mapSerialNumber(): ?CFHIRDataTypeString;

    /**
     * Propriete FHIR "deviceName".
     *
     * @return CFHIRDataTypeBackboneElement[]|null
     */
    public function mapDeviceName(): ?array;

    /**
     * Propriete FHIR "modelNumber".
     */
    public function mapModelNumber(): ?CFHIRDataTypeString;

    /**
     * Propriete FHIR "partNumber".
     */
    public function mapPartNumber(): ?CFHIRDataTypeString;

    /**
     * Propriete FHIR "type".
     */
    public function mapType(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Propriete FHIR "specialization".
     *
     * @return CFHIRDataTypeBackboneElement[]|null
     */
    public function mapSpecialization(): ?array;

    /**
     * Propriete FHIR "version".
     *
     * @return CFHIRDataTypeBackboneElement[]|null
     */
    public function mapVersion(): ?array;

    /**
     * Propriete FHIR "property".
     *
     * @return CFHIRDataTypeBackboneElement[]|null
     */
    public function mapProperty(): ?array;

    /**
     * Propriete FHIR "patient".
     */
    public function mapPatient(): ?CFHIRDataTypeReference;

    /**
     * Propriete FHIR "owner".
     */
    public function mapOwner(): ?CFHIRDataTypeReference;

    /**
     * Propriete FHIR "contact".
     *
     * @return CFHIRDataTypeContactPoint[]|null
     */
    public function mapContact(): ?array;

    /**
     * Propriete FHIR "location".
     */
    public function mapLocation(): ?CFHIRDataTypeReference;

    /**
     * Propriete FHIR "url".
     */
    public function mapUrl(): ?CFHIRDataTypeUri;

    /**
     * Propriete FHIR "note".
     *
     * @return CFHIRDataTypeAnnotation[]|null
     */
    public function mapNote(): ?array;

    /**
     * Propriete FHIR "safety".
     *
     * @return CFHIRDataTypeCodeableConcept[]|null
     */
    public function mapSafety(): ?array;

    /**
     * Propriete FHIR "parent".
     */
    public function mapParent(): ?CFHIRDataTypeReference;
}
