<?php
/**
 * @package Hospilibre\Astreintes\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */


namespace Hospilibre\Astreintes\Tests;

use Ox\Tests\SeleniumTestHospilibre;

/**
 * AstreintesTest
 *
 * @description Test creation of various object in "Astreintes" module
 * @screen      AstreintesPage
 */
class AstreintesTest extends SeleniumTestHospilibre {

  /** @var $page AstreintesPage */
  public $page = null;
  public $user_ponc = "MACGYVER Angus";
  public $user_reg  = "PRINCE Diana";
  public $number = 3;

  /**
   * @inheritdoc
   */
//  public function setUp() {
//    parent::setUp();
//
//    $this->page = new AstreintesPage($this);
//    $this->importObject("astreintes/tests/Functional/data/mediuser.xml");
//  }

  /**
   * Création de plage d'astreintes ponctuelle et réguliÎ¸re.
   * Vérification de leurs création et de l'affichage dans la liste du personnel d'astreinte
   *
   * @config appbar_shortcuts ButtonAstreintesShortcut 1
   */
  public function testPlageAstreintesOk() {
    $pageAstreintes = $this->page;

    // Astreinte ponctuelle
    $pageAstreintes->createAstreinte($this->user_ponc, "ponc", $this->number);
    $this->assertEquals("Plage créée", $pageAstreintes->getSystemMessage());

    // Astreinte réguliÎ¸re
    $pageAstreintes->createAstreinte($this->user_reg, "reg", $this->number);
    $this->assertEquals("Plage créée x ".$this->number, $pageAstreintes->getSystemMessage());

    // Vérification de la création des plages
    $this->assertEquals(1, $pageAstreintes->checkIfAstreinteCreated($this->user_ponc, "ponc", $this->number));
    $this->assertEquals(3, $pageAstreintes->checkIfAstreinteCreated($this->user_reg, "reg", $this->number));
    $this->assertTrue($pageAstreintes->checkListPersonnelAstreinte());
  }
}
