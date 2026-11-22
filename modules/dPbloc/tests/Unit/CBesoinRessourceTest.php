<?php
/**
 * @package Hospilibre\Bloc
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Bloc\Tests;

use Hospilibre\Bloc\CBesoinRessource;
use Hospilibre\Bloc\CTypeRessource;
use Hospilibre\Bloc\CUsageRessource;
use Hospilibre\PlanningOp\CProtocole;
use Ox\Tests\UnitTestHospilibre;

class CBesoinRessourceTest extends UnitTestHospilibre {
  public function test__construct() {
    $besoin = new CBesoinRessource();
    $this->assertInstanceOf(CBesoinRessource::class, $besoin);
  }

  public function testIsAvailable() {
    $besoin = new CBesoinRessource();
    $this->assertIsBool($besoin->isAvailable());
  }

  public function testLoadRefTypeRessource() {
    $besoin = new CBesoinRessource();
    $this->assertInstanceOf(CTypeRessource::class, $besoin->loadRefTypeRessource());
  }

  public function testLoadRefProtocole() {
    $besoin = new CBesoinRessource();
    $this->assertInstanceOf(CProtocole::class, $besoin->loadRefProtocole());
  }

  public function loadRefUsage() {
    $besoin = new CBesoinRessource();
    $this->assertInstanceOf(CUsageRessource::class, $besoin->loadRefUsage());
  }
}
