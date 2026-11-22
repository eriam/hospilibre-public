<?php

/**
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\PractitionerRole\Profiles\AnnuaireSante;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Profiles\CFHIRAnnuaireSante;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\CFHIRResourcePractitionerRole;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\Mapper\AnnuaireSante\PractitionerRoleProfessionalRassMedecin;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\Mapper\AnnuaireSante\PractitionerRoleProfessionalRassMediusers;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\PractitionerRoleMappingInterface;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CMedecin;

/**
 * Profil PractitionerRole « professionnel » de l'Annuaire Santé (RASS).
 *
 * Dans ce profil, la plupart des éléments transverses du PractitionerRole
 * (organisation, localisation, service, disponibilités, endpoint) sont
 * volontairement neutralisés.
 */
class CFHIRResourcePractitionerRoleProfessionalRass extends CFHIRResourcePractitionerRole
{
    /** @var string Identifiant du profil */
    public const PROFILE_TYPE = 'practitionerRole-professionalRole-rass';

    /** @var string Classe de profil associée */
    public const PROFILE_CLASS = CFHIRAnnuaireSante::class;

    /** @var CMedecin|CMediusers */
    public $object;

    /**
     * Classe métier gérée par ce profil.
     */
    public function getClass(): ?string
    {
        return CMedecin::class;
    }

    /**
     * Sélectionne le mapper adapté au type d'objet fourni.
     *
     * @param CStoredObject $object Objet source à mapper
     *
     * @return PractitionerRoleMappingInterface
     */
    protected function setMapperOld(CStoredObject $object): ?DelegatedObjectMapperInterface
    {
        $mappers = [
            CMediusers::class => PractitionerRoleProfessionalRassMediusers::class,
            CMedecin::class   => PractitionerRoleProfessionalRassMedecin::class,
        ];

        $sourceClass = get_class($object);

        if (!isset($mappers[$sourceClass])) {
            throw new CFHIRExceptionNotSupported('Mapping object not supported');
        }

        $mapperClass = $mappers[$sourceClass];

        return new $mapperClass();
    }

    protected function mapOrganization(): void
    {
        // interdit dans ce profil
    }

    protected function mapLocation(): void
    {
        // interdit dans ce profil
    }

    protected function mapHealthCareService(): void
    {
        // interdit dans ce profil
    }

    protected function mapAvailableTime(): void
    {
        // interdit dans ce profil
    }

    protected function mapNotAvailable(): void
    {
        // interdit dans ce profil
    }

    protected function mapAvailabilityExceptions(): void
    {
        // interdit dans ce profil
    }

    protected function mapEndpoint(): void
    {
        // interdit dans ce profil
    }
}
