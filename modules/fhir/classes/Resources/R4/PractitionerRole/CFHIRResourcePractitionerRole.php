<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\PractitionerRole;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\PractitionerRoleMappingInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourcePractitionerRoleInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\PractitionerRole\CFHIRDataTypePractitionerRoleAvailableTime;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\PractitionerRole\CFHIRDataTypePractitionerRolerNotAvailable;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\Mapper\PractitionerRoleMappingMedecin;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\Mapper\PractitionerRoleMappingMediusers;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterString;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CMedecin;

/**
 * FHIR practitionerRole resource
 */
class CFHIRResourcePractitionerRole extends CFHIRDomainResource implements ResourcePractitionerRoleInterface
{
    // constants
    /** @var string Resource type */
    public const RESOURCE_TYPE = "PractitionerRole";

    // attributes
    /** @var CFHIRDataTypeBoolean */
    public $active;

    /** @var CFHIRDataTypePeriod */
    public $period;

    /** @var CFHIRDataTypeReference */
    public $practitioner;

    /** @var CFHIRDataTypeReference */
    public $organization;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $code;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $specialty;

    /** @var CFHIRDataTypeReference[] */
    public $location;

    /** @var CFHIRDataTypeReference[] */
    public $healthcareService;

    /** @var CFHIRDataTypeContactPoint[] */
    public $telecom;

    /** @var CFHIRDataTypePractitionerRoleAvailableTime[] */
    public $availableTime;

    /** @var CFHIRDataTypePractitionerRolerNotAvailable[] */
    public $notAvailable;

    /** @var CFHIRDataTypeString */
    public $availabilityExceptions;

    /** @var CFHIRDataTypeReference[] */
    public $endpoint;

    /** @var CMediusers */
    public $object;

    /** @var PractitionerRoleMappingInterface */
    public $object_mapping;

    /**
     * return CMediusers
     */
    public function getClass(): ?string
    {
        return CMediusers::class;
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
                    CFHIRInteractionCreate::NAME,
                    CFHIRInteractionSearch::NAME,
                    CFHIRInteractionDelete::NAME,
                    CFHIRInteractionUpdate::NAME,
                ]
            )
            ->addSearchAttributes(
                [
                    new SearchParameterString('specialty'),
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

        $this->mapActive();

        $this->mapPeriod();

        $this->mapPractitioner();

        $this->mapOrganization();

        $this->mapCode();

        $this->mapSpecialty();

        $this->mapLocation();

        $this->mapHealthCareService();

        $this->mapTelecom();

        $this->mapAvailableTime();

        $this->mapNotAvailable();

        $this->mapAvailabilityExceptions();

        $this->mapEndpoint();
    }

    /**
     * @throws Exception
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        $this->mapFrom($object);
    }

    /**
     * Map property active
     */
    protected function mapActive(): void
    {
        $this->active = $this->object_mapping->mapActive();
    }

    /**
     * Map property period
     */
    protected function mapPeriod(): void
    {
        $this->period = $this->object_mapping->mapPeriod();
    }

    /**
     * Map property practitioner
     */
    protected function mapPractitioner(): void
    {
        $this->practitioner = $this->object_mapping->mapPractitioner();
    }

    /**
     * Map property organization
     */
    protected function mapOrganization(): void
    {
        $this->organization = $this->object_mapping->mapOrganization();
    }

    /**
     * Map property code
     */
    protected function mapCode(): void
    {
        $this->code = $this->object_mapping->mapCode();
    }

    /**
     * Map property speciality
     */
    protected function mapSpecialty(): void
    {
        $this->specialty = $this->object_mapping->mapSpecialty();
    }

    /**
     * Map property location
     */
    protected function mapLocation(): void
    {
        $this->location = $this->object_mapping->mapLocation();
    }

    /**
     * Map property healthcareService
     */
    protected function mapHealthCareService(): void
    {
        $this->healthcareService = $this->object_mapping->mapHealthCareService();
    }

    /**
     * Map property telecom
     */
    protected function mapTelecom(): void
    {
        $this->telecom = $this->object_mapping->mapTelecom();
    }

    /**
     * Map property availableTime
     */
    protected function mapAvailableTime(): void
    {
        $this->availableTime = $this->object_mapping->mapAvailableTime();
    }

    /**
     * Map property notAvailable
     */
    protected function mapNotAvailable(): void
    {
        $this->availabilityExceptions = $this->object_mapping->mapNotAvailable();
    }

    /**
     * Map property availabilityExceptions
     */
    protected function mapAvailabilityExceptions(): void
    {
        $this->availabilityExceptions = $this->object_mapping->mapAvailabilityExceptions();
    }

    /**
     * Map property endpoint
     */
    protected function mapEndpoint(): void
    {
        $this->endpoint = $this->object_mapping->mapEndpoint();
    }

    /**
     * @param CStoredObject $object
     *
     * @return PractitionerRoleMappingInterface
     */
    protected function setMapperOld(CStoredObject $object): ?DelegatedObjectMapperInterface
    {
        switch (get_class($object)) {
            case CMediusers::class:
                $mapping_object = PractitionerRoleMappingMediusers::class;
                break;
            case CMedecin::class:
                $mapping_object = PractitionerRoleMappingMedecin::class;
                break;
            default:
                throw new CFHIRExceptionNotSupported('Mapping object not supported');
        }

        return new $mapping_object();
    }
}
