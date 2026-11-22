<?php
/**
 * @package Hospilibre\CompteRendu
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\CompteRendu\Tests\Unit;

use Hospilibre\CompteRendu\CWkhtmlToPDF;
use Ox\Tests\UnitTestHospilibre;

//class CWkhtmlToPDFTest extends UnitTestHospilibre {
//
//  public function setUp() {
//    parent::setUp();
//    $this->markTestSkipped('Warning : QSslSocket: cannot resolve SSLv3_client_method');
//  }
//
//  public function test_getExecutable() {
//    $this->assertContains("wkhtmltopdf", CWkhtmlToPDF::getExecutable());
//  }
//
//  public function test_makePDF() {
//    $urls = array(array("m" => "admin"));
//
//    $content = CWkhtmlToPDF::makePDF(null, null, $urls, "A4", "Portrait", "screen", false);
//
//    $this->assertContains("%PDF", $content);
//  }
//}
