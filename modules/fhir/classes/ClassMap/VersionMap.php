<?php

/**
 * @package Mediboard\Fhir\Resources
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ClassMap;

use Exception;
use Ox\Core\CMbArray;

class VersionMap extends ObjectMap
{
    /** @var string */
    protected const KEY_MAP = 'versions';

    /**
     * Retourne les versions FHIR supportées, éventuellement restreintes à une ressource canonique.
     *
     * @param string|null $canonical Nom canonique de la ressource, ou null pour l'ensemble
     *
     * @return string[]
     */
    public function getSupportedFhirVersions(?string $canonical = null): array
    {
        // Sans canonique, on remonte la liste globale des versions.
        if (!$canonical) {
            return CMbArray::getRecursive($this->map, 'fhir_version', []);
        }

        return CMbArray::getRecursive($this->map, "canonical $canonical fhir_version", []);
    }

    /**
     * Versions FHIR déclarées pour une ressource canonique donnée.
     *
     * @param string $canonical Nom canonique de la ressource
     *
     * @return string[]
     */
    public function getResourceVersions(string $canonical): array
    {
        $path = "canonical $canonical fhir_version";

        return CMbArray::getRecursive($this->map, $path, []);
    }

    /**
     * Dernière version FHIR supportée (la plus récente de la liste).
     *
     * @return string
     * @throws Exception
     */
    public function getLastSupportedFhirVersion(): string
    {
        $supported = $this->getSupportedFhirVersions();

        return end($supported);
    }
}
