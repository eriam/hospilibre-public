<?php

/**
 * Profil FHIR pour le Personal Health Device (PHD).
 *
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Profiles;

/**
 * Décrit le profil PHD dérivé de FHIR.
 */
class CFHIRPHD extends CFHIR
{
    /**
     * URL de base des définitions de structure du profil PHD.
     *
     * @var string
     */
    public const BASE_PROFILE = 'http://hl7.org/fhir/uv/phd/StructureDefinition/';

    /**
     * Initialise le profil en positionnant son nom et son type.
     */
    public function __construct()
    {
        parent::__construct();

        $this->type = "PHD";
        $this->name = "FHIR";
    }
}
