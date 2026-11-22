<?php

/**
 * @package Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle;

use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;

/**
 * Element « request » d'une entree de Bundle FHIR.
 *
 * Expose des setters fluides : chaque appel renvoie l'instance courante afin
 * de pouvoir enchainer les affectations.
 */
class CFHIRDataTypeBundleRequest extends CFHIRDataTypeBackboneElement
{
    /** @var string */
    public const NAME = 'Bundle.entry.request';

    /** @var CFHIRDataTypeCode */
    public $method;

    /** @var CFHIRDataTypeUri */
    public $url;

    /** @var CFHIRDataTypeString */
    public $ifNoneMatch;

    /** @var CFHIRDataTypeInstant */
    public $ifModifiedSince;

    /** @var CFHIRDataTypeString */
    public $ifMatch;

    /** @var CFHIRDataTypeString */
    public $ifNoneExist;

    /**
     * Affecte directement l'element method.
     *
     * @param CFHIRDataTypeCode $method
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setMethodElement(CFHIRDataTypeCode $method): CFHIRDataTypeBundleRequest
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Affecte method depuis une valeur brute.
     *
     * @param string $method
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setMethod(string $method): CFHIRDataTypeBundleRequest
    {
        return $this->setMethodElement(new CFHIRDataTypeCode($method));
    }

    /**
     * Affecte directement l'element url.
     *
     * @param CFHIRDataTypeUri $ur
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setUrlElement(CFHIRDataTypeUri $ur): CFHIRDataTypeBundleRequest
    {
        $this->url = $ur;

        return $this;
    }

    /**
     * Affecte url depuis une valeur brute.
     *
     * @param string $url
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setUrl(string $url): CFHIRDataTypeBundleRequest
    {
        return $this->setUrlElement(new CFHIRDataTypeUri($url));
    }

    /**
     * Affecte directement l'element ifNoneMatch.
     *
     * @param CFHIRDataTypeString $ifNoneMatch
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setIfNoneMatchElement(CFHIRDataTypeString $ifNoneMatch): CFHIRDataTypeBundleRequest
    {
        $this->ifNoneMatch = $ifNoneMatch;

        return $this;
    }

    /**
     * Affecte ifNoneMatch depuis une valeur brute.
     *
     * @param string $ifNoneMatch
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setIfNoneMatch(string $ifNoneMatch): CFHIRDataTypeBundleRequest
    {
        return $this->setIfNoneMatchElement(new CFHIRDataTypeString($ifNoneMatch));
    }

    /**
     * Affecte directement l'element ifModifiedSince.
     *
     * @param CFHIRDataTypeInstant $ifModifiedSince
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setIfModifiedSinceElement(CFHIRDataTypeInstant $ifModifiedSince): CFHIRDataTypeBundleRequest
    {
        $this->ifModifiedSince = $ifModifiedSince;

        return $this;
    }

    /**
     * Affecte ifModifiedSince depuis une valeur brute.
     *
     * @param string $ifModifiedSince
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setIfModifiedSince(string $ifModifiedSince): CFHIRDataTypeBundleRequest
    {
        return $this->setIfModifiedSinceElement(new CFHIRDataTypeInstant($ifModifiedSince));
    }

    /**
     * Affecte directement l'element ifMatch.
     *
     * @param CFHIRDataTypeString $ifMatch
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setIfMatchElement(CFHIRDataTypeString $ifMatch): CFHIRDataTypeBundleRequest
    {
        $this->ifMatch = $ifMatch;

        return $this;
    }

    /**
     * Affecte ifMatch depuis une valeur brute.
     *
     * @param string $ifMatch
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setIfMatch(string $ifMatch): CFHIRDataTypeBundleRequest
    {
        return $this->setIfMatchElement(new CFHIRDataTypeString($ifMatch));
    }

    /**
     * Affecte directement l'element ifNoneExist.
     *
     * @param CFHIRDataTypeString $ifNoneExist
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setIfNoneExistElement(CFHIRDataTypeString $ifNoneExist): CFHIRDataTypeBundleRequest
    {
        $this->ifNoneExist = $ifNoneExist;

        return $this;
    }

    /**
     * Affecte ifNoneExist depuis une valeur brute.
     *
     * @param string $ifNoneExist
     *
     * @return CFHIRDataTypeBundleRequest
     */
    public function setIfNoneExist(string $ifNoneExist): CFHIRDataTypeBundleRequest
    {
        return $this->setIfNoneExistElement(new CFHIRDataTypeString($ifNoneExist));
    }
}
