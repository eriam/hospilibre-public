<?php

/**
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\PractitionerRole\Profiles\AnnuaireSante;

use Exception;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Profiles\CFHIRAnnuaireSante;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\CFHIRResourcePractitionerRole;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\Mapper\AnnuaireSante\PractitionerRoleMappingOrganizationalRassExercicePlace;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\Mapper\AnnuaireSante\PractitionerRoleMappingOrganizationalRassMediusers;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\PractitionerRoleMappingInterface;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CExercicePlace;

class CFHIRResourcePractitionerRoleOrganizationalRass extends CFHIRResourcePractitionerRole
{
    /** @var string Identifiant du profil RASS organisationnel */
    public const PROFILE_TYPE = 'practitionerRole-organizationalRole-rass';

    /** @var string Classe de profil FHIR associee */
    public const PROFILE_CLASS = CFHIRAnnuaireSante::class;

    /** @var CExercicePlace Objet metier porte par la ressource */
    public $object;

    /**
     * Retourne la classe metier attachee a ce profil.
     */
    public function getClass(): ?string
    {
        return CExercicePlace::class;
    }

    /**
     * Selectionne le mapper adapte au type d'objet fourni.
     *
     * @param CStoredObject $object Objet a mapper
     *
     * @return PractitionerRoleMappingInterface
     */
    protected function setMapperOld(CStoredObject $object): ?DelegatedObjectMapperInterface
    {
        $mapper_class = null;

        switch (get_class($object)) {
            case CExercicePlace::class:
                $mapper_class = PractitionerRoleMappingOrganizationalRassExercicePlace::class;
                break;

            case CMediusers::class:
                $mapper_class = PractitionerRoleMappingOrganizationalRassMediusers::class;
                break;

            default:
                throw new CFHIRExceptionNotSupported('Mapping object not supported');
        }

        return new $mapper_class();
    }

    /**
     * Mapping de l'extension : interdit pour ce profil.
     *
     * @throws Exception
     */
    protected function mapPractitioner(): void
    {
        // interdit dans ce profil
    }

    protected function mapSpecialty(): void
    {
        // interdit dans ce profil
    }

    protected function mapHealthCareService(): void
    {
        // interdit dans ce profil
    }

    protected function mapAvailabilityExceptions(): void
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

    protected function mapEndpoint(): void
    {
        // interdit dans ce profil
    }
}
