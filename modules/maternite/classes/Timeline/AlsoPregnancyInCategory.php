<?php
/**
 * @package Hospilibre\Maternite
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Maternite\Timeline;

use Hospilibre\Maternite\CGrossesse;

/**
 * Adds the pregnancy base to categories
 */
trait AlsoPregnancyInCategory {
  /** @var CGrossesse */
  private $pregnancy;

  /**
   * @param CGrossesse $pregnancy
   */
  public function setPregnancy(CGrossesse $pregnancy): void {
    $this->pregnancy = $pregnancy;
  }
}