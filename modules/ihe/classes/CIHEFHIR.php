<?php

/**
 * @package Hospilibre
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Ihe;


use Exception;
use Ox\Core\CClassMap;
use Ox\Interop\Eai\CMessageSupported;
use Ox\Interop\Fhir\ClassMap\FHIRClassMap;
use Ox\Interop\Fhir\Exception\CFHIRExceptionInformational;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;

/**
 * Déclinaison FHIR des transactions IHE.
 */
class CIHEFHIR extends CIHE
{
    /** @var string */
    public const TYPE = 'FHIR';

    /** @var string */
    protected const PREFIX_TRANSLATE_VERSION = 'FHIR';

    /**
     * Retourne, par ressource canonique, la liste de ses versions.
     *
     * @return array
     * @throws Exception
     */
    public function getCategoryVersions(): array
    {
        // Utilise le cache déjà calculé si disponible.
        if ($this->_versions_category) {
            return $this->_versions_category;
        }

        $class_map = new FHIRClassMap();
        $by_canonical = [];

        foreach (array_keys($this->_categories) as $canonical_name) {
            $by_canonical[$canonical_name] = $class_map->version->getResourceVersions($canonical_name);
        }

        $this->_versions_category = $by_canonical;

        return $this->_versions_category;
    }

    /**
     * Indique si la transaction du message correspond bien à la catégorie attendue.
     *
     * @param CMessageSupported $message_supported
     * @param string            $category
     *
     * @return bool
     */
    protected function isTransactionSupported(CMessageSupported $message_supported, string $category): bool
    {
        if (!$message_supported->transaction) {
            return false;
        }

        $class_map = new FHIRClassMap();
        $expected  = $class_map->resource->getResource($category);
        $current   = $class_map->resource->getResource($message_supported->transaction);

        return $current instanceof $expected;
    }

    /**
     * Nom court de la classe de profil associée à une ressource canonique.
     *
     * @param string $canonical
     *
     * @return string
     * @throws Exception
     */
    public function getResourceProfile(string $canonical): string
    {
        $class_map = new FHIRClassMap();
        $resource  = $class_map->resource->getResource($canonical);

        if (!$resource) {
            return '';
        }

        return CClassMap::getInstance()->getShortName($resource::PROFILE_CLASS);
    }

    /**
     * Résout le profil canonique d'une ressource pour un profil d'acteur donné.
     *
     * @param string $ref_canonical
     * @param string $profile_resource
     *
     * @return string
     * @throws Exception
     */
    public function getCanonical(string $ref_canonical, string $profile_resource): string
    {
        $class_map = new FHIRClassMap();
        $reference = $class_map->resource->getResource($ref_canonical);

        if (!$reference) {
            throw new CFHIRExceptionNotSupported("Resource '$ref_canonical' not supported");
        }

        /** @var CFHIR $profile_resource */
        $profile_resource = new $profile_resource();
        $profile_class    = get_class($profile_resource);

        /** @var CFHIRResource[] $candidates */
        $candidates = $class_map->resource->listResources($reference::RESOURCE_TYPE);
        $candidates = array_filter(
            $candidates,
            static function (CFHIRResource $candidate) use ($profile_class) {
                return $candidate::PROFILE_CLASS === $profile_class;
            }
        );

        // Une seule ressource profilée pour le même profil d'acteur : réponse immédiate.
        if (count($candidates) === 1) {
            return reset($candidates)->getProfile();
        }

        // Sinon, on tente de départager via le contexte de profilage.
        if ($reference::RESOURCE_CONTEXT_PROFILING) {
            $candidates = array_filter(
                $candidates,
                static function (CFHIRResource $candidate) use ($reference) {
                    return $reference::RESOURCE_CONTEXT_PROFILING === $candidate::RESOURCE_CONTEXT_PROFILING;
                }
            );

            if (count($candidates) === 1) {
                return reset($candidates)->getProfile();
            }
        }

        throw new CFHIRExceptionInformational(
            "The resource is possibly not supported or RESOURCE_CONTEXT_PROFILING is not defined and they are multiples profiling for the same resource and the same actor profile"
        );
    }
}
