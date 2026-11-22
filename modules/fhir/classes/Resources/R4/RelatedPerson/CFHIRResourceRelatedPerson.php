<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\RelatedPerson;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Resources\ResourceRelatedPersonInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientCommunication;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAttachment;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypePeriod;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Hospilibre\Patients\CCorrespondantPatient;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * FHIR RelatedPerson ressource
 */
class CFHIRResourceRelatedPerson extends CFHIRDomainResource implements ResourceRelatedPersonInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = "RelatedPerson";

    // attributes
    /** @var CFHIRDataTypeIdentifier[] */
    public $identifier;

    /** @var CFHIRDataTypeBoolean */
    public $active;

    /** @var CFHIRDataTypeReference */
    public $patient;

    /** @var CFHIRDataTypeCodeableConcept[] */
    public $relationship;

    /** @var CFHIRDataTypeHumanName[] */
    public $name;

    /** @var CFHIRDataTypeContactPoint[] */
    public $telecom;

    /** @var CFHIRDataTypeCode */
    public $gender;

    /** @var CFHIRDataTypeDate */
    public $birthDate;

    /** @var CFHIRDataTypeAddress[] */
    public $address;

    /** @var CFHIRDataTypePeriod */
    public $period;

    /** @var CFHIRDataTypeAttachment[] */
    public $photo;

    /** @var CFHIRDataTypePatientCommunication[] */
    public $communication;

    /** @var CCorrespondantPatient */
    protected $object;

    /**
     * return CCorrespondantPatient
     */
    public function getClass(): ?string
    {
        return CCorrespondantPatient::class;
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
    protected function map(CStoredObject $object): void
    {
        if (!$object->_id) {
            return;
        }

        parent::map($object);

        // active
        $this->mapActive();

        // patient
        $this->mapPatient();

        // patient
        $this->mapRelationship();

        // name
        $this->mapName();

        // telecom
        $this->mapTelecom();

        // gender
        $this->mapGender();

        // birthdate
        $this->mapBirthDate();

        // address
        $this->mapAddress();

        // photo
        $this->mapPhoto();

        // photo
        $this->mapPeriod();

        // communication
        $this->mapCommunication();
    }

    /**
     * @inheritdoc
     * @throws Exception
     * @throws CFHIRException|InvalidArgumentException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CCorrespondantPatient) {
            throw new CFHIRException("Object is not a related person");
        }

        $this->mapFrom($object);
    }

    /**
     * Map property active
     */
    protected function mapActive(): void
    {
        $this->active = new CFHIRDataTypeBoolean(true);
    }

    /**
     * Map property patient
     */
    protected function mapPatient(): void
    {
        $patient       = $this->object->loadRefPatient();
        $this->patient = $this->addReference(CFHIRResourcePatient::class, $patient);
    }

    /**
     * Map property relationship
     */
    protected function mapRelationship(): void
    {
        $relation = $this->object->parente;
        $system   = 'urn:oid:2.16.840.1.113883.4.642.3.449';

        //TODO Etayer les relations
        switch ($relation) {
            case 'mere':
                $code    = 'MTH';
                $display = 'mother';
                break;
            case 'pere':
                $code    = 'FTH';
                $display = 'father';
                break;
            default:
                $code    = 'CHILD';
                $display = 'child';
                break;
        }

        $text = $display;

        $coding = $this->addCoding($system, $code, $display);

        $this->relationship[] = $this->addCodeableConcepts(
            $coding,
            $text
        );
    }

    /**
     * Map property name
     */
    protected function mapName(): void
    {
        // name
        $names = $this->addName(
            $this->object->nom_jeune_fille ?: $this->object->nom,
            $this->object->prenom,
            'official'
        );

        $has_usual_familly = $this->object->nom && $this->object->nom !== $this->object->nom_jeune_fille;

        if ($has_usual_familly) {
            $names = $this->addName(
                $this->object->nom,
                $this->object->prenom,
                'usual',
                null,
                $names
            );
        }

        $this->name = $names;
    }

    /**
     * Map property telecom
     */
    protected function mapTelecom(): void
    {
        if ($this->object->tel) {
            $this->telecom[] = CFHIRDataTypeContactPoint::build(
                [
                    "system" => 'phone',
                    "value"  => $this->object->tel,
                ]
            );
        }

        if ($this->object->tel_autre) {
            $this->telecom[] = CFHIRDataTypeContactPoint::build(
                [
                    "system" => 'phone',
                    "value"  => $this->object->tel_autre,
                ]
            );
        }

        if ($this->object->mob) {
            $this->telecom[] = CFHIRDataTypeContactPoint::build(
                [
                    "system" => 'phone',
                    "value"  => $this->object->mob,
                ]
            );
        }

        if ($this->object->fax) {
            $this->telecom[] = CFHIRDataTypeContactPoint::build(
                [
                    "system" => 'fax',
                    "value"  => $this->object->fax,
                ]
            );
        }

        if ($this->object->email) {
            $this->telecom[] = CFHIRDataTypeContactPoint::build(
                [
                    "system" => 'email',
                    "value"  => $this->object->email,
                ]
            );
        }
    }

    /**
     * Map property gender
     */
    protected function mapGender(): void
    {
        $this->gender = new CFHIRDataTypeCode($this->formatGender($this->object->sex));
    }

    /**
     * Map property birthDate
     */
    protected function mapBirthDate(): void
    {
        $this->birthDate = new CFHIRDataTypeDate($this->object->naissance);
    }

    /**
     * Map property address
     */
    protected function mapAddress(): void
    {
        if ($this->object->adresse || $this->object->ville || $this->object->cp) {
            $this->address[] = CFHIRDataTypeAddress::build(
                [
                    'use'        => 'work',
                    'type'       => 'postal',
                    'line'       => preg_split('/[\r\n]+/', $this->object->adresse) ?? null,
                    'city'       => $this->object->ville ?? null,
                    'postalCode' => $this->object->cp ?? null,
                ]
            );
        }
    }

    /**
     * Map property photo
     */
    protected function mapPhoto(): void
    {
        // not implemented
    }

    /**
     * Map property period
     */
    protected function mapPeriod(): void
    {
        // not implemented
    }

    /**
     * Map property communication
     */
    protected function mapCommunication(): void
    {
        $system  = 'urn:oid:2.16.840.1.113883.4.642.3.20';
        $code    = 'fr-FR';
        $display = 'French (France)';
        $coding  = $this->addCoding($system, $code, $display);
        $text    = 'FranÎ·ais de France';

        $language = $this->addCodeableConcepts(
            $coding,
            $text
        );

        $preferred = new CFHIRDataTypeBoolean(true);

        $patientCommunication            = new CFHIRDataTypePatientCommunication();
        $patientCommunication->language  = $language;
        $patientCommunication->preferred = $preferred;

        $this->communication[] = $patientCommunication;
    }
}
