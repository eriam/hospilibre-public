<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Schedule\Mapper;

use Exception;
use Hospilibre\Cabinet\CPlageconsult;
use Hospilibre\Patients\CMedecin;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\ScheduleMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Profiles\CFHIRAnnuaireSante;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Resources\R4\Schedule\CFHIRResourceSchedule;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Mapper FHIR R4 pour la ressource Schedule (plage de consultation).
 */
class Schedule implements DelegatedObjectMapperInterface, ScheduleMappingInterface
{
    use ResourceTrait;

    /** @var CPlageconsult */
    protected $object;

    /** @var CFHIRResourceSchedule */
    protected $resource;

    public function setResource(CFHIRResource $resource, CStoredObject $object): void
    {
        $this->resource = $resource;
        $this->object   = $object;
    }

    public function getProfiles(): array
    {
        return [CFHIR::class];
    }

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
    }

    public function mapExtension(): array
    {
        return [];
    }

    public function mapIdentifier(): array
    {
        return [];
    }

    public function mapActive(): ?CFHIRDataTypeBoolean
    {
        // Une plage verrouillée est considérée comme inactive.
        return new CFHIRDataTypeBoolean(!$this->object->locked);
    }

    public function mapServiceCategory(): array
    {
        $coding = CFHIRDataTypeCoding::addCoding(
            'http://terminology.hl7.org/CodeSystem/service-category',
            '35',
            'Hospital'
        );

        return [CFHIRDataTypeCodeableConcept::addCodeable($coding, 'Hospital')];
    }

    public function mapServiceType(): array
    {
        // non implémenté
        return [];
    }

    public function mapSpecialty(): array
    {
        return [];
    }

    /**
     * @throws Exception
     */
    public function mapActor(): array
    {
        // Les acteurs restent à dynamiser selon le besoin ; pour le projectathon un seul practitioner est échangé.
        $chir = $this->object->loadRefChir();

        $medecin       = new CMedecin();
        $medecin->rpps = $chir->rpps;
        $medecin->loadMatchingObject();

        return [$this->resource->addReference(CFHIRResourcePractitioner::class, $medecin)];
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function mapPlanningHorizon(): ?CFHIRDataTypePeriod
    {
        $formattedPeriod = CFHIRDataTypeInstant::formatPeriod(
            CFHIR::getTimeUtc($this->object->debut, false),
            CFHIR::getTimeUtc($this->object->fin, false)
        );

        return CFHIRDataTypePeriod::build($formattedPeriod);
    }

    public function mapComment(): ?CFHIRDataTypeString
    {
        // non implémenté
        return null;
    }
}
