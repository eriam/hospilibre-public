<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\SearchParameters;

use Ox\Core\Api\Request\RequestFilter;

/**
 * Paramètre de recherche FHIR de type "string".
 *
 * Traduit les modificateurs FHIR (contains / exact) vers les opérateurs
 * de filtrage internes ; à défaut de modificateur, la recherche démarre
 * par un « commence par ».
 */
class SearchParameterString extends AbstractSearchParameter
{
    /** @var string[] Modificateurs reconnus pour ce type de paramètre */
    protected const ACCEPTED_MODIFIERS = [
        self::MODIFIER_CONTAINS,
        self::MODIFIER_EXACT,
    ];

    /**
     * @inheritDoc
     */
    public function prepareOperator(?string $modifier, $value, ?string $prefixValue): string
    {
        // Aucun modificateur (null, chaîne vide, "0") : « commence par » par défaut
        if (!$modifier) {
            return RequestFilter::FILTER_BEGIN_WITH;
        }

        switch ($modifier) {
            case self::MODIFIER_CONTAINS:
                return RequestFilter::FILTER_CONTAINS;

            case self::MODIFIER_EXACT:
                return RequestFilter::FILTER_STRICT_EQUAL;

            default:
                return parent::prepareOperator($modifier, $value, $prefixValue);
        }
    }
}
