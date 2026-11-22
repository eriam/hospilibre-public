<?php

/**
 * @package Hospilibre
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Patient\Profiles\InteropSante;

use Exception;
use Hospilibre\Patients\CPatient;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\PatientMappingInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientContact;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Ox\Interop\Fhir\Resources\R4\Patient\Mapper\FrPatient;
use Ox\Interop\Fhir\Resources\R4\Patient\PatientInterface;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\InteropSante\CFHIRResourcePractitionerFR;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;

/**
 * Ressource FHIR patient conforme au profil InteropSanté (FR).
 */
class CFHIRResourcePatientFR extends CFHIRResourcePatient
{
    /** @var string Identifiant du profil FR appliqué à la ressource. */
    public const PROFILE_TYPE = 'FrPatient';

    /** @var string Classe de profil FHIR de référence. */
    public const PROFILE_CLASS = CFHIRInteropSante::class;

    /** @var CFHIRDataTypeExtension[] */
    public $extension;

    /** @var CFHIRDataTypeIdentifier[] */
    public $identifier;

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

    /** @var CFHIRDataTypePatientContact[] */
    public $contact;

    /** @var CFHIRDataTypeReference[] */
    public $generalPractitioner;

    /** @var CFHIRDataTypeReference */
    public $managingOrganization;

    /** @var CPatient */
    protected $object;

    /** @var PatientMappingInterface */
    protected $object_mapping;

    /**
     * Retourne la classe métier associée à cette ressource.
     */
    public function getClass(): ?string
    {
        return CPatient::class;
    }

    /**
     * Déclare les interactions FHIR supportées par la ressource patient FR.
     *
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        $capabilities = parent::generateCapabilities();

        return $capabilities->addInteractions([
            CFHIRInteractionCreate::NAME,
            CFHIRInteractionRead::NAME,
            CFHIRInteractionSearch::NAME,
            CFHIRInteractionUpdate::NAME,
            CFHIRInteractionDelete::NAME,
        ]);
    }

    /**
     * Fabrique le mapper (ancienne mécanique) pour l'objet fourni.
     *
     * @param CStoredObject $object
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        $mapper_class = FrPatient::class;

        return new $mapper_class();
    }

    /**
     * Mappe la propriété extension.
     */
    protected function mapExtension(): void
    {
        $this->object_mapping->mapExtension();
    }

    /**
     * Mappe la propriété identifier.
     *
     * @throws Exception
     */
    protected function mapIdentifier(): void
    {
        $this->object_mapping->mapIdentifier();
    }

    /**
     * Mappe la propriété name.
     */
    protected function mapName(): void
    {
        $this->object_mapping->mapName();
    }

    /**
     * Mappe la propriété telecom.
     */
    protected function mapTelecom(): void
    {
        $this->object_mapping->mapTelecom();
    }

    /**
     * Mappe la propriété address.
     */
    protected function mapAddress(): void
    {
        $this->object_mapping->mapAddress();
    }

    /**
     * Mappe la propriété generalPractitioner.
     */
    protected function mapGeneralPractitioner(): void
    {
        $this->object_mapping->mapGeneralPractitioner(CFHIRResourcePractitionerFR::class);
    }

    /**
     * Mappe la propriété contact.
     */
    protected function mapContact(): void
    {
        $this->object_mapping->mapContact();
    }
}
