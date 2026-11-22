<?php

/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests;

use Ox\Core\BufferizedError;
use Ox\Core\CAppUI;
use Ox\Core\CError;
use Hospilibre\Mediusers\CMediusers;
use Ox\Tests\UnitTestHospilibre;

/**
 * Test for the BufferizedError class
 */
class BufferizedErrorTest extends UnitTestHospilibre
{
    public function testEmptyObject(): void
    {
        $error = BufferizedError::createFromArray([]);
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_CODE));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_MICROTIME));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_USER_ID));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_SERVER_IP));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_TIME));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_REQUEST_UID));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_TYPE));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_TEXT));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_FILE));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_LINE));
        $this->assertNull($this->getPrivateProperty($error, BufferizedError::FIELD_SIGNATURE_HASH));
        $this->assertEmpty($this->getPrivateProperty($error, BufferizedError::FIELD_DATA));
    }

    public function testJsonSerialize(): void
    {
        $error = $this->createBufferizedError();
        $this->assertEquals(
            json_encode(
                [
                    BufferizedError::FIELD_USER_ID => CMediusers::get()->_id,
                    BufferizedError::FIELD_TIME => '2022-01-01 00:00:00',
                    BufferizedError::FIELD_TYPE => 'exception',
                    BufferizedError::FIELD_TEXT => 'an error occured',
                    BufferizedError::FIELD_FILE => 'test.php',
                    BufferizedError::FIELD_LINE => 52,
                ]
            ),
            json_encode($error)
        );
    }

    public function testToArray(): void
    {
        $error = $this->createBufferizedError();
        $this->assertEquals(
            [
                BufferizedError::FIELD_MICROTIME      => '123456789',
                BufferizedError::FIELD_USER_ID        => CMediusers::get()->_id,
                BufferizedError::FIELD_SERVER_IP      => '111111',
                BufferizedError::FIELD_TIME           => '2022-01-01 00:00:00',
                BufferizedError::FIELD_REQUEST_UID    => 'request_id',
                BufferizedError::FIELD_TYPE           => 'exception',
                BufferizedError::FIELD_TEXT           => 'an error occured',
                BufferizedError::FIELD_FILE           => 'test.php',
                BufferizedError::FIELD_LINE           => 52,
                BufferizedError::FIELD_SIGNATURE_HASH => 'hash',
                BufferizedError::FIELD_DATA           => ['lorem' => 'ipsum'],
            ],
            $error->toArray()
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testGetUserViewEmpty(): void
    {
        $error = $this->createBufferizedError();

        CAppUI::$user = null;

        $this->assertNull($this->invokePrivateMethod($error, 'getUserView'));
    }

    public function testGetUserView(): void
    {
        $error = $this->createBufferizedError();
        $this->assertEquals(CMediusers::get()->_view, $this->invokePrivateMethod($error, 'getUserView'));
    }

    public function testToString(): void
    {
        $error = $this->createBufferizedError();

        $this->assertEquals(
            sprintf(
                "\n\n<div class='%s'>\n<strong>User: </strong>%s (%s)<strong>Time: </strong>%s "
                ."<strong>Type: </strong>%s <strong>Text: </strong>%s <strong>File: </strong>%s:%s</div>",
                str_replace(
                    'big-',
                    'small-',
                    CError::$_classes[$this->getPrivateProperty($error, BufferizedError::FIELD_CODE)] ?? null
                ),
                $this->invokePrivateMethod($error, 'getUserView'),
                $this->getPrivateProperty($error, BufferizedError::FIELD_USER_ID),
                $this->getPrivateProperty($error, BufferizedError::FIELD_TIME),
                $this->getPrivateProperty($error, BufferizedError::FIELD_TYPE),
                $this->getPrivateProperty($error, BufferizedError::FIELD_TEXT),
                $this->getPrivateProperty($error, BufferizedError::FIELD_FILE),
                $this->getPrivateProperty($error, BufferizedError::FIELD_LINE)
            ),
            (string) $error
        );
    }

    private function createBufferizedError(): BufferizedError
    {
        return BufferizedError::createFromArray(
            [
                BufferizedError::FIELD_CODE           => 256,
                BufferizedError::FIELD_MICROTIME      => '123456789',
                BufferizedError::FIELD_USER_ID        => CMediusers::get()->_id,
                BufferizedError::FIELD_SERVER_IP      => '111111',
                BufferizedError::FIELD_TIME           => '2022-01-01 00:00:00',
                BufferizedError::FIELD_REQUEST_UID    => 'request_id',
                BufferizedError::FIELD_TYPE           => 'exception',
                BufferizedError::FIELD_TEXT           => 'an error occured',
                BufferizedError::FIELD_FILE           => 'test.php',
                BufferizedError::FIELD_LINE           => 52,
                BufferizedError::FIELD_SIGNATURE_HASH => 'hash',
                BufferizedError::FIELD_DATA           => ['lorem' => 'ipsum'],
            ]
        );
    }
}
