<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\SearchParameters;

use Ox\Core\Api\Request\RequestFilter;
use Ox\Core\CMbDT;

class SearchParameterDate extends AbstractSearchParameter
{
    /** @var string[] */
    public const ACCEPTED_PREFIXES = self::ALL_PREXFIX;

    /**
     * @inheritDoc
     */
    public function prepareOperator(?string $modifier, $value, ?string $prefixValue): string
    {
        // Table de correspondance préfixe FHIR -> opérateur de filtre interne.
        $mapping = [
            self::PREFIX_NOT_EQUAL       => RequestFilter::FILTER_NOT_EQUAL,
            self::PREFIX_GREATER_THAN    => RequestFilter::FILTER_GREATER,
            self::PREFIX_GREATER_OR_EQUAL => RequestFilter::FILTER_GREATER_OR_EQUAL,
            self::PREFIX_LESS_THAN       => RequestFilter::FILTER_LESS,
            self::PREFIX_LESS_OR_EQUAL   => RequestFilter::FILTER_LESS_OR_EQUAL,
        ];

        // Les préfixes sa/eb/ap/eq (ainsi qu'une valeur absente) retombent sur l'égalité.
        return $mapping[$prefixValue] ?? RequestFilter::FILTER_EQUAL;
    }

    /**
     * @param string $value
     *
     * @return string|null
     */
    public function extractPrefixValue(string $value): ?string
    {
        $candidate = substr($value, 0, 2);

        if (!in_array($candidate, self::ALL_PREXFIX)) {
            return null;
        }

        return $candidate;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    public function extractValue($value)
    {
        // On retire le préfixe éventuel avant de normaliser la date.
        if ($this->extractPrefixValue($value)) {
            $value = substr($value, 2);
        }

        return CMbDT::date($value);
    }
}
