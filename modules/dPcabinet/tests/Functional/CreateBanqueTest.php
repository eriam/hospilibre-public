<?php
/**
 * @package Hospilibre\Cabinet\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Cabinet\Tests\Functional;

use Ox\Tests\SeleniumTestHospilibre;
use Hospilibre\Cabinet\Tests\Functional\Pages\BanquesPage;

/**
 * CreateBanqueTest
 *
 * @description Test the creation of a banque
 *
 * @screen BanquesPage
 */
class CreateBanqueTest extends SeleniumTestHospilibre {

  /**
   * Création d'une banque appelée NomBanque
   *
   * @config locale_warn 0
   */
  public function testCreateBanqueOk() {
    $page = new BanquesPage($this);
    $page->createBanque("NomBanque");

    $this->assertEquals("Banque créée", $page->getSystemMessage());
  }
}