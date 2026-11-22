<?php

/**
 * @package Mediboard\Fhir\Resources
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ClassMap;

use Ox\Core\CMbArray;
use Ox\Interop\Fhir\Exception\CFHIRExceptionInformational;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Symfony\Component\HttpFoundation\Response;

class ResourceMap extends ObjectMap
{
    /** @var string */
    protected const KEY_MAP = 'resource';

    /**
     * Résout une ressource à partir d'un canonical, d'un type ou d'une classe de ressource.
     *
     * @param string $canonical_or_type canonical | resource_type | resource_class
     *
     * @return CFHIRResource|string
     */
    public function getResource(string $canonical_or_type)
    {
        $fhir_version = $this->version;

        $canonicals = CMbArray::getRecursive($this->class_map, "resource $fhir_version canonical", []);

        // La valeur reçue est déjà une classe de ressource connue.
        if (is_subclass_of($canonical_or_type, CFHIRResource::class)
            && array_search($canonical_or_type, $canonicals)) {
            return $this->out($canonical_or_type);
        }

        // Recherche directe par canonical.
        $by_canonical = CMbArray::get($canonicals, "$canonical_or_type");
        if ($by_canonical) {
            return $this->out($by_canonical);
        }

        // Repli : recherche par type (uniquement pour les ressources internationales).
        $by_type = $this->listResources($canonical_or_type, CFHIR::class);
        if ($by_type) {
            return $this->out(reset($by_type));
        }

        throw new CFHIRExceptionInformational(
            "It is impossible to resolve the resource '$canonical_or_type' with version '$fhir_version'",
            Response::HTTP_INTERNAL_SERVER_ERROR,
            [],
            CFHIRExceptionInformational::CODE_RESOURCE_NOT_SUPPORTED_NOW
        );
    }

    /**
     * Liste les ressources, éventuellement restreintes à un type et/ou à un profil.
     *
     * @param string|null       $resource_type
     * @param CFHIR|string|null $profile
     *
     * @return CFHIRResource[]|string[]
     */
    public function listResources(?string $resource_type = null, $profile = null): array
    {
        $fhir_version = $this->version;

        if ($resource_type) {
            $resources = CMbArray::getRecursive($this->map, "$fhir_version type $resource_type", []);
        } else {
            $resources = array_values(CMbArray::getRecursive($this->map, "$fhir_version canonical", []));
        }

        if ($profile) {
            return $this->out($this->filterByProfile($resources, $profile));
        }

        return $this->out($resources);
    }

    /**
     * Restreint un ensemble de ressources à celles rattachées au profil fourni.
     *
     * @param array        $resources
     * @param CFHIR|string $profile
     *
     * @return array
     */
    private function filterByProfile(array $resources, $profile): array
    {
        $fhir_version = $this->version;

        if ($profile instanceof CFHIR) {
            $profile = get_class($profile);
        }

        if (!is_string($profile)) {
            return [];
        }

        $canonical_map = CMbArray::getRecursive($this->map, "$fhir_version canonical", []);
        $matching      = array_filter(
            $canonical_map,
            static function ($resource_class) use ($resources): bool {
                return in_array($resource_class, $resources);
            }
        );

        if ($profile !== CFHIR::class && !is_subclass_of($profile, CFHIR::class)) {
            $profile = CMbArray::getRecursive($this->class_map, "profiles $fhir_version profile $profile", null);
        }

        $canonical_from_profile = CMbArray::getRecursive(
            $this->class_map,
            "profiles $fhir_version profile_class $profile",
            []
        );

        $matching = array_filter(
            $matching,
            static function ($canonical) use ($canonical_from_profile): bool {
                return in_array($canonical, $canonical_from_profile);
            },
            ARRAY_FILTER_USE_KEY
        );

        return array_values($matching);
    }

    /**
     * Liste les ressources profilées, hors profil international par défaut.
     *
     * @return CFHIRResource[]|string[]
     */
    public function listProfiled(?string $profile = null): array
    {
        $fhir_version = $this->version;

        $path             = "profiles $fhir_version profile_class" . ($profile ? " $profile" : '');
        $profiled_classes = CMbArray::getRecursive($this->class_map, $path, []);

        // On ne conserve que les ressources profilées.
        unset($profiled_classes[CFHIR::class]);

        $profiled_classes = CMbArray::array_flatten($profiled_classes);

        $canonical_map = CMbArray::getRecursive($this->map, "$fhir_version canonical", []);
        $matching      = array_filter(
            $canonical_map,
            static function ($canonical) use ($profiled_classes): bool {
                return in_array($canonical, $profiled_classes);
            },
            ARRAY_FILTER_USE_KEY
        );

        return $this->out(array_values($matching));
    }
}
