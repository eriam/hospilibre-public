<?php
/**
 * @package Hospilibre\Cabinet\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Cabinet\Tests\Functional\Pages;

use Ox\Tests\HomePage;

/**
 * Banque page representation
 */
class BanquesPage extends HomePage {

  protected $module_name = "cabinet";
  protected $tab_name = "showBanks";

  function createBanque($banque_name = "NomBanque") {
    $driver = $this->driver;

    $form = "editFrm";

    // Click on the create button
    $driver->byCss(".new")->click();

    // Fill the naom
    $driver->byId($form . "_nom")->value($banque_name);

    // Click on the create button
    $driver->byClassName("modify")->click();
  }
}
