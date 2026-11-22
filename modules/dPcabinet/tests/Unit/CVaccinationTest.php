<?php

namespace Hospilibre\Cabinet\Tests\Unit;

use Hospilibre\Cabinet\Vaccination\CInjection;
use Hospilibre\Cabinet\Vaccination\CVaccin;
use Hospilibre\Cabinet\Vaccination\CVaccination;
use Ox\Tests\UnitTestHospilibre;
use PHPUnit\Framework\TestCase;

/**
 * Class CVaccinationTest
 */
class CVaccinationTest extends UnitTestHospilibre {

  /**
   * Test if it can find a type of vaccine
   */
  public function testIsVaccinationActive() {
    $vaccines = [
      new CVaccin("VACCS1"),
      new CVaccin("VACCS2"),
      new CVaccin("Autre")
    ];

    $this->assertFalse(CVaccination::isVaccinationActive(new CVaccin("VACCS3"), $vaccines));
    $this->assertTrue(CVaccination::isVaccinationActive(new CVaccin("VACCS1"), $vaccines));
  }

  /**
   * Test the magical to string method
   */
  public function test__toString() {
    $vaccine = new CVaccin("VACCS1", "One", "Vaccs One");
    $vaccine2 = new CVaccin("Autre", "Other", "Other vaccs");

    $injection = new CInjection();
    $injection->speciality = "VACCSINATOR";

    $vaccination = new CVaccination();
    $vaccination->_ref_injection = $injection;

    $vaccination->_ref_vaccine = $vaccine;
    $this->assertEquals("Vaccs One<br>Produit: ".$injection->speciality, $vaccination->__toString());

    $vaccination->_ref_vaccine = $vaccine2;
    $this->assertEquals("Produit: ".$injection->speciality, $vaccination->__toString());
  }
}
