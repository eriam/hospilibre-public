<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Observation;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Observation\CFHIRDataTypeObservationComponent;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAnnotation;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeMeta;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Observation\Mapper\Observation;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\Patients\Constants\CAbstractConstant;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * FHIR observation resource
 */
class CFHIRResourceObservation extends CFHIRDomainResource
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'Observation';

    // attributes
    /** @var CFHIRDataTypeReference */
    public $basedOn;

    /** @var CFHIRDataTypeReference */
    public $partOf;

    /** @var CFHIRDataTypeCode */
    public $status;

    /** @var CFHIRDataTypeCodeableConcept */
    public $category;

    /** @var CFHIRDataTypeCodeableConcept */
    public $code;

    /** @var CFHIRDataTypeReference */
    public $subject;

    /** @var CFHIRDataTypeReference */
    public $focus;

    /** @var CFHIRDataTypeReference */
    public $encounter;

    /** @var mixed */
    public $effective;

    /** @var CFHIRDataTypeInstant */
    public $issued;

    /** @var CFHIRDataTypeReference */
    public $performer;

    /** @var mixed */
    public $value;

    /** @var CFHIRDataTypeCodeableConcept */
    public $dataAbsentReason;

    /** @var CFHIRDataTypeCodeableConcept */
    public $interpretation;

    /** @var CFHIRDataTypeAnnotation */
    public $note;

    /** @var CFHIRDataTypeCodeableConcept */
    public $bodySite;

    /** @var CFHIRDataTypeCodeableConcept */
    public $method;

    /** @var CFHIRDataTypeReference */
    public $specimen;

    /** @var CFHIRDataTypeReference */
    public $device;

    // TODO Î° changer
    /** @var CFHIRDataTypeBackboneElement */
    public $referenceRange;

    /** @var CFHIRDataTypeReference */
    public $hasMember;

    /** @var CFHIRDataTypeReference */
    public $derivedFrom;

    /** @var CFHIRDataTypeObservationComponent[] */
    public $component;

    /** @var CAbstractConstant */
    protected $object;

    /** @var Observation */
    protected $object_mapping;

    public function getMeta(): CFHIRDataTypeMeta
    {
        return $this->meta;
    }

    public function setMeta(CFHIRDataTypeMeta $_meta): void
    {
        $this->meta = $_meta;
    }

    /**
     * @return CFHIRDataTypeExtension[]
     */
    public function getExtension(): array
    {
        return $this->extension;
    }

    /**
     * @param CFHIRDataTypeExtension[] $extension
     */
    public function setExtension(array $extension): void
    {
        $this->extension = $extension;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getBasedOn(): CFHIRDataTypeReference
    {
        return $this->basedOn;
    }

    /**
     * @param CFHIRDataTypeReference $basedOn
     */
    public function setBasedOn(CFHIRDataTypeReference $basedOn): void
    {
        $this->basedOn = $basedOn;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getPartOf(): CFHIRDataTypeReference
    {
        return $this->partOf;
    }

    /**
     * @param CFHIRDataTypeReference $partOf
     */
    public function setPartOf(CFHIRDataTypeReference $partOf): void
    {
        $this->partOf = $partOf;
    }

    /**
     * @return CFHIRDataTypeCode
     */
    public function getStatus(): CFHIRDataTypeCode
    {
        return $this->status;
    }

    /**
     * @param CFHIRDataTypeCode $status
     */
    public function setStatus(CFHIRDataTypeCode $status): void
    {
        $this->status = $status;
    }

    /**
     * @return CFHIRDataTypeCodeableConcept
     */
    public function getCategory(): CFHIRDataTypeCodeableConcept
    {
        return $this->category;
    }

    /**
     * @param CFHIRDataTypeCodeableConcept $category
     */
    public function setCategory(CFHIRDataTypeCodeableConcept $category): void
    {
        $this->category = $category;
    }

    /**
     * @return CFHIRDataTypeCodeableConcept
     */
    public function getCode(): CFHIRDataTypeCodeableConcept
    {
        return $this->code;
    }

    /**
     * @param CFHIRDataTypeCodeableConcept $code
     */
    public function setCode(CFHIRDataTypeCodeableConcept $code): void
    {
        $this->code = $code;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getSubject(): CFHIRDataTypeReference
    {
        return $this->subject;
    }

    /**
     * @param CFHIRDataTypeReference $subject
     */
    public function setSubject(CFHIRDataTypeReference $subject): void
    {
        $this->subject = $subject;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getFocus(): CFHIRDataTypeReference
    {
        return $this->focus;
    }

    /**
     * @param CFHIRDataTypeReference $focus
     */
    public function setFocus(CFHIRDataTypeReference $focus): void
    {
        $this->focus = $focus;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getEncounter(): CFHIRDataTypeReference
    {
        return $this->encounter;
    }

    /**
     * @param CFHIRDataTypeReference $encounter
     */
    public function setEncounter(CFHIRDataTypeReference $encounter): void
    {
        $this->encounter = $encounter;
    }

    /**
     * @return mixed
     */
    public function getEffective()
    {
        return $this->effective;
    }

    /**
     * @param mixed $effective
     */
    public function setEffective($effective): void
    {
        $this->effective = $effective;
    }

    /**
     * @return CFHIRDataTypeInstant
     */
    public function getIssued(): CFHIRDataTypeInstant
    {
        return $this->issued;
    }

    /**
     * @param CFHIRDataTypeInstant $issued
     */
    public function setIssued(CFHIRDataTypeInstant $issued): void
    {
        $this->issued = $issued;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getPerformer(): CFHIRDataTypeReference
    {
        return $this->performer;
    }

    /**
     * @param CFHIRDataTypeReference $performer
     */
    public function setPerformer(CFHIRDataTypeReference $performer): void
    {
        $this->performer = $performer;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     */
    public function setValue($value): void
    {
        $this->value = $value;
    }

    /**
     * @return CFHIRDataTypeCodeableConcept
     */
    public function getDataAbsentReason(): CFHIRDataTypeCodeableConcept
    {
        return $this->dataAbsentReason;
    }

    /**
     * @param CFHIRDataTypeCodeableConcept $dataAbsentReason
     */
    public function setDataAbsentReason(CFHIRDataTypeCodeableConcept $dataAbsentReason): void
    {
        $this->dataAbsentReason = $dataAbsentReason;
    }

    /**
     * @return CFHIRDataTypeCodeableConcept
     */
    public function getInterpretation(): CFHIRDataTypeCodeableConcept
    {
        return $this->interpretation;
    }

    /**
     * @param CFHIRDataTypeCodeableConcept $interpretation
     */
    public function setInterpretation(CFHIRDataTypeCodeableConcept $interpretation): void
    {
        $this->interpretation = $interpretation;
    }

    /**
     * @return CFHIRDataTypeAnnotation
     */
    public function getNote(): CFHIRDataTypeAnnotation
    {
        return $this->note;
    }

    /**
     * @param CFHIRDataTypeAnnotation $note
     */
    public function setNote(CFHIRDataTypeAnnotation $note): void
    {
        $this->note = $note;
    }

    /**
     * @return CFHIRDataTypeCodeableConcept
     */
    public function getBodySite(): CFHIRDataTypeCodeableConcept
    {
        return $this->bodySite;
    }

    /**
     * @param CFHIRDataTypeCodeableConcept $bodySite
     */
    public function setBodySite(CFHIRDataTypeCodeableConcept $bodySite): void
    {
        $this->bodySite = $bodySite;
    }

    /**
     * @return CFHIRDataTypeCodeableConcept
     */
    public function getMethod(): CFHIRDataTypeCodeableConcept
    {
        return $this->method;
    }

    /**
     * @param CFHIRDataTypeCodeableConcept $method
     */
    public function setMethod(CFHIRDataTypeCodeableConcept $method): void
    {
        $this->method = $method;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getSpecimen(): CFHIRDataTypeReference
    {
        return $this->specimen;
    }

    /**
     * @param CFHIRDataTypeReference $specimen
     */
    public function setSpecimen(CFHIRDataTypeReference $specimen): void
    {
        $this->specimen = $specimen;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getDevice(): CFHIRDataTypeReference
    {
        return $this->device;
    }

    /**
     * @param CFHIRDataTypeReference $device
     */
    public function setDevice(CFHIRDataTypeReference $device): void
    {
        $this->device = $device;
    }

    /**
     * @return CFHIRDataTypeBackboneElement
     */
    public function getReferenceRange(): CFHIRDataTypeBackboneElement
    {
        return $this->referenceRange;
    }

    /**
     * @param CFHIRDataTypeBackboneElement $referenceRange
     */
    public function setReferenceRange(CFHIRDataTypeBackboneElement $referenceRange): void
    {
        $this->referenceRange = $referenceRange;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getHasMember(): CFHIRDataTypeReference
    {
        return $this->hasMember;
    }

    /**
     * @param CFHIRDataTypeReference $hasMember
     */
    public function setHasMember(CFHIRDataTypeReference $hasMember): void
    {
        $this->hasMember = $hasMember;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getDerivedFrom(): CFHIRDataTypeReference
    {
        return $this->derivedFrom;
    }

    /**
     * @param CFHIRDataTypeReference $derivedFrom
     */
    public function setDerivedFrom(CFHIRDataTypeReference $derivedFrom): void
    {
        $this->derivedFrom = $derivedFrom;
    }

    /**
     * @return CFHIRDataTypeReference
     */
    public function getComponent(): CFHIRDataTypeReference
    {
        return $this->component;
    }

    /**
     * @param CFHIRDataTypeReference $component
     */
    public function setComponent(CFHIRDataTypeReference $component): void
    {
        $this->component = $component;
    }

    /**
     * return CAbstractConstant
     */
    public function getClass(): ?string
    {
        return CAbstractConstant::class;
    }

    /**
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return (parent::generateCapabilities())
            ->addInteractions(
                [
                    CFHIRInteractionCreate::NAME,
                    CFHIRInteractionRead::NAME,
                    CFHIRInteractionSearch::NAME,
                    CFHIRInteractionUpdate::NAME,
                    CFHIRInteractionDelete::NAME,
                ]
            );
    }

    /**
     * @inheritdoc
     *
     * @param CStoredObject $object
     */
    final protected function map(CStoredObject $object): void
    {
        if ($this->object_mapping) {
            if (!$object->_id) {
                return;
            }

            parent::map($object);

            // basedOn
            $this->mapBasedOn();

            // partOf
            $this->mapPartOf();

            // status
            $this->mapStatus();

            // category
            $this->mapCategory();

            // code
            $this->mapCode();

            // subject
            $this->mapSubject();

            // focus
            $this->mapFocus();

            // encounter
            $this->mapEncounter();

            // effective
            $this->mapEffective();

            // issued
            $this->mapIssued();

            // performer
            $this->mapPerformer();

            // value
            $this->mapValue();

            // dataAbsentReason
            $this->mapDataAbsentReason();

            // interpretation
            $this->mapInterpretation();

            // note
            $this->mapNote();

            // bodySite
            $this->mapBodySite();

            // method
            $this->mapMethod();

            // specimen
            $this->mapSpecimen();

            // device
            $this->mapDevice();

            // referenceRange
            $this->mapReferenceRange();

            // component
            $this->mapComponent();
        }
    }

    /**
     * @throws Exception
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CAbstractConstant) {
            throw new CFHIRException("Object is not an observation");
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
        $mapping_object = Observation::class;

        return new $mapping_object();
    }

    /**
     * Map property basedOn
     */
    protected function mapBasedOn(): void
    {
        $this->basedOn = $this->object_mapping->mapBasedOn();
    }

    /**
     * Map property partOf
     */
    protected function mapPartOf(): void
    {
        $this->partOf = $this->object_mapping->mapPartOf();
    }

    /**
     * Map property status
     */
    protected function mapStatus(): void
    {
        $this->status = $this->object_mapping->mapStatus();
    }

    /**
     * Map property category
     */
    protected function mapCategory(): void
    {
        $this->category = $this->object_mapping->mapCategory();
    }

    /**
     * Map property code
     */
    protected function mapCode(): void
    {
        $this->code = $this->object_mapping->mapCode();
    }

    /**
     * Map property subject
     * @throws Exception
     * @throws InvalidArgumentException
     */
    protected function mapSubject(): void
    {
        $this->subject = $this->object_mapping->mapSubject();
    }

    /**
     * Map property focus
     */
    protected function mapFocus(): void
    {
        $this->focus = $this->object_mapping->mapFocus();
    }

    /**
     * Map property encounter
     */
    protected function mapEncounter(): void
    {
        $this->encounter = $this->object_mapping->mapEncounter();
    }

    /**
     * Map property effective
     */
    protected function mapEffective(): void
    {
        $this->effective = $this->object_mapping->mapEffective();
    }

    /**
     * Map property issued
     */
    protected function mapIssued(): void
    {
        $this->issued = $this->object_mapping->mapIssued();
    }

    /**
     * Map property performer
     * @throws Exception
     */
    protected function mapPerformer(): void
    {
        $this->performer = $this->object_mapping->mapPerformer();
    }

    /**
     * Map property value
     */
    protected function mapValue(): void
    {
        $this->value = $this->object_mapping->mapValue();
    }

    /**
     * Map property dataAbsentReason
     */
    protected function mapDataAbsentReason(): void
    {
        $this->dataAbsentReason = $this->object_mapping->mapDataAbsentReason();
    }

    /**
     * Map property interpretation
     */
    protected function mapInterpretation(): void
    {
        $this->interpretation = $this->object_mapping->mapInterpretation();
    }

    /**
     * Map property note
     */
    protected function mapNote(): void
    {
        $this->note = $this->object_mapping->mapNote();
    }

    /**
     * Map property bodySite
     */
    protected function mapBodySite(): void
    {
        $this->bodySite = $this->object_mapping->mapBodySite();
    }

    /**
     * Map property method
     */
    protected function mapMethod(): void
    {
        $this->method = $this->object_mapping->mapMethod();
    }

    /**
     * Map property specimen
     */
    protected function mapSpecimen(): void
    {
        $this->specimen = $this->object_mapping->mapSpecimen();
    }

    /**
     * Map property device
     */
    protected function mapDevice(): void
    {
        $this->device = $this->object_mapping->mapDevice();
    }

    /**
     * Map property referenceRange
     */
    protected function mapReferenceRange(): void
    {
        $this->referenceRange = $this->object_mapping->mapReferenceRange();
    }

    /**
     * Map property hasMember
     */
    protected function mapHasMember(): void
    {
        $this->hasMember = $this->object_mapping->mapHasMember();
    }

    /**
     * Map property derivedFrom
     */
    protected function mapDerivedFrom(): void
    {
        $this->derivedFrom = $this->object_mapping->mapDerivedFrom();
    }

    /**
     * Map property component
     */
    protected function mapComponent(): void
    {
        $this->component = $this->object_mapping->mapComponent();
    }
}
