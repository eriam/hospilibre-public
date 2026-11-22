<?php

/**
 * @package Mediboard\Fhir\Resources
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ClassMap;

use Ox\Components\Cache\Exceptions\CouldNotGetCache;
use Ox\Components\Cache\LayeredCache;
use Ox\Core\Cache;
use Ox\Core\CAppUI;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Psr\SimpleCache\InvalidArgumentException;

class FHIRClassMap
{
    /** @var string */
    private const CACHE_KEY = 'FHIR-map';
    /** @var int */
    private const CACHE_TTL = 600;

    /** @var array */
    private $map = [];

    /** @var string  */
    private $default_fhir_version;

    /** @var LayeredCache */
    private $cache;

    /** @var ProfileMap */
    public $profile;

    /** @var ResourceMap */
    public $resource;

    /** @var DelegatedMap */
    public $delegated;

    /** @var VersionMap */
    public $version;

    /**
     * Initialise la carte des classes FHIR.
     *
     * @param string[] $resources
     *
     * @throws InvalidArgumentException
     * @throws CouldNotGetCache
     */
    public function __construct(array $resources = [])
    {
        $this->cache                = Cache::getCache(Cache::INNER_OUTER);
        $this->default_fhir_version = CAppUI::conf('fhir general version', 'static');
        $this->map                  = $this->getOrBuildClassMap($resources);
    }

    /**
     * Propage la version FHIR retenue à chaque sous-carte.
     *
     * @param string $version
     *
     * @return $this
     */
    public function selectVersion(string $version): self
    {
        $this->profile->selectVersion($version);
        $this->resource->selectVersion($version);
        $this->delegated->selectVersion($version);

        return $this;
    }

    /**
     * Récupère la carte depuis le cache ou la (re)construit au besoin.
     *
     * @param string[] $resources
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public function getOrBuildClassMap(array $resources = []): array
    {
        if ($this->map) {
            return $this->map;
        }

        // on ne conserve que les ressources FHIR
        $resources = array_filter($resources, static function ($class) {
            return is_subclass_of($class, CFHIRResource::class);
        });

        $has_resources = count($resources) > 0;
        $fingerprint   = sha1(serialize($resources));

        $cache_key = $has_resources ? self::CACHE_KEY . ".$fingerprint" : self::CACHE_KEY;
        $cache_ttl = $has_resources ? self::CACHE_TTL : null;

        $class_map = $this->cache->get($cache_key);
        if (!$class_map) {
            $class_map = (new FhirClassMapBuilder())->build($resources);
        }

        $this->map = $class_map;

        $this->profile   = new ProfileMap($this, $this->default_fhir_version);
        $this->resource  = new ResourceMap($this, $this->default_fhir_version);
        $this->delegated = new DelegatedMap($this, $this->default_fhir_version);
        $this->version   = new VersionMap($this, $this->default_fhir_version);

        $this->cache->set($cache_key, $class_map, $cache_ttl);

        return $class_map;
    }

    public function clearCache(): bool
    {
        $this->map = null;

        return $this->cache->delete(self::CACHE_KEY);
    }
}
