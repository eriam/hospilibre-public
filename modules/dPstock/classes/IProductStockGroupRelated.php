<?php
/**
 * @package Hospilibre\Stock
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Stock;

/**
 * Product stock group related
 */
interface IProductStockGroupRelated {
  /**
   * Get related product stock group
   *
   * @return CProductStockGroup
   */
  function loadRelProductStockGroup();
}
