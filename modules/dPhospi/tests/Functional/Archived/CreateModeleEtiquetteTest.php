<?php
/**
 * @package Hospilibre\Hospi\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Tests\SeleniumTestHospilibre;

/**
 * CreateModeleTest
 *
 * @description Test creation of a document's modele
 * @screen      ModelesPage
 */
class CreateModeleEtiquetteTest extends SeleniumTestHospilibre {
  public $chir_name = "CHIR Test";

  /**
   * Création d'un modÎ¸le d'étiquette NomEtiquette pour l'utilisateur CHIR Test avec comme classe Séjour et come texte Texte
   */
  public function testCreateModeleOk() {
    $page = new ModeleEtiquettePage($this);
    $page->createModeleEtiquette();

    $this->assertEquals("ModÎ¸le d'étiquette créé", $page->getSystemMessage());
  }

}