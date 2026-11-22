<?php

/**
 * @package Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle;

use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\OperationOutcome\CFHIRDataTypeOperationOutcomeIssue;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeResource;
use Ox\Interop\Fhir\Resources\R4\OperationOutcome\CFHIRResourceOperationOutcome;

/**
 * Élément « response » d'une entrée de Bundle FHIR.
 */
class CFHIRDataTypeBundleResponse extends CFHIRDataTypeBackboneElement
{
    /** @var string */
    public const NAME = 'Bundle.entry.response';

    /** @var CFHIRDataTypeString */
    public $status;

    /** @var CFHIRDataTypeUri */
    public $location;

    /** @var CFHIRDataTypeString */
    public $etag;

    /** @var CFHIRDataTypeInstant */
    public $lastModified;

    /** @var CFHIRDataTypeResource [CFHIRResourceOperationOutcome] */
    public $outcome;

    /**
     * Affecte l'élément status déjà typé.
     *
     * @param CFHIRDataTypeString $status
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setStatusElement(CFHIRDataTypeString $status): CFHIRDataTypeBundleResponse
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Affecte le status à partir d'une chaîne brute.
     *
     * @param string $status
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setStatus(string $status): CFHIRDataTypeBundleResponse
    {
        return $this->setStatusElement(new CFHIRDataTypeString($status));
    }

    /**
     * Affecte l'élément location déjà typé.
     *
     * @param CFHIRDataTypeUri $location
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setLocationElement(CFHIRDataTypeUri $location): CFHIRDataTypeBundleResponse
    {
        $this->location = $location;

        return $this;
    }

    /**
     * Affecte la location à partir d'une chaîne brute.
     *
     * @param string $location
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setLocation(string $location): CFHIRDataTypeBundleResponse
    {
        return $this->setLocationElement(new CFHIRDataTypeUri($location));
    }

    /**
     * Affecte l'élément etag déjà typé.
     *
     * @param CFHIRDataTypeString $etag
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setEtagElement(CFHIRDataTypeString $etag): CFHIRDataTypeBundleResponse
    {
        $this->etag = $etag;

        return $this;
    }

    /**
     * Affecte l'etag (encapsule à nouveau la valeur reçue).
     *
     * @param CFHIRDataTypeString $etag
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setEtag(CFHIRDataTypeString $etag): CFHIRDataTypeBundleResponse
    {
        return $this->setEtagElement(new CFHIRDataTypeString($etag));
    }

    /**
     * Affecte l'élément lastModified déjà typé.
     *
     * @param CFHIRDataTypeInstant $lastModified
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setLastModifiedElement(CFHIRDataTypeInstant $lastModified): CFHIRDataTypeBundleResponse
    {
        $this->lastModified = $lastModified;

        return $this;
    }

    /**
     * Affecte lastModified à partir d'une chaîne brute.
     *
     * @param string $lastModified
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setLastModified(string $lastModified): CFHIRDataTypeBundleResponse
    {
        return $this->setLastModifiedElement(new CFHIRDataTypeInstant($lastModified));
    }

    /**
     * Affecte l'élément outcome déjà typé.
     *
     * @param CFHIRDataTypeResource $outcome
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setOutcomeElement(CFHIRDataTypeResource $outcome): CFHIRDataTypeBundleResponse
    {
        $this->outcome = $outcome;

        return $this;
    }

    /**
     * Affecte l'outcome à partir d'une ressource OperationOutcome.
     *
     * @param CFHIRResourceOperationOutcome $outcome
     *
     * @return CFHIRDataTypeBundleResponse
     */
    public function setOutcome(CFHIRResourceOperationOutcome $outcome): CFHIRDataTypeBundleResponse
    {
        return $this->setOutcomeElement(new CFHIRDataTypeResource($outcome));
    }

    /**
     * Ajoute un issue à l'OperationOutcome porté par la réponse,
     * en créant l'outcome au vol s'il n'existe pas encore.
     *
     * @param CFHIRDataTypeOperationOutcomeIssue $issue
     *
     * @return $this
     */
    public function addIssue(CFHIRDataTypeOperationOutcomeIssue $issue)
    {
        if (!$this->outcome) {
            $this->setOutcome(new CFHIRResourceOperationOutcome());
        }

        /** @var CFHIRResourceOperationOutcome $resource */
        $resource = $this->outcome->_value;
        $resource->addIssue($issue);

        return $this;
    }
}
