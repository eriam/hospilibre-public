<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Practitioner;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourcePractitionerInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Practitioner\CFHIRDataTypePractitionerQualification;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAttachment;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Mapper\Practitioner;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterString;
use Hospilibre\Patients\CMedecin;

/**
 * FHIR practitioner resource
 */
class CFHIRResourcePractitioner extends CFHIRDomainResource implements ResourcePractitionerInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'Practitioner';

    // attributes
    /** @var CFHIRDataTypeBoolean */
    public $active;

    /** @var CFHIRDataTypeHumanName[] */
    public $name;

    /** @var CFHIRDataTypeContactPoint[] */
    public $telecom;

    /** @var CFHIRDataTypeAddress[] */
    public $address;

    /** @var CFHIRDataTypeCode */
    public $gender;

    /** @var CFHIRDataTypeDate */
    public $birthDate;

    /** @var CFHIRDataTypeAttachment[] */
    public $photo;

    /** @var CFHIRDataTypePractitionerQualification[] */
    public $qualification;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $communication;

    /** @var CMedecin */
    protected $object;

    /** @var PractitionerMappingInterface */
    protected $object_mapping;

    /**
     * return CMediusers
     */
    public function getClass(): ?string
    {
        return CMedecin::class;
    }

    /**
     * @return CCapabilitiesResource
     */
    protected function generateCapabilities(): CCapabilitiesResource
    {
        return parent::generateCapabilities()
            ->addInteractions(
                [
                    CFHIRInteractionCreate::NAME,
                    CFHIRInteractionRead::NAME,
                    CFHIRInteractionSearch::NAME,
                    CFHIRInteractionUpdate::NAME,
                    CFHIRInteractionDelete::NAME,
                ]
            )
            ->addSearchAttributes(
                [
                    new SearchParameterString('family'),
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
        if (!$object->_id) {
            return;
        }

        parent::map($object);

        // active
        $this->mapActive();

        // name
        $this->mapName();

        // telecom
        $this->mapTelecom();

        // address
        $this->mapAddress();

        // gender
        $this->mapGender();

        // birthdate
        $this->mapBirthDate();

        // photo
        $this->mapPhoto();

        // qualification
        $this->mapQualification();

        // communication
        $this->mapCommunication();
    }

    /**
     * @throws Exception
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CMedecin) {
            throw new CFHIRException("Object is not a practitioner");
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
        $mapping_object = Practitioner::class;

        return new $mapping_object();
    }

    /**
     * Map property active
     */
    protected function mapActive(): void
    {
        $this->active = $this->object_mapping->mapActive();
    }

    /**
     * Map property name
     */
    protected function mapName(): void
    {
        $this->name = $this->object_mapping->mapName();
    }

    /**
     * Map property telecom
     */
    protected function mapTelecom(): void
    {
        $this->telecom = $this->object_mapping->mapTelecom();
    }

    /**
     * Map property address
     */
    protected function mapAddress(): void
    {
        $this->address = $this->object_mapping->mapAddress();
    }

    /**
     * Map property gender
     */
    protected function mapGender(): void
    {
        $this->gender = $this->object_mapping->mapGender();
    }

    /**
     * Map property birthDate
     */
    protected function mapBirthDate(): void
    {
        $this->birthDate = $this->object_mapping->mapBirthDate();
    }

    /**
     * Map property photo
     */
    protected function mapPhoto(): void
    {
        $this->photo = $this->object_mapping->mapPhoto();
    }

    /**
     * Map property qualification
     */
    protected function mapQualification(): void
    {
        $this->qualification = $this->object_mapping->mapQualification();
    }

    /**
     * Map property communication
     */
    protected function mapCommunication(): void
    {
        $this->communication = $this->object_mapping->mapCommunication();
    }
}
