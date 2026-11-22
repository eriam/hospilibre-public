<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Contracts\Mapping\R4;

use Ox\Interop\Fhir\Contracts\Mapping\ResourceMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Observation\CFHIRDataTypeObservationComponent;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAnnotation;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeChoice;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;

/**
 * Contrat de mapping pour la ressource FHIR R4 « Observation ».
 *
 * Chaque methode se charge de projeter une propriete de la ressource
 * vers le(s) datatype(s) FHIR correspondant(s).
 */
interface ObservationMappingInterface extends ResourceMappingInterface
{
    /** @var string Type de ressource FHIR gere par ce contrat. */
    public const RESOURCE_TYPE = "Observation";

    /**
     * Projette la propriete « basedOn ».
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapBasedOn(): array;

    /**
     * Projette la propriete « partOf ».
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapPartOf(): array;

    /**
     * Projette la propriete « status ».
     */
    public function mapStatus(): ?CFHIRDataTypeCode;

    /**
     * Projette la propriete « category ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapCategory(): array;

    /**
     * Projette la propriete « code ».
     */
    public function mapCode(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Projette la propriete « subject ».
     */
    public function mapSubject(): ?CFHIRDataTypeReference;

    /**
     * Projette la propriete « focus ».
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapFocus(): array;

    /**
     * Projette la propriete « encounter ».
     */
    public function mapEncounter(): ?CFHIRDataTypeReference;

    /**
     * Projette la propriete « effective ».
     */
    public function mapEffective(): ?CFHIRDataTypeChoice;

    /**
     * Projette la propriete « issued ».
     */
    public function mapIssued(): ?CFHIRDataTypeInstant;

    /**
     * Projette la propriete « performer ».
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapPerformer(): array;

    /**
     * Projette la propriete « value ».
     */
    public function mapValue(): ?CFHIRDataTypeChoice;

    /**
     * Projette la propriete « dataAbsentReason ».
     */
    public function mapDataAbsentReason(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Projette la propriete « interpretation ».
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    public function mapInterpretation(): array;

    /**
     * Projette la propriete « note ».
     *
     * @return CFHIRDataTypeAnnotation[]
     */
    public function mapNote(): array;

    /**
     * Projette la propriete « bodySite ».
     */
    public function mapBodySite(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Projette la propriete « method ».
     */
    public function mapMethod(): ?CFHIRDataTypeCodeableConcept;

    /**
     * Projette la propriete « specimen ».
     */
    public function mapSpecimen(): ?CFHIRDataTypeReference;

    /**
     * Projette la propriete « device ».
     */
    public function mapDevice(): ?CFHIRDataTypeReference;

    /**
     * Projette la propriete « referenceRange ».
     *
     * @return CFHIRDataTypeBackboneElement[]
     */
    public function mapReferenceRange(): array;

    /**
     * Projette la propriete « hasMember ».
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapHasMember(): array;

    /**
     * Projette la propriete « derivedFrom ».
     *
     * @return CFHIRDataTypeReference[]
     */
    public function mapDerivedFrom(): array;

    /**
     * Projette la propriete « component ».
     *
     * @return CFHIRDataTypeObservationComponent[]
     */
    public function mapComponent(): array;
}
