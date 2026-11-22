<?php

/**
 * @package Mediboard\Fhir\Resources
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ClassMap;

use Ox\Core\CMbArray;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectHandleInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectSearcherInterface;

class DelegatedMap extends ObjectMap
{
    /** @var string */
    public const TYPE_DELEGATED_MAPPER = "mapper";
    /** @var string */
    public const TYPE_DELEGATED_SEARCHER = "searcher";
    /** @var string */
    public const TYPE_DELEGATED_HANDLE = "handle";

    /** @var string */
    protected const KEY_MAP = 'delegated';

    /**
     * Aiguille vers la liste correspondant au type de délégation demandé.
     *
     * @param string $type
     * @param string $canonical_or_type
     *
     * @return DelegatedObjectInterface[]|string[]
     */
    public function listDelegated(string $type, string $canonical_or_type = null): array
    {
        if ($type === self::TYPE_DELEGATED_HANDLE) {
            return $this->listDelegatedHandle($canonical_or_type);
        }

        if ($type === self::TYPE_DELEGATED_MAPPER) {
            return $this->listDelegatedMapper($canonical_or_type);
        }

        if ($type === self::TYPE_DELEGATED_SEARCHER) {
            return $this->listDelegatedSearcher($canonical_or_type);
        }

        return [];
    }

    /**
     * @param string $canonical_or_type
     *
     * @return DelegatedObjectMapperInterface[]|string[]
     */
    public function listDelegatedMapper(string $canonical_or_type = null): array
    {
        return $this->listSpecificDelegated('mapper', $canonical_or_type);
    }

    /**
     * Récupère, pour une clef de délégation donnée, l'ensemble ou le
     * sous-ensemble ciblé par un canonical (contenant un « / ») ou un type.
     *
     * @param string $key
     * @param string $canonical_or_type
     *
     * @return DelegatedObjectInterface[]|string[]
     */
    private function listSpecificDelegated(string $key, ?string $canonical_or_type): array
    {
        $fhir_version = $this->version;

        if (!$canonical_or_type) {
            return $this->out(CMbArray::getRecursive($this->map, "$fhir_version $key all", []));
        }

        // Un canonical se distingue par la présence d'un séparateur de chemin.
        $lookup = strrpos($canonical_or_type, '/') !== false ? 'canonical' : 'type';

        return $this->out(
            CMbArray::getRecursive($this->map, "$fhir_version $key $lookup $canonical_or_type", [])
        );
    }

    /**
     * @param string $canonical_or_type
     *
     * @return DelegatedObjectSearcherInterface[]|string[]
     */
    public function listDelegatedSearcher(string $canonical_or_type = null): array
    {
        return $this->listSpecificDelegated('searcher', $canonical_or_type);
    }

    /**
     * @param string $canonical_or_type
     *
     * @return DelegatedObjectHandleInterface[]|string[]
     */
    public function listDelegatedHandle(string $canonical_or_type = null): array
    {
        return $this->listSpecificDelegated('handle', $canonical_or_type);
    }

    /**
     * @param string $short_name
     *
     * @return DelegatedObjectHandleInterface|string|null
     */
    public function getDelegatedMapperFromShortName(string $short_name)
    {
        return $this->getDelegatedFromShortName('mapper', $short_name);
    }

    public function getDelegatedSearcherFromShortName(string $short_name)
    {
        return $this->getDelegatedFromShortName('searcher', $short_name);
    }

    public function getDelegatedHandleFromShortName(string $short_name)
    {
        return $this->getDelegatedFromShortName('handle', $short_name);
    }

    /**
     * Résout un délégué à partir de son nom court : la résolution n'aboutit
     * que si exactement une classe se termine par ce nom.
     *
     * @param string $key
     * @param string $short_name
     *
     * @return DelegatedObjectHandleInterface|string
     */
    public function getDelegatedFromShortName(string $key, string $short_name)
    {
        $fhir_version = $this->version;
        $candidates   = CMbArray::getRecursive($this->map, "$fhir_version $key all", []);

        $matches = array_filter(
            $candidates,
            static fn($class_name): bool => str_ends_with($class_name, "\\$short_name")
        );

        if (count($matches) !== 1) {
            return null;
        }

        return $this->out(reset($matches));
    }
}
