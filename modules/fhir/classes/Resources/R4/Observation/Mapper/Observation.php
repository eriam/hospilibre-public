<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Observation\Mapper;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\ObservationMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Observation\CFHIRDataTypeObservationComponent;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeChoice;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Device\CFHIRResourceDevice;
use Ox\Interop\Fhir\Resources\R4\Observation\CFHIRResourceObservation;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Patients\Constants\CAbstractConstant;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Mapper FHIR R4 pour une ressource Observation adossee a une constante medicale.
 */
class Observation implements DelegatedObjectMapperInterface, ObservationMappingInterface
{
    use ResourceTrait;

    /** @var CAbstractConstant */
    protected $object;

    /** @var CFHIRResourceObservation */
    protected $resource;

    public function setResource(CFHIRResource $resource, CStoredObject $object): void
    {
        // On memorise l'objet source (la constante) et la ressource FHIR cible.
        $this->resource = $resource;
        $this->object   = $object;
    }

    /**
     * @inheritDoc
     */
    public function getProfiles(): array
    {
        return [CFHIR::class];
    }

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        // Rien a preparer pour ce mapper.
    }

    /**
     * @inheritDoc
     */
    public function mapExtension(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapIdentifier(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapBasedOn(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapPartOf(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapStatus(): ?CFHIRDataTypeCode
    {
        return new CFHIRDataTypeCode('final');
    }

    /**
     * @inheritDoc
     */
    public function mapCategory(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapCode(): ?CFHIRDataTypeCodeableConcept
    {
        // Panel LOINC des signes vitaux avec details de methode.
        $libelle = "Vital signs with method details panel";

        $coding = CFHIRDataTypeCoding::addCoding("http://loinc.org", "34566-0", $libelle);

        return CFHIRDataTypeCodeableConcept::addCodeable($coding, $libelle);
    }

    /**
     * Construit une reference vers le patient a partir de son IPP et de l'OID d'etablissement.
     */
    private function buildPatientReference(): CFHIRDataTypeReference
    {
        $patient = $this->object->loadRefPatient();

        $valeurIdentifiant = CAppUI::conf('mb_oid') . '|' . $patient->loadIPP();

        $identifiants = CFHIRDataTypeIdentifier::addIdentifier(null, $valeurIdentifiant);

        return CFHIRDataTypeReference::build(["identifier" => $identifiants[0]]);
    }

    /**
     * @inheritDoc
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function mapSubject(): ?CFHIRDataTypeReference
    {
        return $this->buildPatientReference();
    }

    /**
     * @inheritDoc
     */
    public function mapFocus(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapEncounter(): ?CFHIRDataTypeReference
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapEffective(): ?CFHIRDataTypeChoice
    {
        return new CFHIRDataTypeChoice(CFHIRDataTypeDateTime::class, $this->object->datetime);
    }

    /**
     * @inheritDoc
     */
    public function mapIssued(): ?CFHIRDataTypeInstant
    {
        return null;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function mapPerformer(): array
    {
        return [$this->buildPatientReference()];
    }

    /**
     * @inheritDoc
     */
    public function mapValue(): ?CFHIRDataTypeChoice
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapDataAbsentReason(): ?CFHIRDataTypeCodeableConcept
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapInterpretation(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapNote(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapBodySite(): ?CFHIRDataTypeCodeableConcept
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapMethod(): ?CFHIRDataTypeCodeableConcept
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapSpecimen(): ?CFHIRDataTypeReference
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function mapDevice(): ?CFHIRDataTypeReference
    {
        return $this->resource->addReference(CFHIRResourceDevice::class, $this->object);
    }

    /**
     * @inheritDoc
     */
    public function mapReferenceRange(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapHasMember(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapDerivedFrom(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapComponent(): array
    {
        return [];
    }
}
