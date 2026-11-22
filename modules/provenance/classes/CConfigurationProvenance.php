<?php
/**
 * @package Hospilibre\Provenance
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Provenance;

use Ox\Core\Handlers\HandlerParameterBag;
use Hospilibre\System\AbstractConfigurationRegister;
use Hospilibre\System\CConfiguration;

/**
 * Class CConfigurationProvenance
 *
 * @package Hospilibre\Provenance
 */
class CConfigurationProvenance extends AbstractConfigurationRegister {
  /**
   * @inheritDoc
   */
  public function register() {
    CConfiguration::register([]);
  }

  /**
   * Déclaration du handler des provenances patient
   *
   * @return array
   */
  public function getObjectHandlers(HandlerParameterBag $parameter_bag): void {
      $parameter_bag
          ->register(CProvenancePatientHandler::class, false);
  }
}
