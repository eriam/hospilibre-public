<?php
/**
 * @package Hospilibre\CompteRendu\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\CompteRendu\Tests\Unit;

use Hospilibre\Cabinet\CConsultation;
use Hospilibre\CompteRendu\CAideSaisie;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\CSejour;
use Ox\Tests\UnitTestHospilibre;

class CAideSaisieTest extends UnitTestHospilibre {

  public function test__construct() {
    $aide = new CAideSaisie();
    $this->assertInstanceOf(CAideSaisie::class, $aide);
  }

  public function testLoadRefFunction() {
    $aide = new CAideSaisie();
    $this->assertInstanceOf(CFunctions::class, $aide->loadRefFunction());
  }

  public function testLoadRefUser() {
    $aide = new CAideSaisie();
    $this->assertInstanceOf(CMediusers::class, $aide->loadRefUser());
  }

  public function testLoadRefGroup() {
    $aide = new CAideSaisie();
    $this->assertInstanceOf(CGroups::class, $aide->loadRefGroup());
  }

  /**
   * @return array
   */
  public function classesDependValues() {
    return array(
      "consultation" => [CConsultation::class],
      "sejour"       => [CSejour::class]
    );
  }

  /**
   * @dataProvider classesDependValues
   */
//  public function testLoadDependValues($classe) {
//    $this->markTestSkipped();
//    $aide = new CAideSaisie();
//    $aide->class = $classe;
//    $aide->loadDependValues();
//  }
}
