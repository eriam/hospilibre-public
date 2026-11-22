/**
 * @package Hospilibre\Context
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

Contextual = {
  /**
   * Disabled buttons
   */
  disabledButton: function(classname){
    $$('button.' + classname).each(function(button) {
      button.disable();
    })
  }
};
