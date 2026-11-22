<?php
/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Operations;

use Ox\Interop\Eai\CDomain;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\CFHIRDataTypeBackboneElement;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeBackbone;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Interactions\CFHIRInteraction;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\Parameters\CFHIRResourceParameters;
use Ox\Interop\Fhir\Response\CFHIRResponse;
use Hospilibre\Patients\CPatient;

/**
 * Description
 */
class CFHIROperationIhePix extends CFHIRInteraction
{
    /** @var string */
    public const NAME = 'ihe-pix';

    /**
     * Get the resource method name
     *
     * @return string
     */
    public function getResourceMethodName(): string
    {
        $interaction = preg_replace('/[^\w]/', '_', self::NAME);

        return "operation_$interaction";
    }

    public function getBasePath(): ?string
    {
        return parent::getBasePath() . '/$ihe-pix';
    }

    /**
     * @inheritdoc
     *
     * @param CPatient $result
     */
    public function handleResult(CFHIRResource $resource, $result): CFHIRResponse
    {
        $root = new CFHIRResourceParameters();

        if ($result) {
            $resource->mapFrom($result);

            $domains = CDomain::loadDomainIdentifiers($result);

            foreach ($domains as $_domain) {
                if (empty($result->_returned_oids) || in_array($_domain->OID, $result->_returned_oids)) {
                    $_bb       = new CFHIRDataTypeBackboneElement();
                    $_bb->name = "targetIdentifier";

                    $_bb->valueIdentifier = CFHIRDataTypeIdentifier::build(
                        [
                            "use"    => "official",
                            "system" => "urn:oid:$_domain->OID",
                            "value"  => $_domain->_identifier->id400,
                        ]
                    );

                    $root->parameter[] = $_bb;
                }
            }

            $patient                 = new CFHIRDataTypeBackboneElement();
            $patient->name           = "targetId";
            $patient->valueReference = CFHIRDataTypeReference::build(
                [
                    "reference" => $resource->getResourceType() . "/$result->patient_id",
                ]
            );

            $root->parameter[] = $patient;
        }

        $this->setResource($root);

        return new CFHIRResponse($this, $this->format);
    }
}
