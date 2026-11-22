<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\SearchParameters;

/**
 * Paramètre de recherche FHIR de type « reference ».
 */
class SearchParameterReference extends AbstractSearchParameter
{
    /**
     * {@inheritDoc}
     */
    public function prepareOperator(?string $modifier, $value, ?string $prefixValue): string
    {
        // À implémenter : construction de l'opérateur pour une référence.
        return '';
    }
}
