<?php
/**
 * @package Hospilibre\fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Request;
use Ox\Interop\Fhir\Request\Api\CRequestFormats;
use Ox\Interop\Fhir\Serializers\CFHIRSerializer;

/**
 * FHIR JSON response
 */
class CFHIRRequestXML extends CFHIRRequest {
  /**
   * @inheritdoc
   */
  protected function _output() {
      // todo use parameter pretty fhir
      $pretty = true;
      header("Content-Type: ". CRequestFormats::CONTENT_TYPE_XML);
      $serializer = CFHIRSerializer::serialize($this->resource, 'xml', ['pretty' => $pretty]);

      return $serializer->getResourceSerialized();
  }
}
