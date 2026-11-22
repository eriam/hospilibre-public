<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit\Kernel;

use Exception;
use Ox\Core\CApp;
use Ox\Core\Kernel\Event\AppListener;
use Ox\Core\Kernel\Kernel;
use Ox\Tests\UnitTestHospilibre;
use PHPUnit\Framework\MockObject\Rule\InvokedCount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelInterface;

class AppListenerTestSkipped extends UnitTestHospilibre
{
    public function eventProvider(): array
    {
        $request        = new Request();
        $public_request = new Request();

        $public_request->attributes->set('is_api', true);
        $public_request->attributes->set('security', []);

        $kernel = $this->getMockBuilder(KernelInterface::class)
            ->getMock();

        $master_request_event  = new RequestEvent($kernel, $request, Kernel::MAIN_REQUEST);
        $master_response_event = new ResponseEvent($kernel, $request, Kernel::MAIN_REQUEST, new Response());

        $sub_request_event  = new RequestEvent($kernel, $request, Kernel::SUB_REQUEST);
        $sub_response_event = new ResponseEvent($kernel, $request, Kernel::SUB_REQUEST, new Response());

        $public_master_request_event  = new RequestEvent($kernel, $public_request, Kernel::MAIN_REQUEST);
        $public_master_response_event = new ResponseEvent(
            $kernel,
            $public_request,
            Kernel::MAIN_REQUEST,
            new Response()
        );

        $public_sub_request_event  = new RequestEvent($kernel, $public_request, Kernel::SUB_REQUEST);
        $public_sub_response_event = new ResponseEvent($kernel, $public_request, Kernel::SUB_REQUEST, new Response());

        return [
            'normal request' => [$master_request_event, $master_response_event, $this->never(), $this->never()],
            'normal sub'     => [$sub_request_event, $sub_response_event, $this->never(), $this->never()],
            'public request' => [
                $public_master_request_event,
                $public_master_response_event,
                $this->once(),
                $this->once(),
            ],
            // No sub request in public environment
            'public sub'     => [$public_sub_request_event, $public_sub_response_event, $this->never(), $this->never()],
        ];
    }

    /**
     * @dataProvider eventProvider
     *
     * @param RequestEvent  $request_event
     * @param ResponseEvent $response_event
     * @param InvokedCount  $count_init
     * @param InvokedCount  $count_stop
     *
     * @throws Exception
     */
    public function testPublicRequestIsHandledOnRouting(
        RequestEvent $request_event,
        ResponseEvent $response_event,
        InvokedCount $count_init,
        InvokedCount $count_stop
    ): void {
        $this->markTestSkipped('TODO [public] Restore with public');
        $app = $this->getMockBuilder(CApp::class)
            ->disableOriginalConstructor()
            ->getMock();

        $listener = $this->getMockBuilder(AppListener::class)
            ->onlyMethods(['isStarted', 'initPublicEnvironment', 'stopPublicEnvironment'])
            ->setConstructorArgs([$app])
            ->getMock();

        $listener->method('isStarted')->willReturn(true);

        $listener->expects($count_init)->method('initPublicEnvironment');
        $listener->onRouting($request_event);

        $listener->expects($count_stop)->method('stopPublicEnvironment');
        $listener->onResponse($response_event);
    }

    /**
     * Preventing unit test handlers to fail when in public environment
     * (because of CApp global state internally modified).
     */
    public function tearDown(): void
    {
        CApp::getInstance()->setPublic(new Request());
    }
}
