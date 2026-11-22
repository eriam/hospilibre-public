<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\SearchParameters;

use Ox\Core\CMbArray;

/**
 * Collection ordonnée de paramètres de recherche FHIR.
 */
class SearchParameterList
{
    /** @var ISearchParameter[] */
    private $parameters = [];

    /**
     * Construit la liste à partir d'un tableau ; les entrées qui ne sont
     * pas des ISearchParameter sont ignorées.
     *
     * @param ISearchParameter[]|null $parameters
     */
    public function __construct(array $parameters = [])
    {
        foreach ($parameters as $candidate) {
            if ($candidate instanceof ISearchParameter) {
                $this->add($candidate);
            }
        }
    }

    /**
     * Ajoute un paramètre sans contrôle de doublon.
     */
    public function add(ISearchParameter $search_parameter): bool
    {
        $this->parameters[] = $search_parameter;

        return true;
    }

    /**
     * Ajoute un paramètre seulement si son nom n'est pas déjà présent.
     */
    public function addUnique(ISearchParameter $search_parameter): bool
    {
        if ($this->has($search_parameter->getParameterName())) {
            return false;
        }

        return $this->add($search_parameter);
    }

    /**
     * Indique si un paramètre portant ce nom existe déjà.
     */
    public function has(string $parameter_name): bool
    {
        foreach ($this->parameters as $entry) {
            if ($entry->getParameterName() === $parameter_name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retourne le paramètre nommé situé à la position demandée, ou null.
     */
    public function get(string $parameter_name, int $position = 0): ?ISearchParameter
    {
        return CMbArray::get($this->all($parameter_name), $position);
    }

    /**
     * Retourne l'ensemble des paramètres, ou uniquement ceux portant le
     * nom fourni (réindexés).
     *
     * @return ISearchParameter[]
     */
    public function all(?string $parameter_name = null): array
    {
        if (!$parameter_name) {
            return $this->parameters;
        }

        $matching = array_filter(
            $this->parameters,
            static fn($entry) => $entry->getParameterName() === $parameter_name
        );

        return array_values($matching);
    }

    /**
     * Sérialise la liste en tableau associatif : pour un SearchParameter,
     * nom => valeur ; sinon index numérique => nom du paramètre.
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->parameters as $index => $entry) {
            if ($entry instanceof SearchParameter) {
                $result[$entry->getParameterName()] = $entry->getValue();
            } else {
                $result[$index] = $entry->getParameterName();
            }
        }

        return $result;
    }
}
