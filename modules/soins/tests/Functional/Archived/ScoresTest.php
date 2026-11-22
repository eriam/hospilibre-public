<?php 
/**
 * @package Hospilibre\Soins
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Tests\SeleniumTestHospilibre;

/**
 * Class ScoresTest
 *
 * @description Test the creation of the Chung and IGS scores
 * @screen      SejourPage
 */
class ScoresTest extends SeleniumTestHospilibre {
  /** @var SejourPage $page */
  public $page = null;

  /**
   * @inheritdoc
   */
//  public function setUp() {
//    parent::setUp();
//    $this->page = new SejourPage($this);
//  }

  /**
   * Tests the value of the Chung score based on the constants preop and perop
   */
  public function testChungScore() {
    $this->importObject("soins/tests/Functional/data/score_chung.xml");
    $this->assertEquals(6, $this->page->createChungScore());
    $this->assertContains('Score de Chung créé', $this->page->getSystemMessage());
  }

  /**
   * Test the value of the IGS score based on the constants
   */
  public function testIgsScore() {
    $this->importObject("soins/tests/Functional/data/score_igs.xml");
    $this->assertEquals(54, $this->page->createIgsScore());
    $this->assertContains('Score IGS créé', $this->page->getSystemMessage());
  }

}
