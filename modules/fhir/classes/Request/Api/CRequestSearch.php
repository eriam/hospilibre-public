<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Request\Api;

use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameter;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterList;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

class CRequestSearch
{
    /** @var Request */
    private $request;

    /** @var CFHIRResource */
    private $resource;

    /** @var SearchParameterList */
    private $parameters;

    /**
     * Prepare la recherche a partir de la requete HTTP et, le cas echeant,
     * applique les parametres detectes sur la ressource FHIR ciblee.
     *
     * @param Request            $request
     * @param CFHIRResource|null $resource
     */
    public function __construct(Request $request, ?CFHIRResource $resource)
    {
        $this->request  = $request;
        $this->resource = $resource;

        $this->parameters = $this->findParameters();
        $this->applyParameters($this->parameters);
    }

    /**
     * Construit la liste des parametres de recherche a partir de la query string.
     *
     * @return SearchParameterList
     */
    private function findParameters(): ?SearchParameterList
    {
        $list = new SearchParameterList();

        // Sans ressource cible, aucun parametre ne peut etre resolu.
        if (!$this->resource) {
            return $list;
        }

        $query        = $this->request->query;
        $capabilities = $this->resource->getCapabilities();

        foreach ($query->all() as $name => $raw) {
            // Chaque cle peut porter une valeur unique ou multiple.
            $candidates = is_array($raw) ? $raw : [$raw];

            foreach ($candidates as $candidate) {
                $parameter = $this->createResourceParameter($capabilities, $name, $candidate);

                if ($parameter !== null) {
                    $list->add($parameter);
                }
            }
        }

        return $list;
    }

    /**
     * Cree un parametre de recherche si la ressource declare le supporter.
     *
     * @param CCapabilitiesResource $capabilities
     * @param string                $key
     * @param mixed                 $value
     *
     * @return SearchParameter
     */
    private function createResourceParameter(CCapabilitiesResource $capabilities, string $key, $value): ?SearchParameter
    {
        // Le nom peut etre suffixe d'un modificateur : "name:modifier".
        $segments       = explode(':', $key);
        $parameter_name = $segments[0];
        $modifier       = $segments[1] ?? null;

        $search_parameter_type = $capabilities->getSearchParameters($parameter_name);

        // Parametre inconnu pour cette ressource : on l'ignore.
        if (!$search_parameter_type) {
            return null;
        }

        return new SearchParameter($search_parameter_type, $value, $modifier);
    }

    /**
     * Reporte la query string brute puis les parametres normalises sur la ressource.
     *
     * @param SearchParameterList $parameters
     *
     * @return CFHIRResource|null
     */
    protected function applyParameters(SearchParameterList $parameters): ?CFHIRResource
    {
        if (!$this->resource) {
            return null;
        }

        $this->resource->setParameter($this->request->query);
        $this->resource->setParameter(new ParameterBag($parameters->all()));

        return $this->resource;
    }

    /**
     * @return SearchParameterList
     */
    public function getSearchParameters(): SearchParameterList
    {
        return $this->parameters;
    }
}
