<?php

/**
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Profiles;

/**
 * Profil FHIR de l'ANS (Agence du Numérique en Santé).
 *
 * Reprend le comportement de CFHIR en fixant le nom et le type propres
 * au cadre d'interopérabilité CI-SIS.
 */
class CFHIRANS extends CFHIR
{
    /** URL de base des StructureDefinition publiées par l'ANS (CI-SIS). */
    public const BASE_PROFILE = 'http://esante.gouv.fr/ci-sis/fhir/StructureDefinition/';

    public function __construct()
    {
        parent::__construct();

        // Identifie ce profil comme du FHIR au format ANS.
        $this->type = "ANS";
        $this->name = "FHIR";
    }
}
