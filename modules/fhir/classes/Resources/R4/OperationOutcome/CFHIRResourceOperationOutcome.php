<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\OperationOutcome;

use Ox\Interop\Fhir\Contracts\Resources\ResourceOperationOutcomeInterface;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\OperationOutcome\CFHIRDataTypeOperationOutcomeIssue;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;

/**
 * FIHR patient resource
 */
class CFHIRResourceOperationOutcome extends CFHIRDomainResource implements ResourceOperationOutcomeInterface
{
    // constants
    /** @var string */
    public const RESOURCE_TYPE = "OperationOutcome";

    // attributes
    /** @var CFHIRDataTypeOperationOutcomeIssue[] */
    public $issue = [];

    public function addIssue(CFHIRDataTypeOperationOutcomeIssue $issue): void
    {
        if (!$this->issue) {
            $this->issue = [];
        }

        $this->issue[] = $issue;
    }
 }
