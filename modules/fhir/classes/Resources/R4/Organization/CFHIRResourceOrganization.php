<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Organization;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\OrganizationMappingInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourceOrganizationInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Organization\CFHIRDataTypeOrganizationContact;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Organization\Mapper\Organization;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\Patients\CExercicePlace;

/**
 * FHIR organization resource
 */
class CFHIRResourceOrganization extends CFHIRDomainResource implements ResourceOrganizationInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'Organization';

    /** @var CFHIRDataTypeIdentifier[] */
    public $identifier;

    /** @var CFHIRDataTypeBoolean */
    public $active;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $type;

    /** @var CFHIRDataTypeString */
    public $name;

    /** @var CFHIRDataTypeString[] */
    public $alias;

    /** @var CFHIRDataTypeContactPoint[] */
    public $telecom;

    /** @var CFHIRDataTypeAddress[] */
    public $address;

    /** @var CFHIRDataTypeReference */
    public $partOf;

    /** @var CFHIRDataTypeOrganizationContact[] */
    public $contact;

    /** @var CFHIRDataTypeReference */
    public $endpoint;

    /** @var CExercicePlace */
    protected $object;

    /** @var OrganizationMappingInterface */
    protected $object_mapping;

    /**
     * return CExercicePlace
     */
    public function getClass(): ?string
    {
        return CExercicePlace::class;
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
        if (!$object->_id) {
            return;
        }

        parent::map($object);

        // active
        $this->mapActive();

        // type
        $this->mapType();

        // name
        $this->mapName();

        // alias
        $this->mapAlias();

        // telecom
        $this->mapTelecom();

        // address
        $this->mapAddress();

        // partOf
        $this->mapPartOf();

        // contact
        $this->mapContact();

        // endpoint
        $this->mapEndpoint();
    }

    /**
     * @throws Exception
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CExercicePlace) {
            throw new CFHIRException('Object is not an organization');
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
        $mapping_object = Organization::class;

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
     * Map property type
     */
    protected function mapType(): void
    {
        $this->type = $this->object_mapping->mapType();
    }

    /**
     * Map property name
     */
    protected function mapName(): void
    {
        $this->name = $this->object_mapping->mapName();
    }

    /**
     * Map property alias
     */
    protected function mapAlias(): void
    {
        $this->alias = $this->object_mapping->mapAlias();
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
     * Map property partOf
     */
    protected function mapPartOf(): void
    {
        $this->partOf = $this->object_mapping->mapPartOf();
    }

    /**
     * Map property contact
     */
    protected function mapContact(): void
    {
        $this->contact = $this->object_mapping->mapContact();
    }

    /**
     * Map property endpoint
     */
    protected function mapEndpoint(): void
    {
        $this->endpoint = $this->object_mapping->mapEndpoint();
    }
}
