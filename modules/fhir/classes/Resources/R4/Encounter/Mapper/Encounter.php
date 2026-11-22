<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Encounter\Mapper;

use Ox\Core\CStoredObject;
use Ox\Interop\Eai\CDomain;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\EncounterMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Encounter\CFHIRDataTypeEncounterParticipant;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\R4\Encounter\CFHIRResourceEncounter;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Hospilibre\Patients\CMedecin;
use Hospilibre\Patients\CPatient;
use Hospilibre\PlanningOp\CSejour;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Convertit un CSejour vers la ressource FHIR Encounter (R4).
 */
class Encounter implements DelegatedObjectMapperInterface, EncounterMappingInterface
{
    use ResourceTrait;

    /** @var CSejour */
    protected $object;

    /** @var CFHIRResourceEncounter */
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
        // Aucune initialisation nécessaire.
    }

    public function mapExtension(): array
    {
        return [];
    }

    /**
     * Construit la liste des identifiants métier du séjour.
     *
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapIdentifier(): array
    {
        $result = [];

        foreach (CDomain::loadDomainIdentifiers($this->object) as $_domain) {
            $filtered = empty($sejour->_returned_oids)
                || in_array($_domain->OID, $sejour->_returned_oids);

            if (!$filtered) {
                continue;
            }

            $result[] = CFHIRDataTypeIdentifier::build(
                [
                    "system" => "urn:oid:$_domain->OID",
                    "value"  => $_domain->_identifier->id400,
                ]
            );
        }

        return $result;
    }

    public function mapStatus(): ?CFHIRDataTypeCode
    {
        if ($this->object->annule) {
            return new CFHIRDataTypeCode('cancelled');
        }

        $correspondance = [
            "preadmission" => 'planned',
            "encours"      => 'in-progress',
            "cloture"      => 'finished',
        ];

        $code = $correspondance[$this->object->_etat] ?? null;

        return $code === null ? null : new CFHIRDataTypeCode($code);
    }

    public function mapType(): ?CFHIRDataTypeCodeableConcept
    {
        return null;
    }

    public function mapSubject(): ?CFHIRDataTypeReference
    {
        $patient = $this->object->loadRefPatient();

        return $this->resource->addReference(get_class(new CPatient()), $patient);
    }

    /**
     * Le médecin traitant est exposé comme participant de type ADM.
     *
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapParticipant(): array
    {
        $medecin   = $this->object->loadRefMedecinTraitant();
        $reference = $this->resource->addReference(get_class(new CMedecin()), $medecin);

        return [
            CFHIRDataTypeEncounterParticipant::build(
                [
                    "type"       => "ADM",
                    "individual" => $reference,
                ]
            ),
        ];
    }

    /**
     * Période couvrant l'entrée et la sortie du séjour.
     *
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapPeriod(): ?CFHIRDataTypePeriod
    {
        $intervalle = CFHIRDataTypeInstant::formatPeriod(
            $this->object->entree,
            $this->object->sortie
        );

        return CFHIRDataTypePeriod::build($intervalle);
    }
}
