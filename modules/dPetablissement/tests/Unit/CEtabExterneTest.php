<?php
/**
 * @package Hospilibre\Etablissement
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Etablissement\Tests\Unit;
use Hospilibre\Etablissement\CEtabExterne;
use Ox\Tests\UnitTestHospilibre;

class CEtabExterneTest extends UnitTestHospilibre {
  /**
   * Test of instance of a class
   */
  public function test__construct() {
    $etab_externe = new CEtabExterne();
    $this->assertInstanceOf(CEtabExterne::class, $etab_externe);
  }

  /**
   * Test of _view variable
   */
  public function testUpdateFormFields() {
    $etab_externe = new CEtabExterne();
    $etab_externe->nom = "Mon établissement de test";

    $etab_externe->updateFormFields();
    $this->assertEquals($etab_externe->nom, $etab_externe->_view);
  }
}
