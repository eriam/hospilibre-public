<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\SearchParameters;

use Ox\Core\Api\Exceptions\ApiRequestException;
use Ox\Core\Api\Request\Filter;
use Ox\Core\Api\Request\RequestFilter;
use Ox\Core\CSQLDataSource;
use Symfony\Component\HttpFoundation\Request;

/**
 * Représente un paramètre de recherche FHIR concret : type, valeur, éventuels
 * modificateur et préfixe, et opérateur SQL déduit.
 */
class SearchParameter implements ISearchParameter
{
    /** @var AbstractSearchParameter */
    private $type;

    /** @var string */
    private $modifier;

    /** @var mixed */
    private $value;

    /** @var string|null */
    private $prefixValue;

    /** @var string */
    private $operator;

    /**
     * @param AbstractSearchParameter $type
     * @param mixed                   $value
     * @param string|null             $modifier
     * @param string|null             $prefix
     */
    public function __construct(AbstractSearchParameter $type, $value, ?string $modifier = null, ?string $prefix = null)
    {
        $this->type = $type;

        // On ne retient modificateur et préfixe que si le type les accepte.
        if ($modifier && $this->type->isSupportedModifier($modifier)) {
            $this->modifier = $modifier;
        }

        if ($prefix && $this->type->isSupportedPrefix($prefix)) {
            $this->prefixValue = $prefix;
        }

        $this->prepareValue($value);
        $this->prepareOperator();
    }

    /**
     * @return string
     */
    public function getParameterName(): string
    {
        return $this->type->getParameterName();
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Construit la clause SQL correspondant au filtre de ce paramètre.
     *
     * @param string         $field
     * @param CSQLDataSource $ds
     * @param mixed|null     $value
     * @param array          $sanitize
     *
     * @return string
     * @throws ApiRequestException
     */
    public function getSql(string $field, CSQLDataSource $ds, $value = null, array $sanitize = []): string
    {
        $filter        = $this->getFilter($field, $value);
        $requestFilter = new RequestFilter(new Request());

        return $requestFilter->getSqlFilter($filter, $ds, $sanitize);
    }

    /**
     * @param string     $field
     * @param null|mixed $value
     *
     * @return Filter
     */
    public function getFilter(string $field, $value = null): Filter
    {
        $filter = $this->prepareFilter($field);

        // Une valeur explicite prend le pas sur celle du paramètre.
        if ($value) {
            return new Filter($filter->getKey(), $filter->getOperator(), $value);
        }

        return $filter;
    }

    public function toQuery(): string
    {
        $query = $this->getParameterName();

        if ($this->modifier) {
            $query .= ':' . $this->modifier;
        }

        $query .= '=';

        if ($this->prefixValue) {
            $query .= $this->prefixValue;
        }

        return $query . urlencode($this->value);
    }

    /**
     * @param string $field
     *
     * @return Filter|null
     */
    protected function prepareFilter(string $field): ?Filter
    {
        return new Filter($field, $this->operator, $this->value);
    }

    /**
     * Extrait, si besoin, le préfixe puis la valeur nette portée par l'entrée.
     *
     * @param mixed $value
     */
    protected function prepareValue($value): void
    {
        if (!$this->prefixValue) {
            $extractedPrefix = $this->type->extractPrefixValue($value);

            if ($extractedPrefix && $this->type->isSupportedPrefix($extractedPrefix)) {
                $this->prefixValue = $extractedPrefix;
            }
        }

        $this->value = $this->type->extractValue($value);
    }

    /**
     * @param string $operator
     */
    public function setOperator(string $operator): void
    {
        $this->operator = $operator;
    }

    /**
     * @return string
     */
    protected function prepareOperator(): string
    {
        $this->operator = $this->type->prepareOperator($this->modifier, $this->value, $this->prefixValue);

        return $this->operator;
    }
}
