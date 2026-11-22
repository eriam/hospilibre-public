<?php

namespace Hospilibre\Hospi\Tests\Unit;

use Hospilibre\Hospi\CChambre;
use Hospilibre\Hospi\CEmplacement;
use Hospilibre\Hospi\CLit;
use Hospilibre\Hospi\CService;
use Ox\Tests\UnitTestHospilibre;

class CChambreTest extends UnitTestHospilibre {
  public function test__construct() {
    $chambre = new CChambre();
    $this->assertInstanceOf(CChambre::class, $chambre);
  }

  public function testLoadRefService() {
    $chambre = new CChambre();
    $this->assertInstanceOf(CService::class, $chambre->loadRefService());
  }

  public function testLoadRefsLits() {
    /** @var CChambre $chambre */
    $chambre = $this->getRandomObjects(CChambre::class);

    $this->assertContainsOnlyInstancesOf(CLit::class, $chambre->loadRefsLits());
  }

  public function testLoadRefEmplacement() {
    $chambre = new CChambre();
    $this->assertInstanceOf(CEmplacement::class, $chambre->loadRefEmplacement());
  }

  public function testCheckChambre() {
    /** @var CChambre $chambre */
    $chambre = $this->getRandomObjects(CChambre::class);

    $chambre->loadRefsLits();

    foreach ($chambre->_ref_lits as $_lit) {
      $_lit->_ref_affectations = array();
    }

    $chambre->checkChambre();

    $this->assertTrue($chambre->_nb_lits_dispo <= count($chambre->_ref_lits));
  }
}