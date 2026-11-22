<?php
/**
 * @package Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Ftp\Tests\Unit;

use Ox\Core\CApp;
use Ox\Interop\Ftp\CSFTP;
use Ox\Interop\Eai\Client\Legacy\CFileSystemLegacy;
use Ox\Core\Chronometer;
use Ox\Core\CMbException;
use Hospilibre\System\Sources\CSourceFile;
use Ox\Interop\Ftp\CSourceFTP;
use Ox\Interop\Ftp\CSourceSFTP;
use Hospilibre\Files\CFile;
use Hospilibre\System\CSourceFileSystem;
use Ox\Tests\UnitTestHospilibre;
use stdClass;

class CSFTPTest extends UnitTestHospilibre
{
    /**
     * @return CSFTP
     */
    public function initForTest(): CSFTP
    {
        $sftp                  = new CSFTP();
        $exchange_source       = new CSourceSFTP();
        $exchange_source->_id  = mt_rand(1, 1000);
        $exchange_source->name = "test";
        $exchange_source->host = "127.0.0.1";
        $sftp->init($exchange_source);

        return $sftp;
    }

    /**
     * @return CSFTP
     */
    public function testInit(): CSFTP
    {
        $sftp            = new CSFTP();
        $exchange_source = new CSourceSFTP();

        $exchange_source->_id = mt_rand(1, 1000);
        $sftp->init($exchange_source);

        $this->assertInstanceOf(CSourceSFTP::class, $exchange_source);

        return $sftp;
    }

    /**
     * TestTruncate
     */
    public function testTruncate(): void
    {
        $text = new stdClass();
        $this->assertInstanceOf(stdClass::class, CSFTP::truncate($text));

        // length 100
        $text = 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labor.';

        $string = null;
        for ($i = 0; $i <= 10; $i++) {
            $string .= $text;
        }

        $string_truncated = CSFTP::truncate($string);

        $this->assertStringEndsWith('... [1100 bytes]', $string_truncated);

        $this->assertEquals(1024 + 13, strlen($string_truncated));
    }

    /**
     * @return void
     */
    public function testGenerateNameFile(): void
    {
        $res   = CSourceSFTP::generateFileName();
        $regex = "/(\d.+\_\d.+)/";
        $this->assertTrue((bool)preg_match($regex, $res));
    }
}
