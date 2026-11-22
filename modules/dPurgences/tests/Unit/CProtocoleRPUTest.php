<?php

/**
 * @package Hospilibre\Urgences
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Hospilibre\Hospi\CLit;
use Hospilibre\Hospi\CUniteFonctionnelle;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\CChargePriceIndicator;
use Hospilibre\PlanningOp\CModeEntreeSejour;
use Hospilibre\Urgences\CProtocoleRPU;
use Ox\Tests\UnitTestHospilibre;

class CProtocoleRPUTest extends UnitTestHospilibre {
  public function test__construct() {
    $protocole = new CProtocoleRPU();
    $this->assertInstanceOf(CProtocoleRPU::class, $protocole);
  }

  public function test_loadRefResponsable() {
    $protocole = new CProtocoleRPU();
    $this->assertInstanceOf(CMediusers::class, $protocole->loadRefResponsable());
  }

  public function test_loadRefUfSoins() {
    $protocole = new CProtocoleRPU();
    $this->assertInstanceOf(CUniteFonctionnelle::class, $protocole->loadRefUfSoins());
  }

  public function test_loadRefCharge() {
    $protocole = new CProtocoleRPU();
    $this->assertInstanceOf(CChargePriceIndicator::class, $protocole->loadRefCharge());
  }

  public function test_loadRefBox() {
    $protocole = new CProtocoleRPU();
    $this->assertInstanceOf(CLit::class, $protocole->loadRefBox());
  }

  public function test_loadRefModeEntree() {
    $protocole = new CProtocoleRPU();
    $this->assertInstanceOf(CModeEntreeSejour::class, $protocole->loadRefModeEntree());
  }

  public function test_loadProtocoles() {
    $this->assertContainsOnlyInstancesOf(CProtocoleRPU::class, CProtocoleRPU::loadProtocoles());
  }
}