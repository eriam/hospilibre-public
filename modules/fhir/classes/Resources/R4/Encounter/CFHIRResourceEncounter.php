<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Encounter;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\EncounterMappingInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourceEncounterInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Encounter\CFHIRDataTypeEncounterParticipant;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Encounter\Mapper\Encounter;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\PlanningOp\CSejour;

/**
 * FHIR encounter resource
 */
class CFHIRResourceEncounter extends CFHIRDomainResource implements ResourceEncounterInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'Encounter';

    /** @var CFHIRDataTypeIdentifier[] */
    public $identifier;

    /** @var CFHIRDataTypeCode */
    public $status;

    /** @var CFHIRDataTypeCoding */
    public $class;

    /** @var CFHIRDataTypeCodeableConcept */
    public $type;

    /** @var CFHIRDataTypeReference */
    public $subject;

    /** @var CFHIRDataTypeEncounterParticipant[] */
    public $participant;

    /** @var CFHIRDataTypePeriod */
    public $period;

    /** @var CSejour */
    protected $object;

    /** @var EncounterMappingInterface */
    protected $object_mapping;

    /**
     * @inheritdoc
     */
    public function getClass(): ?string
    {
        return CSejour::class;
    }

    /**
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return (parent::generateCapabilities())
            ->addInteractions(
                [
                    CFHIRInteractionRead::NAME,
                    CFHIRInteractionSearch::NAME,
                ]
            );
    }

    /**
     * @inheritdoc
     */
    final protected function map(CStoredObject $object): void
    {
        if ($this->object_mapping) {
            if (!$object->_id) {
                return;
            }

            parent::map($object);
        }
    }

    /**
     * @inheritdoc
     * @throws CFHIRException
     * @throws Exception
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CSejour) {
            throw new CFHIRException("Object is not a patient");
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
        $mapping_object = Encounter::class;

        return new $mapping_object();
    }

    /**
     * Map property identifier
     */
    protected function mapIdentifier(): void
    {
        $this->identifier = $this->object_mapping->mapIdentifier();
    }

    /**
     * Map property status
     */
    protected function mapStatus(): void
    {
        $this->status = $this->object_mapping->mapStatus();
    }

    /**
     * Map property type
     */
    protected function mapType(): void
    {
        $this->type = $this->object_mapping->mapType();
    }

    /**
     * Map property subject
     */
    protected function mapSubject(): void
    {
        $this->subject = $this->object_mapping->mapSubject();
    }

    /**
     * Map property participant
     */
    protected function mapParticipant(): void
    {
        $this->participant = $this->object_mapping->mapParticipant();
    }

    /**
     * Map property period
     */
    protected function mapPeriod(): void
    {
        $this->period = $this->object_mapping->mapPeriod();
    }
}
