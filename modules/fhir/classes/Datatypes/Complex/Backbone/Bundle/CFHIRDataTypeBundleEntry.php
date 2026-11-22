<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle;

use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeResource;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeSignature;

/**
 * Entrée d'un Bundle FHIR (Bundle.entry).
 */
class CFHIRDataTypeBundleEntry extends CFHIRDataTypeBackboneElement
{
    /** @var string */
    public const NAME = 'Bundle.entry';

    /** @var CFHIRDataTypeUri[] */
    public $link;

    /** @var CFHIRDataTypeUri */
    public $fullUrl;

    /** @var CFHIRDataTypeResource */
    public $resource;

    /** @var CFHIRDataTypeBundleSearch */
    public $search;

    /** @var CFHIRDataTypeBundleRequest */
    public $request;

    /** @var CFHIRDataTypeBundleResponse */
    public $response;

    /** @var CFHIRDataTypeSignature */
    public $signature;

    /**
     * Affecte l'ensemble des liens de l'entrée.
     *
     * @param array $links
     *
     * @return self
     */
    public function setLinkElement(array $links): self
    {
        $collected = [];

        foreach ($links as $candidate) {
            if (is_string($candidate)) {
                $candidate = new CFHIRDataTypeUri($candidate);
            }

            if (!$candidate instanceof CFHIRDataTypeUri) {
                continue;
            }

            $collected[] = $candidate;
        }

        $this->link = $collected;

        return $this;
    }

    /**
     * Ajoute un lien unique à l'entrée.
     *
     * @param string|CFHIRDataTypeUri $link
     *
     * @return self
     */
    public function addLinkElement($link): self
    {
        if (is_string($link)) {
            $link = new CFHIRDataTypeUri($link);
        }

        if ($link instanceof CFHIRDataTypeUri) {
            $this->link[] = $link;
        }

        return $this;
    }

    /**
     * Affecte l'URL complète à partir d'une chaîne.
     *
     * @param string $fullUrl
     *
     * @return self
     */
    public function setFullUrl(string $fullUrl): self
    {
        return $this->setFullUrlElement(new CFHIRDataTypeUri($fullUrl));
    }

    /**
     * Affecte l'URL complète à partir d'un élément URI.
     *
     * @param CFHIRDataTypeUri $fullUrl
     *
     * @return self
     */
    public function setFullUrlElement(CFHIRDataTypeUri $fullUrl): self
    {
        $this->fullUrl = $fullUrl;

        return $this;
    }

    /**
     * Affecte la ressource portée par l'entrée.
     *
     * @param CFHIRDataTypeResource $resource
     *
     * @return self
     */
    public function setResourceElement(CFHIRDataTypeResource $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    /**
     * Affecte le bloc search de l'entrée.
     *
     * @param CFHIRDataTypeBundleSearch $search
     *
     * @return self
     */
    public function setSearchElement(CFHIRDataTypeBundleSearch $search): self
    {
        $this->search = $search;

        return $this;
    }

    /**
     * Affecte le bloc request de l'entrée.
     *
     * @param CFHIRDataTypeBundleRequest $request
     *
     * @return self
     */
    public function setRequestElement(CFHIRDataTypeBundleRequest $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Affecte le bloc response de l'entrée.
     *
     * @param CFHIRDataTypeBundleResponse $response
     *
     * @return self
     */
    public function setResponseElement(CFHIRDataTypeBundleResponse $response): self
    {
        $this->response = $response;

        return $this;
    }
}
