<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle;

use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;

/**
 * Element "link" d'un Bundle FHIR : associe une relation à une URL.
 */
class CFHIRDataTypeBundleLink extends CFHIRDataTypeBackboneElement
{
    /** @var string */
    public const NAME = 'Bundle.link';

    /** @var CFHIRDataTypeString */
    public $relation;

    /** @var CFHIRDataTypeUri */
    public $url;

    /**
     * Renseigne la relation à partir d'une chaîne brute.
     *
     * @param string $relation
     *
     * @return $this
     */
    public function setRelation(string $relation): self
    {
        return $this->setUrlRelation(new CFHIRDataTypeString($relation));
    }

    /**
     * Renseigne la relation à partir d'un type FHIR string.
     *
     * @param CFHIRDataTypeString $relation
     *
     * @return $this
     */
    public function setUrlRelation(CFHIRDataTypeString $relation): self
    {
        $this->relation = $relation;

        return $this;
    }

    /**
     * Renseigne l'URL à partir d'une chaîne brute.
     *
     * @param string $url
     *
     * @return $this
     */
    public function setUrl(string $url): self
    {
        return $this->setUrlElement(new CFHIRDataTypeUri($url));
    }

    /**
     * Renseigne l'URL à partir d'un type FHIR uri.
     *
     * @param CFHIRDataTypeUri $url
     *
     * @return $this
     */
    public function setUrlElement(CFHIRDataTypeUri $url): self
    {
        $this->url = $url;

        return $this;
    }
}
