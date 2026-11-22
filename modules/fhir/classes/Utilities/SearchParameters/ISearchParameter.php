<?php
/**
 * @package Hospilibre\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réimplémentation GPL (clean-room, reconstruite depuis l'implémenteur
 * SearchParameter et ses appelants, sans copier la source OXOL).
 */

namespace Ox\Interop\Fhir\Utilities\SearchParameters;

/**
 * Contrat d'un paramètre de recherche FHIR : il expose son nom de paramètre.
 */
interface ISearchParameter
{
    /**
     * @return string Le nom du paramètre de recherche FHIR.
     */
    public function getParameterName(): string;
}
