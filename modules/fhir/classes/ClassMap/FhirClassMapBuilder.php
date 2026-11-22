<?php

/**
 * @package Mediboard\Fhir\Resources
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ClassMap;

use Exception;
use Ox\Core\CClassMap;
use Ox\Core\CMbArray;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectHandleInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectSearcherInterface;
use Ox\Interop\Fhir\Profiles\CFHIR;
use Ox\Interop\Fhir\Resources\CFHIRResource;

class FhirClassMapBuilder
{
    /** @var array */
    private $delegated_objects;

    /**
     * Construit la carte des classes FHIR pour les ressources demandées.
     *
     * @return array
     * @throws Exception
     */
    public function build(array $only_resources = []): array
    {
        /** @var CFHIRResource[] $resources */
        if ($only_resources) {
            $resources = [];
            foreach ($only_resources as $className) {
                $resources[] = new $className();
            }
        } else {
            $resources = CClassMap::getInstance()->getClassChildren(CFHIRResource::class, true, true);
        }

        // On mémorise les objets délégués avant d'assembler la carte.
        $this->delegated_objects = $this->findDelegated();

        return $this->buildResources($resources);
    }

    /**
     * @param CFHIRResource[] $resources
     *
     * @return array
     */
    private function buildResources(array $resources): array
    {
        $map = [];

        foreach ($resources as $resource) {
            foreach ($resource->getAvailableFHIRVersions() as $version) {
                $this->buildResource($map, $resource, $version);
                $this->buildDelegated($map, $resource, $version);
            }
        }

        return $map;
    }

    /**
     * @param array         $map
     * @param CFHIRResource $resource
     * @param string        $fhir_version
     *
     * @return array
     */
    private function buildResource(array &$map, CFHIRResource $resource, string $fhir_version): array
    {
        /** @var CFHIR|string $profile_class */
        $profile_class  = $resource::PROFILE_CLASS;
        $resource_class = get_class($resource);
        $canonical      = $resource->getProfile();
        $resource_type  = $resource::RESOURCE_TYPE;

        // versions fhir_version
        if (!in_array($fhir_version, CMbArray::getRecursive($map, 'versions fhir_version', []))) {
            $map['versions']['fhir_version'][] = $fhir_version;
        }

        // versions canonical <canonical> fhir_version
        if (!in_array($fhir_version, CMbArray::getRecursive($map, "versions canonical $canonical fhir_version", []))) {
            $map['versions']['canonical'][$canonical]['fhir_version'][] = $fhir_version;
        }

        // profiles <fhir_version> canonical <canonical>
        $map['profiles'][$fhir_version]['canonical'][$canonical] = $profile_class;

        // profiles <fhir_version> profile_class <profile_class>
        if (!in_array($canonical, CMbArray::getRecursive($map, "profiles $fhir_version profile $profile_class", []))) {
            $map['profiles'][$fhir_version]['profile_class'][$profile_class][] = $canonical;
        }

        // profiles <fhir_version> fhir_resource <fhir_resource>
        $map['profiles'][$fhir_version]['fhir_resource'][$resource_type][] = $profile_class;

        // profiles <fhir_version> profile <base_url> => profile_class
        $map['profiles'][$fhir_version]['profile'][$profile_class::BASE_PROFILE] = $profile_class;

        // resource <fhir_version> canonical <canonical>
        $map['resource'][$fhir_version]['canonical'][$canonical] = $resource_class;

        // resource <fhir_version> fhir_resource resource_type
        if ($resource->isFHIRResource()) {
            $map['resource'][$fhir_version]['fhir_resource'][$resource_type] = $resource_class;
        }

        // resource <fhir_version> type <resource_type>
        $map['resource'][$fhir_version]['type'][$resource_type][] = $resource_class;

        return $map;
    }

    /**
     * @param array         $map
     * @param CFHIRResource $resource
     * @param string        $fhir_version
     *
     * @return array
     */
    private function buildDelegated(array &$map, CFHIRResource $resource, string $fhir_version): array
    {
        // Une entrée par type de délégation : mapper, searcher, handle.
        foreach (['mapper', 'searcher', 'handle'] as $delegation_key) {
            $map['delegated'][$fhir_version][$delegation_key] =
                $this->buildSpecificDelegated($delegation_key, $map, $resource, $fhir_version);
        }

        return $map;
    }

    /**
     * @param string        $key
     * @param array         $map
     * @param CFHIRResource $resource
     * @param string        $fhir_version
     *
     * @return array
     */
    private function buildSpecificDelegated(string $key, array $map, CFHIRResource $resource, string $fhir_version): array
    {
        $profile_class = $resource::PROFILE_CLASS;
        $canonical     = $resource->getProfile();
        $resource_type = $resource::RESOURCE_TYPE;

        $delegated_objects = CMbArray::getRecursive($map, "delegated $fhir_version $key", []);

        // resource <fhir_version> delegated mapper type <resource_type>
        $types_delegated = CMbArray::getRecursive($this->delegated_objects, "$key type $resource_type", []);
        if ($types_delegated) {
            $delegated_objects['type'][$resource_type] = $types_delegated;
        }

        // resource <fhir_version> delegated mapper canonical
        $profile_delegated = CMbArray::getRecursive($this->delegated_objects, "$key profiled $resource_type $profile_class");
        if ($profile_delegated) {
            $delegated_objects['canonical'][$canonical] = $profile_delegated;
        }

        // resource <fhir_version delegated mapper all
        if (!CMbArray::get($delegated_objects, "all", [])) {
            $all_delegated = CMbArray::getRecursive($this->delegated_objects, "$key all");
            if ($all_delegated) {
                $delegated_objects['all'] = $all_delegated;
            }
        }

        return $delegated_objects;
    }

    /**
     * @return array
     * @throws Exception
     */
    private function findDelegated(): array
    {
        $classmap = CClassMap::getInstance();

        return [
            'mapper'   => $this->findSpecificDelegated(DelegatedObjectMapperInterface::class, $classmap),
            'searcher' => $this->findSpecificDelegated(DelegatedObjectSearcherInterface::class, $classmap),
            'handle'   => $this->findSpecificDelegated(DelegatedObjectHandleInterface::class, $classmap),
        ];
    }

    /**
     * @param string    $interface
     * @param CClassMap $classMap
     *
     * @return array
     * @throws Exception
     */
    private function findSpecificDelegated(string $interface, CClassMap $classMap): array
    {
        $result = [];

        foreach ($classMap->getClassChildren($interface) as $class) {
            if (!$classMap->getClassMap($class)) {
                continue;
            }

            /** @var DelegatedObjectInterface $object_handle */
            $object_handle = new $class();

            if (is_subclass_of($class, DelegatedObjectHandleInterface::class)) {
                $resource_type = (new $class())->getTargetResource()::RESOURCE_TYPE;
            } else {
                $resource_type = $class::RESOURCE_TYPE;
            }

            foreach ($object_handle->getProfiles() ?: ['all'] as $profile_class) {
                $result['profiled'][$resource_type][$profile_class][] = $class;
            }

            // all
            if (!in_array($class, $result['all'] ?? [])) {
                $result['all'][] = $class;
            }

            // resource type
            if (!in_array($class, $result['type'] ?? [])) {
                $result['type'][$resource_type][] = $class;
            }
        }

        return $result;
    }
}
