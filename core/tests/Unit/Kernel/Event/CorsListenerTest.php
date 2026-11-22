<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit\Kernel\Event;

use Ox\Core\Auth\Authenticators\ApiTokenAuthenticator;
use Ox\Core\Kernel\Event\CorsListener;
use Ox\Tests\UnitTestHospilibre;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests for the CorsListener :
 * - onRequest : For OPTIONS request change response to 204 and stop the propagation
 * - onResponse : Set access control headers
 */
class CorsListenerTest extends UnitTestHospilibre
{
    public function testOnRequestSetResponse(): Request
    {
        $request = $this->getRequestForApi();
        $request->server->set('REQUEST_METHOD', Request::METHOD_OPTIONS);

        $listener = new CorsListener();
        $this->assertFalse($this->getPrivateProperty($listener, 'is_request_options'));

        $event = new RequestEvent($this->getKernelForTests(), $request, HttpKernelInterface::MAIN_REQUEST);
        $this->assertNull($event->getResponse());

        $listener->onRequest($event);

        $this->assertTrue($this->getPrivateProperty($listener, 'is_request_options'));

        $response = $event->getResponse();
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        return $request;
    }

    /**
     * @depends testOnRequestSetResponse
     */
    public function testOnResponseSetHeaders(Request $request): void
    {
        $listener = new CorsListener();

        $event = new ResponseEvent(
            $this->getKernelForTests(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response()
        );
        $listener->onResponse($event);

        $response = $event->getResponse();

        $this->assertEquals('GET', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertEquals('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertEquals(
            'Accept, Content-Type, Authorization, ' . ApiTokenAuthenticator::TOKEN_HEADER_KEY,
            $response->headers->get('Access-Control-Allow-Headers')
        );
        $this->assertEquals('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }
}
