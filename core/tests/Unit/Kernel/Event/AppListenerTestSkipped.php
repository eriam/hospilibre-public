<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit\Kernel\Event;

use Ox\Core\Kernel\Event\AppListener;
use Ox\Tests\TestsException;
use Ox\Tests\UnitTestHospilibre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Test the behaviours of AppListener :
 * - Set the public environment onRouting TODO When public is activated
 * - Stop CApp onResponse TODO When public is activated
 *
 * @runTestsInSeparateProcesses
 */
class AppListenerTestSkipped extends UnitTestHospilibre
{
    /**
     * @return void
     * @throws TestsException
     */
    public function testOnRequestNotMainRequest(): void
    {
        $listener = new AppListener($this->getKernelForTests()->getContainer());
        $this->assertFalse($this->getPrivateProperty($listener, 'is_started'));

        $event = new RequestEvent(
            $this->getKernelForTests(),
            $this->getRequestForApi(),
            HttpKernelInterface::SUB_REQUEST
        );

        $listener->onRequest($event);

        $this->assertFalse($this->getPrivateProperty($listener, 'is_started'));
    }

    public function testOnRequestNotRequestApi(): void
    {
        $listener = new AppListener();
        $this->assertFalse($this->getPrivateProperty($listener, 'is_started'));

        $event = new RequestEvent($this->getKernelForTests(), new Request(), HttpKernelInterface::MAIN_REQUEST);

        $listener->onRequest($event);

        $this->assertFalse($this->getPrivateProperty($listener, 'is_started'));
    }

    public function testOnTerminateWithtoutStart(): void
    {
        $kernel   = $this->getKernelForTests();
        $listener = new AppListener($kernel->getContainer());
        $event    = new TerminateEvent($kernel, new Request(), new Response());
        $this->assertFalse($event->isPropagationStopped());

        $listener->onTerminate($event);

        $this->assertTrue($event->isPropagationStopped());
    }
}
