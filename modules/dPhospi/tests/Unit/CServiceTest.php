<?php

namespace Hospilibre\Hospi\Tests\Unit;

use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\Hospi\CAffectationUniteFonctionnelle;
use Hospilibre\Hospi\CChambre;
use Hospilibre\Hospi\CLit;
use Hospilibre\Hospi\CService;
use Hospilibre\Hospi\CUniteFonctionnelle;
use Ox\Tests\UnitTestHospilibre;

class CServiceTest extends UnitTestHospilibre {
  public function test__construct() {
    $service = new CService();
    $this->assertInstanceOf(CService::class, $service);
  }

  public function testLoadGroupList() {
    $service = new CService();
    $this->assertContainsOnlyInstancesOf(CService::class, $service->loadGroupList());
  }

  public function testLoadRefsChambres() {
    /** @var CService $service */
    $service = $this->getRandomObjects(CService::class);
    $this->assertContainsOnlyInstancesOf(CChambre::class, $service->loadRefsChambres());
  }

  public function testLoadRefsLits() {
    /** @var CService $service */
    $service = $this->getRandomObjects(CService::class);
    $this->assertContainsOnlyInstancesOf(CLit::class, $service->loadRefsLits());
  }

  public function testLoadRefsAffectations() {
    /** @var CService $service */
    $service = $this->getRandomObjects(CService::class);
    $this->assertContainsOnlyInstancesOf(CAffectation::class, $service->loadRefsAffectations(CMbDT::date()));
  }

  public function testLoadRefsAffectationsCouloir() {
    /** @var CService $service */
    $service = $this->getRandomObjects(CService::class);
    $this->assertContainsOnlyInstancesOf(CAffectation::class, $service->loadRefsAffectationsCouloir(CMbDT::date()));
  }

  public function testLoadRefGroup() {
    /** @var CService $service */
    $service = $this->getRandomObjects(CService::class);
    $this->assertInstanceOf(CGroups::class, $service->loadRefGroup());
  }

  public function testLoadServicesUrgence() {
    $services_urgences = CService::loadServicesUrgence();
    $this->assertEquals(count($services_urgences), array_sum(CMbArray::pluck($services_urgences, "urgence")));
  }

  public function testLoadServicesUHCD() {
    $services_UHCD = CService::loadServicesUHCD();
    $this->assertEquals(count($services_UHCD), array_sum(CMbArray::pluck($services_UHCD, "uhcd")));
  }

  public function testLoadServicesImagerie() {
    $services_imagerie = CService::loadServicesImagerie();
    $this->assertEquals(count($services_imagerie), array_sum(CMbArray::pluck($services_imagerie, "imagerie")));
  }

  public function testLoadServiceExterne() {
    $service_externe = CService::loadServiceExterne();
    $this->assertEquals("1", $service_externe->externe);
  }

  public function loadServiceRadiologie() {
    $service_radiologie = CService::loadServiceRadiologie();
    $this->assertEquals("1", $service_radiologie->radiologie);
  }

  public function testLoadRefAffectationsUF() {
    /** @var CService $service */
    $service = $this->getRandomObjects(CService::class);
    $this->assertContainsOnlyInstancesOf(CAffectationUniteFonctionnelle::class, $service->loadRefAffectationsUF());
  }

  public function loadRefsUFs() {
    /** @var CService $service */
    $service = $this->getRandomObjects(CService::class);
    $this->assertContainsOnlyInstancesOf(CUniteFonctionnelle::class, $service->loadRefsUFs());
  }
}