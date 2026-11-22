<?php

/**
 * @package Mediboard\Fhir\Objects
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\Helper;

use Ox\Interop\Fhir\Resources\R4\Encounter\CFHIRResourceEncounter;

class SejourHelper
{
    /**
     * Recherche l'identifiant NDA dans une ressource Encounter.
     *
     * On parcourt les identifiants de la ressource et on retourne la valeur
     * du premier dont le systeme correspond a l'OID fourni.
     *
     * @param CFHIRResourceEncounter $encounter Ressource FHIR de type Encounter
     * @param string                 $nda_oid   OID du domaine d'identification NDA
     *
     * @return string|null Valeur du NDA, ou null si aucun identifiant ne correspond
     */
    public static function getNDA(CFHIRResourceEncounter $encounter, string $nda_oid): ?string
    {
        foreach ($encounter->identifier as $currentIdentifier) {
            if ($currentIdentifier->isSystemMatch($nda_oid)) {
                return $currentIdentifier->value->getValue();
            }
        }

        return null;
    }
}
