<?php

/**
 * @package Mediboard\Fhir\Resources
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ClassMap;

/**
 * Base des mappings d'objets FHIR : expose la portion de class map
 * correspondant à la clé du mapping concret et instancie les classes cibles.
 */
abstract class ObjectMap
{
    /** @var string Clé identifiant la section du class map exploitée par la sous-classe. */
    protected const KEY_MAP = '';

    /** @var array Portion du class map propre à ce mapping. */
    protected $map;

    /** @var string Version FHIR active. */
    protected $version;

    /** @var bool Lorsque vrai, on retourne le nom de classe plutôt qu'une instance. */
    protected $return_class = false;

    /** @var array Class map complet reconstruit à la demande. */
    protected $class_map;

    /**
     * @param array  $map
     * @param string $version
     */
    public function __construct(FHIRClassMap $class_map, string $version)
    {
        $resolved = $class_map->getOrBuildClassMap();

        $this->class_map = $resolved;
        $this->map       = $resolved[static::KEY_MAP];
        $this->version   = $version;
    }

    /**
     * @param string $version
     *
     * @return void
     */
    public function selectVersion(string $version): void
    {
        $this->version = $version;
    }

    /**
     * @param bool $return_class
     *
     * @return ObjectMap
     */
    public function setReturnClass(bool $return_class): ObjectMap
    {
        $this->return_class = $return_class;

        return $this;
    }

    /**
     * @param string|string[] $data
     *
     * @return array|mixed
     */
    protected function out($data)
    {
        // Rien à traiter : on renvoie tel quel (null, chaîne vide, tableau vide...).
        if (!$data) {
            return $data;
        }

        // Cas scalaire : soit le nom de classe, soit une instance fraîche.
        if (!is_array($data)) {
            return $this->return_class ? $data : new $data();
        }

        // Cas tableau : on écarte les entrées vides avant de matérialiser chaque élément.
        $cleaned = array_filter($data);

        return array_map(
            fn ($element) => $this->return_class ? $element : new $element(),
            $cleaned
        );
    }
}
