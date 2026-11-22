<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit\Kernel\Event;

use Ox\Core\Api\Request\Etags;
use Ox\Core\Kernel\Event\EtagListener;
use Ox\Tests\UnitTestHospilibre;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * ETags listener :
 * - onRequest : Get request ETags
 * - onResponse : If response's ETag is in the request ETags, return notModifiedResponse
 */
class EtagListenerTest extends UnitTestHospilibre
{
    private const ETAG = 'loremipsum';

    public function testOnRequestGetEtags(): array
    {
        $listener = new EtagListener();

        $request = $this->getRequestForApi();
        $request->headers->set('If-None-Match', self::ETAG);

        $event = new RequestEvent($this->getKernelForTests(), $request, HttpKernelInterface::MAIN_REQUEST);
        $this->assertNull($this->getPrivateProperty($listener, 'request_etags'));

        $listener->onRequest($event);

        $this->assertEquals(new Etags([self::ETAG]), $this->getPrivateProperty($listener, 'request_etags'));

        return [$listener, $request];
    }

    /**
     * @depends testOnRequestGetEtags
     */
    public function testOnResponseReturnNotModifiedResponse(array $args): void
    {
        [$listener, $request] = $args;

        $response = new Response(null, 200, ['ETag' => self::ETAG]);
        $event    = new ResponseEvent(
            $this->getKernelForTests(), $request, HttpKernelInterface::MAIN_REQUEST, $response
        );

        $listener->onResponse($event);

        $event_response = $event->getResponse();
        $this->assertNotEquals($response, $event_response);
        $this->assertEquals(Response::HTTP_NOT_MODIFIED, $event_response->getStatusCode());
        $this->assertEquals('"' . self::ETAG . '"', $event_response->getEtag());
    }
}
