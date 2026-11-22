<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit\Kernel\Event;

use Error;
use Exception;
use Ox\Core\Kernel\Event\ExceptionListener;
use Ox\Core\Kernel\Exception\HttpException;
use Ox\Core\Kernel\Exception\UnavailableApplicationException;
use Ox\Tests\UnitTestHospilibre;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

class ExceptionListenerTest extends UnitTestHospilibre
{
    public function testExceptionListener()
    {
        // Use the real Kernel if tests are launched with symfony Kernel
        $kernel = $this->getKernelForTests();

        $subscriber = new ExceptionListener($kernel->getContainer()->get('twig'));

        // gui
        $req   = Request::create('/gui/lorem/ipsum');
        $event = new ExceptionEvent($kernel, $req, HttpKernelInterface::MAIN_REQUEST, new Exception('ipsum'));
        $subscriber->onException($event);
        $this->assertTrue($event->hasResponse());
        $this->assertInstanceOf(Response::class, $event->getResponse());

        // api
        $req   = Request::create('/api/foo/bar');
        $event = new ExceptionEvent($kernel, $req, HttpKernelInterface::MAIN_REQUEST, new Exception('ipsum'));
        $subscriber->onException($event);
        $this->assertTrue($event->hasResponse());
        $this->assertInstanceOf(JsonResponse::class, $event->getResponse());
    }

    /**
     * @dataProvider canLogProvider
     */
    public function testCanLog(Throwable $throwable, bool $expected): void
    {
        $listener = new ExceptionListener($this->getKernelForTests()->getContainer()->get('twig'));
        $this->assertEquals($expected, $this->invokePrivateMethod($listener, 'canLog', $throwable));
    }

    public function canLogProvider(): array
    {
        return [
            'can_log_basic_exception'             => [new Exception(), true],
            'can_log_error'                       => [new Error(), true],
            'can_log_loggable_http_exception'     => [new HttpException(500), true],
            'can_log_non_loggable_http_exception' => [new UnavailableApplicationException(500), false],
        ];
    }
}
