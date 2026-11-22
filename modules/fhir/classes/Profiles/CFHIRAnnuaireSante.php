<?php

/**
 * Profil FHIR dédié à l'Annuaire Santé (ANS / RASS).
 *
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Profiles;

class CFHIRAnnuaireSante extends CFHIR
{
    /** URL de base des StructureDefinition exposées par l'annuaire. */
    public const BASE_PROFILE = 'https://apifhir.annuaire.sante.fr/ws-sync/exposed/structuredefinition/';

    /** Source déclarée dans les métadonnées des ressources. */
    public const RESOURCE_META_SOURCE = "https://annuaire.sante.fr";

    /**
     * Initialise le profil FHIR pour l'Annuaire Santé.
     */
    public function __construct()
    {
        parent::__construct();

        $this->type = "AnnuaireSante";
        $this->name = "FHIR";
    }
}
