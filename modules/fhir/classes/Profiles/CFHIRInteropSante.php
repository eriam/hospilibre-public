<?php

/**
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Profiles;

/**
 * Profil FHIR spécialisé pour le cadre d'interopérabilité français Interop'Santé.
 */
class CFHIRInteropSante extends CFHIR
{
    /**
     * URL de base des StructureDefinition publiées par Interop'Santé.
     *
     * @var string
     */
    public const BASE_PROFILE = 'http://interopsante.org/fhir/StructureDefinition/';

    public function __construct()
    {
        // On délègue d'abord l'initialisation générique au profil FHIR parent.
        parent::__construct();

        // Puis on positionne l'identité propre à ce profil.
        $this->type = 'InteropSante';
        $this->name = 'FHIR';
    }
}
