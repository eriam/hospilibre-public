<?php

/**
 * @package Hospilibre\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Request\Api;

use Exception;
use Ox\Core\Api\Request\Content\RequestContent;
use Ox\Core\Kernel\Routing\RequestHelperTrait;
use Ox\Interop\Fhir\Actors\CSenderFHIR;
use Ox\Interop\Fhir\Controllers\CFHIRController;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterList;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Façade regroupant les différentes facettes d'une requête FHIR entrante
 * (format, ressource ciblée, paramètres de recherche, corps et interaction).
 */
class CRequestFHIR
{
    use RequestHelperTrait;

    /** @var CRequestFormats */
    protected $request_format;

    /** @var CRequestResource */
    protected $request_resource;

    /** @var CRequestSearch */
    protected $request_search;

    /** @var RequestContent */
    protected $request_content;

    /** @var CRequestInteraction */
    protected $request_interaction;

    /**
     * CRequestFHIR constructor.
     *
     * @param RequestStack $request
     *
     * @throws Exception
     */
    public function __construct(RequestStack $request)
    {
        // On travaille sur la requête HTTP courante extraite de la pile.
        $httpRequest = $request->getCurrentRequest();

        $this->request          = $httpRequest;
        $this->request_format   = new CRequestFormats($httpRequest);
        $this->request_resource = new CRequestResource($httpRequest);
        $this->request_content  = new RequestContent($httpRequest);

        // La recherche a besoin de connaître la ressource ciblée.
        $this->request_search      = new CRequestSearch($httpRequest, $this->getResource());
        $this->request_interaction = new CRequestInteraction($httpRequest);
    }

    /**
     * @return CRequestResource|null
     * @throws Exception
     */
    public function getResource(): ?CFHIRResource
    {
        return $this->request_resource->getResource();
    }

    /**
     * @return string
     */
    public function getFormat(): string
    {
        return $this->request_format->getFormat();
    }

    public function getSender(): ?CSenderFHIR
    {
        return $this->request_resource->getSender();
    }

    /**
     * @return string
     */
    public function getResourceVersion(): ?string
    {
        return $this->request_resource->getVersionId();
    }

    /**
     * @return string|null
     */
    public function getResourceId(): ?string
    {
        return $this->request_resource->getResourceId();
    }

    /**
     * @return CFHIRInteraction|null
     * @throws Exception
     */
    public function getInteraction(): ?CFHIRInteraction
    {
        // Applique le format négocié puis rattache l'échange FHIR courant.
        $currentInteraction = $this->request_interaction->getInteraction()
            ->setFormat($this->getFormat());

        $currentInteraction->_ref_fhir_exchange = CFHIRController::$exchange_fhir;

        return $currentInteraction;
    }

    /**
     * @return SearchParameterList
     */
    public function getSearchParameters(): SearchParameterList
    {
        return $this->request_search->getSearchParameters();
    }

    /**
     * @param bool   $json_decode
     * @param string $encode_to
     * @param string $encode_from
     *
     * @return false|mixed|resource|string|null
     * @throws Exception
     */
    public function getContent(bool $json_decode = false, string $encode_to = null, string $encode_from = 'UTF-8')
    {
        return $this->request_content->getContent($json_decode, $encode_to, $encode_from);
    }
}
