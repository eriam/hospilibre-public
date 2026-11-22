<?php

/**
 * @package Mediboard\fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Slot;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\SlotMappingInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourceSlotInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotFound;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionHistory;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Slot\Mapper\Slot;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\Cabinet\CPlageconsult;
use Hospilibre\Cabinet\CSlot;
use Hospilibre\Sante400\CIdSante400;

/**
 * Ressource FHIR R4 « Slot » : créneau de disponibilité d'un planning.
 *
 * @package Ox\Interop\Fhir\Resources
 */
class CFHIRResourceSlot extends CFHIRDomainResource implements ResourceSlotInterface
{
    /** @var string Type de ressource FHIR exposé */
    public const RESOURCE_TYPE = "Slot";

    /** @var CFHIRDataTypeIdentifier[] */
    public $identifier;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public array $serviceCategory;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public array $serviceType;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public array $specialty;

    /** @var CFHIRDataTypeCodeableConcept|null */
    public ?CFHIRDataTypeCodeableConcept $appointmentType;

    /** @var CFHIRDataTypeReference|null */
    public ?CFHIRDataTypeReference $schedule;

    /** @var CFHIRDataTypeCode|null */
    public ?CFHIRDataTypeCode $status;

    /** @var CFHIRDataTypeInstant|null */
    public ?CFHIRDataTypeInstant $start;

    /** @var CFHIRDataTypeInstant|null */
    public ?CFHIRDataTypeInstant $end;

    /** @var CFHIRDataTypeBoolean|null */
    public ?CFHIRDataTypeBoolean $overbooked;

    /** @var CFHIRDataTypeString|null */
    public ?CFHIRDataTypeString $comment;

    /** @var CSlot */
    public $object;

    /** @var SlotMappingInterface */
    public $object_mapping;

    /**
     * Classe métier associée à cette ressource.
     *
     * @return string
     */
    public function getClass(): ?string
    {
        return CSlot::class;
    }

    /**
     * Déclare les interactions FHIR supportées par la ressource.
     *
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return parent::generateCapabilities()->setInteractions([
            CFHIRInteractionRead::NAME,
            CFHIRInteractionCreate::NAME,
            CFHIRInteractionSearch::NAME,
            CFHIRInteractionHistory::NAME,
        ]);
    }

    /**
     * Alimente l'ensemble des propriétés FHIR à partir de l'objet métier.
     *
     * @param CStoredObject $object
     *
     * @throws Exception
     */
    final protected function map(CStoredObject $object): void
    {
        // Rien à mapper tant que l'objet n'est pas persisté.
        if (!$object->_id) {
            return;
        }

        parent::map($object);

        $this->mapServiceCategory();
        $this->mapServiceType();
        $this->mapSpecialty();
        $this->mapAppointmentType();
        $this->mapSchedule();
        $this->mapStatus();
        $this->mapStart();
        $this->mapEnd();
        $this->mapOverbooked();
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

        if (!$object instanceof CSlot) {
            throw new CFHIRException("Object is not a slot");
        }

        $this->map($object);
    }

    /**
     * @param CStoredObject $object
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        return new Slot();
    }

    /**
     * Charge l'objet métier de contexte à partir d'un identifiant composite.
     *
     * @param string      $resource_id
     * @param string|null $version_id
     *
     * @return CStoredObject
     * @throws Exception
     */
    public function loadObjectFromContext(string $resource_id, ?string $version_id = null): CStoredObject
    {
        // L'identifiant de créneau embarque un suffixe « -<id> » qu'on retire ici.
        $separator_pos = strrpos($resource_id, '-');
        $plage_consult_id = substr($resource_id, 0, $separator_pos);

        return parent::loadObjectFromContext($plage_consult_id, $version_id);
    }

    /**
     * @param CStoredObject $object
     *
     * @return string
     * @throws CFHIRExceptionNotFound
     */
    protected function getInternalId(CStoredObject $object): string
    {
        /** @var CSlot $object */
        $internal_id = parent::getInternalId($object);

        // Si un identifiant externe existe déjà pour ce destinataire, on le renvoie tel quel.
        if ($this->_receiver) {
            $idex = CIdSante400::getMatch($object->_class, $this->_receiver->_tag_fhir, null, $object->_id);

            if ($idex && $idex->_id) {
                return $internal_id;
            }
        }

        if ($object->_id === null) {
            throw new CFHIRExceptionNotFound();
        }

        return $internal_id . '-' . $object->_id;
    }

    /**
     * Mappe la propriété serviceCategory.
     */
    protected function mapServiceCategory(): void
    {
        $this->serviceCategory = $this->object_mapping->mapServiceCategory();
    }

    /**
     * Mappe la propriété serviceType.
     * @throws Exception
     */
    protected function mapServiceType(): void
    {
        $this->serviceType = $this->object_mapping->mapServiceType();
    }

    /**
     * Mappe la propriété specialty.
     */
    protected function mapSpecialty(): void
    {
        $this->specialty = $this->object_mapping->mapSpecialty();
    }

    /**
     * Mappe la propriété appointmentType.
     */
    protected function mapAppointmentType(): void
    {
        $this->appointmentType = $this->object_mapping->mapAppointmentType();
    }

    /**
     * Mappe la propriété schedule.
     */
    protected function mapSchedule(): void
    {
        $this->schedule = $this->object_mapping->mapSchedule();
    }

    /**
     * Mappe la propriété status.
     */
    protected function mapStatus(): void
    {
        $this->status = $this->object_mapping->mapStatus();
    }

    /**
     * Mappe la propriété start.
     */
    protected function mapStart(): void
    {
        $this->start = $this->object_mapping->mapStart();
    }

    /**
     * Mappe la propriété end.
     */
    protected function mapEnd(): void
    {
        $this->end = $this->object_mapping->mapEnd();
    }

    /**
     * Mappe la propriété overbooked.
     */
    protected function mapOverbooked(): void
    {
        $this->overbooked = $this->object_mapping->mapOverbooked();
    }

    /**
     * Mappe la propriété comment.
     */
    protected function mapComment(): void
    {
        $this->comment = $this->object_mapping->mapComment();
    }
}
