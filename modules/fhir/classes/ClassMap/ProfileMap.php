<?php

/**
 * @package Mediboard\Fhir\Resources
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\ClassMap;

use Ox\Core\CMbArray;
use Ox\Interop\Fhir\Profiles\CFHIR;

class ProfileMap extends ObjectMap
{
    /** @var string */
    protected const KEY_MAP = 'profiles';

    /**
     * Retourne la liste des profils, éventuellement filtrée par type de ressource.
     *
     * @return CFHIR[]
     */
    public function listProfiles(?string $resource_type = null): array
    {
        $fhir_version = $this->version;

        // Sans type de ressource, on énumère les clés de la table des classes de profil ;
        // sinon on cible directement les profils rattachés à la ressource demandée.
        if (!$resource_type) {
            $path      = "$fhir_version profile_class";
            $collected = array_keys(CMbArray::getRecursive($this->map, $path, []));
        } else {
            $path      = "$fhir_version fhir_resource $resource_type";
            $collected = CMbArray::getRecursive($this->map, $path, []);
        }

        return $this->out(array_unique($collected));
    }

    /**
     * Résout les canonicals associés à une classe de profil donnée.
     *
     * @param string      $profile_class
     * @param string|null $fhir_version
     *
     * @return string[]
     */
    public function getCanonicalsFromProfileClass(string $profile_class): array
    {
        $lookup = "{$this->version} profile_class $profile_class";

        return CMbArray::getRecursive($this->map, $lookup, []);
    }
}
