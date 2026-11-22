<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\SearchParameters;

use Ox\Core\Api\Request\RequestFilter;

/**
 * Paramètre de recherche FHIR de type booléen.
 */
class SearchParameterBool extends AbstractSearchParameter
{
    /** @var string[] Aucun modificateur n'est accepté pour ce paramètre. */
    protected const ACCEPTED_MODIFIERS = [];

    /**
     * @inheritDoc
     */
    public function prepareOperator(?string $modifier, $value, ?string $prefixValue): string
    {
        // Un booléen ne se compare que par égalité stricte.
        return RequestFilter::FILTER_EQUAL;
    }

    public function extractValue($value)
    {
        // Les représentations « fausses » explicites sont ramenées à false ;
        // tout le reste passe par la conversion booléenne standard.
        $isFalsy = ($value === "false") || ($value === 0);

        return $isFalsy ? false : boolval($value);
    }
}
