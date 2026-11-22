<?php
/**
 * @package Hospilibre\
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Admin\Rgpd;

use Hospilibre\Admin\CTokenValidator;

/**
 * Description
 */
class CRGPDTokenValidator extends CTokenValidator {
  protected $patterns = array(
    'get' => array(
      'consent' => array('/(0|1)/', null),
    ),
  );

  protected $authorized_methods = array(
    'get',
  );
}