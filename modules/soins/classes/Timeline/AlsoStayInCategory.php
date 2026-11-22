<?php
/**
 * @package Hospilibre\Soins
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Soins\Timeline;

use Hospilibre\PlanningOp\CSejour;

/**
 * Adds the stay base in categories
 */
trait AlsoStayInCategory {
  /** @var CSejour */
  private $stay;

  /**
   * @param CSejour $stay
   */
  public function setStay(CSejour $stay): void {
    $this->stay = $stay;
  }
}