<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Unit\Elastic;

use Ox\Core\CLogger;
use Hospilibre\System\Elastic\ApplicationLog;
use Ox\Tests\UnitTestHospilibre;

class ApplicationLogTest extends UnitTestHospilibre
{

    public function testPrepareToRender(): void
    {
        $level   = CLogger::LEVEL_DEBUG;
        $message = "TEST EXECUTION - Test1";
        $log     = new ApplicationLog(
            $message,
            ["context" => "test"],
            $level
        );

        $data = $log->prepareToRender();

        self::assertIsArray($data);
        self::assertStringMatchesFormat("[%d-%d-%d %d:%d:%d.%d]", $data["date"]);
        $level_name = strtoupper(CLogger::getLevels()[$level]);
        self::assertEquals("[" . $level_name . "]", $data["level"]);
        self::assertEquals(CLogger::getLevelsColors()[$level_name], $data["color"]);
        self::assertEquals($message, $data["message"]);
    }

    public function testToLogFile(): void
    {
        $level   = CLogger::LEVEL_DEBUG;
        $message = "TEST EXECUTION - Test1";
        $log     = new ApplicationLog(
            $message,
            ["context" => "test"],
            $level
        );

        $data = $log->toLogFile();

        self::assertIsString($data);
        self::assertMatchesRegularExpression(
            "/\[(.*?)] \[(\w+)] (.*?) \[(.*?)] \[(.*?)]/",
            $data
        );
    }
}
