<?php
/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients\Tests\Unit;

use DateTimeImmutable;
use Hospilibre\Patients\BloodSugarDayReport;
use Hospilibre\Patients\BloodSugarDayConstantsReport;
use Ox\Tests\UnitTestHospilibre;

/**
 * Class BloodSugarDayTest
 */
class BloodSugarDayTest extends UnitTestHospilibre {
  /**
   * @test
   */
  public function get_string_date(): void {
    $blood_sugar_day = new BloodSugarDayReport(new DateTimeImmutable("2020-09-01"), new BloodSugarDayConstantsReport(), []);
    $this->assertEquals("2020-09-01", $blood_sugar_day->getDateString());
  }
}
