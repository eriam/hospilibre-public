<?php
/**
 * @package Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Ftp\Tests\Unit;

use Ox\Core\CApp;
use Ox\Interop\Ftp\CFTP;
use Ox\Core\Chronometer;
use Ox\Core\CMbException;
use Ox\Interop\Ftp\CSourceFTP;
use Ox\Tests\UnitTestHospilibre;
use stdClass;

class CFTPTest extends UnitTestHospilibre
{
    /**
     * TestTruncate
     */
    public function testTruncate(): void
    {
        $text = new stdClass();
        $this->assertInstanceOf(stdClass::class, CFTP::truncate($text));

        // length 100
        $text = 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labor.';

        $string = null;
        for ($i = 0; $i <= 10; $i++) {
            $string .= $text;
        }

        $string_truncated = CFTP::truncate($string);

        $this->assertStringEndsWith('... [1100 bytes]', $string_truncated);

        $this->assertEquals(1024 + 13, strlen($string_truncated));
    }

    /**
     * @return void
     */
    public function testInitError(): void
    {
        $ftp             = new CFTP();
        $exchange_source = new CSourceFTP();
        $this->expectException(CMbException::class);
        $ftp->init($exchange_source);
    }

    /**
     * @return void
     */
    public function testInit(): void
    {
        $ftp = $this->getSource();
        $this->assertInstanceOf(CSourceFTP::class, $ftp->_source);
    }

    private function getSource(): CFTP
    {
        $ftp                  = new CFTP();
        $exchange_source      = new CSourceFTP();
        $exchange_source->_id = mt_rand(1, 1000);
        $ftp->init($exchange_source);

        return $ftp;
    }

    public function testCallError(): void
    {
        $ftp = $this->getSource();
        $this->expectException(CMbException::class);
        $ftp->toto();
    }

    /**
     * @return void
     */
    public function testCallLoggable(): void
    {
        CApp::$chrono = new Chronometer();
        CApp::$chrono->start();

        $mock = $this->getMockBuilder(CFTP::class)
            ->setMethods(['_connect'])
            ->getMock();

        $mock->method('_connect')->willReturn(true);

        $exchange_source           = new CSourceFTP();
        $exchange_source->_id      = rand(1, 1000);
        $exchange_source->loggable = true;

        $mock->init($exchange_source);

        $this->assertTrue($mock->connect());
    }

    public function testConnectKo(): void
    {
        $ftp = $this->getSource();
        $this->expectException(CMbException::class);
        $ftp->connect();
    }

    /**
     * @return void
     */
    public function testGenerateNameFile(): void
    {
        $res   = CSourceFTP::generateFileName();
        $regex = "/(\d.+\_\d.+)/";
        $this->assertTrue((bool)preg_match($regex, $res));
    }


}
