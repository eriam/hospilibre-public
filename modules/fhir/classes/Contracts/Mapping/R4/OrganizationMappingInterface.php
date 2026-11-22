<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Contracts\Mapping\R4;

use Ox\Interop\Fhir\Contracts\Mapping\ResourceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Organization\CFHIRDataTypeOrganizationContact;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;

/**
 * Contrat de correspondance pour la ressource FHIR R4 Organization.
 *
 * Chaque methode traduit un attribut de l'organisation vers le type FHIR adequat.
 */
interface OrganizationMappingInterface extends ResourceMappingInterface
{
    /** @var string Nom de la ressource FHIR concernee. */
    public const RESOURCE_TYPE = "Organization";

    /**
     * Correspondance de l'attribut « active ».
     */
    public function mapActive(): ?CFHIRDataTypeBoolean;

    /**
     * Correspondance de l'attribut « type ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapType(): array;

    /**
     * Correspondance de l'attribut « name ».
     */
    public function mapName(): ?CFHIRDataTypeString;

    /**
     * Correspondance de l'attribut « alias ».
     *
     * @return CFHIRDataTypeString[]
     */
    public function mapAlias(): array;

    /**
     * Correspondance de l'attribut « telecom ».
     *
     * @return CFHIRDataTypeContactPoint[]
     */
    public function mapTelecom(): array;

    /**
     * Correspondance de l'attribut « address ».
     *
     * @return CFHIRDataTypeAddress[]
     */
    public function mapAddress(): array;

    /**
     * Correspondance de l'attribut « partOf ».
     */
    public function mapPartOf(): ?CFHIRDataTypeReference;

    /**
     * Correspondance de l'attribut « contact ».
     *
     * @return CFHIRDataTypeOrganizationContact[]
     */
    public function mapContact(): array;

    /**
     * Correspondance de l'attribut « endpoint ».
     */
    public function mapEndpoint(): ?CFHIRDataTypeReference;
}
