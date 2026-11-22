<?php

/**
 * @package Mediboard\fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Schedule;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\ScheduleMappingInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourceScheduleInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionHistory;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Resources\R4\Schedule\Mapper\Schedule;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterDate;
use Hospilibre\Cabinet\CPlageconsult;
use Hospilibre\Patients\CMedecin;

/**
 * Ressource FHIR Schedule adossée à une plage de consultation Hospilibre.
 *
 * @package Mediboard\fhir
 */
class CFHIRResourceSchedule extends CFHIRDomainResource implements ResourceScheduleInterface
{
    /** @var string Type de ressource FHIR exposé. */
    public const RESOURCE_TYPE = 'Schedule';

    /** @var CFHIRDataTypeIdentifier[] */
    public $identifier;

    /** @var CFHIRDataTypeBoolean */
    public $active;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $serviceCategory;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $serviceType;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $specialty;

    /** @var CFHIRDataTypeReference[] */
    public $actor;

    /** @var CFHIRDataTypePeriod */
    public $planningHorizon;

    /** @var CFHIRDataTypeString */
    public $comment;

    /** @var CPlageconsult */
    public $object;

    /** @var ScheduleMappingInterface */
    protected $object_mapping;

    /**
     * Classe métier Hospilibre correspondant à cette ressource.
     */
    public function getClass(): ?string
    {
        return CPlageconsult::class;
    }

    /**
     * Décrit les interactions et paramètres de recherche supportés.
     *
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        $capabilities = parent::generateCapabilities();

        $capabilities->setInteractions([
            CFHIRInteractionRead::NAME,
            CFHIRInteractionCreate::NAME,
            CFHIRInteractionSearch::NAME,
            CFHIRInteractionHistory::NAME,
        ]);

        $capabilities->addSearchAttributes([
            new SearchParameterDate('start'),
        ]);

        return $capabilities;
    }

    /**
     * Alimente la ressource à partir de la plage de consultation.
     *
     * @param CStoredObject $object
     *
     * @throws Exception
     */
    final protected function map(CStoredObject $object): void
    {
        if (!$object->_id) {
            return;
        }

        // L'identifiant doit être mappé en amont pour être transmis à l'extension.
        $this->object = $object;

        parent::map($object);

        $this->mapActive();
        $this->mapServiceCategory();
        $this->mapServiceType();
        $this->mapSpecialty();
        $this->mapActor();
        $this->mapPlanningHorizon();
        $this->mapComment();
    }

    /**
     * @inheritdoc
     * @throws Exception
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CPlageconsult) {
            throw new CFHIRException("Object is not a schedule");
        }

        $this->map($object);
    }

    /**
     * Instancie l'ancien mapper délégué pour cette ressource.
     *
     * @param CStoredObject $object
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        $mapper_class = Schedule::class;

        return new $mapper_class();
    }

    /**
     * Renseigne la propriété active.
     */
    protected function mapActive(): void
    {
        // non implémenté
        $this->active = $this->object_mapping->mapActive();
    }

    /**
     * Renseigne la propriété serviceCategory.
     */
    protected function mapServiceCategory(): void
    {
        $this->serviceCategory = $this->object_mapping->mapServiceCategory();
    }

    /**
     * Renseigne la propriété serviceType.
     * @throws Exception
     */
    protected function mapServiceType(): void
    {
        $this->serviceType = $this->object_mapping->mapServiceType();
    }

    /**
     * Renseigne la propriété specialty.
     */
    protected function mapSpecialty(): void
    {
        $this->specialty = $this->object_mapping->mapSpecialty();
    }

    /**
     * Renseigne la propriété actor.
     * @throws Exception
     */
    protected function mapActor(): void
    {
        $this->actor = $this->object_mapping->mapActor();
    }

    /**
     * Renseigne la propriété planningHorizon.
     * @throws Exception
     */
    protected function mapPlanningHorizon(): void
    {
        $this->planningHorizon = $this->object_mapping->mapPlanningHorizon();
    }

    /**
     * Renseigne la propriété comment.
     */
    protected function mapComment(): void
    {
        $this->comment = $this->object_mapping->mapComment();
    }
}
