<?php
/**
 * @package Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */


namespace Hospilibre\System\Tests\Unit;

use Ox\Core\CApp;
use Hospilibre\System\CFileSystem;
use Ox\Core\Chronometer;
use Ox\Core\CMbException;
use Hospilibre\System\Sources\CSourceFile;
use Ox\Interop\Ftp\CSourceFTP;
use Hospilibre\Files\CFile;
use Hospilibre\System\CSourceFileSystem;
use Ox\Tests\UnitTestHospilibre;
use stdClass;

class CFileSystemTest extends UnitTestHospilibre
{
    /**
     * @return CFileSystem
     */
    public function testInit(): CFileSystem
    {
        $fs              = new CFileSystem();
        $exchange_source = new CSourceFileSystem();

        $exchange_source->_id = mt_rand(1, 1000);
        $fs->init($exchange_source);

        $this->assertInstanceOf(CSourceFileSystem::class, $exchange_source);

        return $fs;
    }

    /**
     * @return bool
     * @throws CMbException
     */
    public function testSend(): bool
    {
        $fs              = new CFileSystem();
        $exchange_source = new CSourceFileSystem();
        $fs->init($exchange_source);
        $this->expectException(CMbException::class);

        return $fs->send("/test.tx");
    }

    /**
     * @return array
     * @throws CMbException
     */
    public function testReceive(): array
    {
        $fs              = new CFileSystem();
        $exchange_source = new CSourceFileSystem();
        $fs->init($exchange_source);
        $this->expectException(CMbException::class);

        return $fs->receive();
    }

    /**
     * @throws CMbException
     */
    public function testRenameFile(): void
    {
        $fs              = new CFileSystem();
        $exchange_source = new CSourceFileSystem();
        $fs->init($exchange_source);
        $this->expectException(CMbException::class);
        $fs->send("/test.tx");
        $fs->renameFile("/test.txt", "/renamed.txt");
    }

    /**
     *
     */
    public function testCallLoggable(): void
    {
        CApp::$chrono = new Chronometer();
        CApp::$chrono->start();

        $mock = $this->getMockBuilder(CFileSystem::class)
            ->setMethods(['_connect'])
            ->getMock();

        $mock->method('_connect')->willReturn(true);

        $exchange_source           = new CSourceFileSystem();
        $exchange_source->_id      = rand(1, 1000);
        $exchange_source->loggable = true;

        $mock->init($exchange_source);

        $this->assertTrue($mock->_connect());
    }


    /**
     * @param $fs CFileSystem
     *
     * @depends testInit
     */
    public function testConnectKo(CFileSystem $fs): void
    {
        $this->expectException(CMbException::class);
        $fs->send("/");
        //$fs->_connect();
    }

    /**
     * @return void
     */
    public function testGenerateNameFile(): void
    {
        $res   = CSourceFileSystem::generateFileName();
        $regex = "/(\d.+\_\d.+)/";
        $this->assertTrue((bool)preg_match($regex, $res));
    }

}
