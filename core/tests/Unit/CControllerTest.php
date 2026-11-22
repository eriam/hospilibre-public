<?php

namespace Ox\Core\Tests\Unit;

use Exception;
use InvalidArgumentException;
use Ox\Core\Api\Resources\Item;
use Ox\Core\CAppUI;
use Ox\Core\CController;
use Ox\Core\CModelObjectCollection;
use Ox\Core\Kernel\Exception\ControllerException;
use Ox\Core\Kernel\Routing\RouterBridge;
use Ox\Interop\Fhir\Controllers\CFHIRController;
use Hospilibre\Admin\Controllers\PermissionController;
use Hospilibre\Admin\CUser;
use Hospilibre\Admin\Tests\Fixtures\UsersFixtures;
use Hospilibre\System\Controllers\CSystemController;
use Hospilibre\System\CSourceHTTP;
use Hospilibre\System\Tests\Classes\SourceTestTrait;
use Ox\Tests\UnitTestHospilibre;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class CControllerTest extends UnitTestHospilibre
{
    use SourceTestTrait;

    private const CONTROLLER      = CFHIRController::class;
    private const FUNCTION        = 'initBlink1';
    private const FUNCTION_FAILED = 'failedTestFunction';
    private const MODULE          = 'fhir';
    private const ROUTE           = 'fhir_metadata';

    /**
     * RenderResponse
     */
    public function testRenderResponse(): void
    {
        $controller = $this->getController();
        $content    = 'Lorem ipsum';
        $response   = $controller->renderResponse($content);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals($response->getContent(), $content);
    }

    /**
     * GetModule
     *
     * @throws Exception
     */
    public function testGetModule(): void
    {
        $controller = $this->getController();
        $module     = $controller->getModuleName();
        $this->assertEquals($module, self::MODULE);
    }

    /**
     * GetControllerFromRequest
     *
     * @throws ControllerException
     */
    public function testGetControllerFromRequest(): void
    {
        $route = new Route('/lorem/ipsum');
        $route->setDefault('_controller', self::CONTROLLER);
        $collection = new RouteCollection();
        $collection->add('a', $route);

        $request    = Request::create('/lorem/ipsum');
        $matcher    = new UrlMatcher($collection, new RequestContext());
        $parameters = $matcher->matchRequest($request);
        $request->attributes->add($parameters);

        $controller = CController::getControllerFromRequest($request);
        $this->assertEquals($controller, $this->getController());
    }

    /**
     *
     */
    public function testGetReflectionMethod(): void
    {
        $controller = self::CONTROLLER;
        /** @var CController $controller */
        $controller = new $controller();
        $this->assertInstanceOf(ReflectionMethod::class, $controller->getReflectionMethod(self::FUNCTION));
    }

    /**
     *
     */
    public function testGetReflectionClass(): void
    {
        $controller = self::CONTROLLER;
        /** @var CController $controller */
        $controller = new $controller();
        $this->assertInstanceOf(ReflectionClass::class, $controller->getReflectionClass());
    }

    /**
     * RenderJsonRespons
     */
    public function testRenderJsonResponse(): void
    {
        $controller = $this->getController();
        $content    = ['foo' => 'bar'];
        $response   = $controller->renderJsonResponse($content, 200, [], false);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals($response->getContent(), json_encode($content));
    }

    /**
     * GetControllerFromRouteOk
     */
    public function testGetControllerFromRouteOk(): void
    {
        $this->assertInstanceOf(CController::class, CController::getControllerFromRoute($this->getRoute()));
    }

    /**
     * GetControllerFromRouteKo
     */
    public function testGetControllerFromRouteKo(): void
    {
        $this->expectException(ControllerException::class);
        CController::getControllerFromRoute(new Route('api', []));
    }

    /**
     * GetMethodFromRouteOk
     */
    public function testGetMethodFromRouteOk(): void
    {
        $this->assertEquals(self::FUNCTION, CController::getMethodFromRoute($this->getRoute()));
    }

    /**
     * GetMethodFromRouteKo
     */
    public function testGetMethodFromRouteKo(): void
    {
        $this->expectException(ControllerException::class);
        CController::getMethodFromRoute($this->getRouteFailed());
    }

    /**
     * @return CController
     */
    private function getController(): CController
    {
        $controller = self::CONTROLLER;

        return new $controller();
    }

    /**
     * @return Route
     */
    private function getRoute(): Route
    {
        return new Route('http::localhost', ['_controller' => self::CONTROLLER . '::' . self::FUNCTION]);
    }

    /**
     * @return Route
     */
    private function getRouteFailed(): Route
    {
        return new Route('http::localhost', ['_controller' => self::CONTROLLER . '::' . self::FUNCTION_FAILED]);
    }


    public function testRedirect(): void
    {
        $controller = new PermissionController();
        $target_url = 'http://www.loremi-ipsum.fr';
        /** @var RedirectResponse $response */
        $response = $this->invokePrivateMethod($controller, 'redirect', $target_url);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals($target_url, $response->getTargetUrl());
    }

    public function testRedirectToRoute(): void
    {
        $controller = new PermissionController();
        /** @var RedirectResponse $response */
        $response = $this->invokePrivateMethod($controller, 'redirectToRoute', 'admin_identicate', [], 302);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals($controller->generateUrl('admin_identicate'), $response->getTargetUrl());
        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testRedirectToRouteFailed(): void
    {
        $controller = new PermissionController();
        $this->expectException(InvalidArgumentException::class);
        $this->invokePrivateMethod($controller, 'redirectToRoute', 'system_about', [], 404);
    }

    public function testRenderApiResponse(): void
    {
        $controller = $this->getKernelForTests()->getContainer()->get(CSystemController::class);
        $resource   = new Item(new CUser());
        $reponse    = $controller->renderApiResponse($resource);
        $this->assertInstanceOf(JsonResponse::class, $reponse);
        $this->assertNotNull($reponse->getEtag());
    }

    public function testStoreObjectAndRenderApiResponse(): void
    {
        $controller = $this->getKernelForTests()->getContainer()->get(CSystemController::class);

        /** @var CUser $user */
        $user                = $this->getObjectFromFixturesReference(
            CUser::class,
            UsersFixtures::REF_USER_LOREM_IPSUM
        );
        $user->user_address1 = uniqid('address');

        /** @var Response $response */
        $response = $controller->storeObjectAndRenderApiResponse($user);

        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertStringContainsString(
            '"user_username": "' . UsersFixtures::REF_USER_LOREM_IPSUM . '"',
            $response->getContent()
        );
    }

    public function testStoreCollectionAndRenderApiResponse(): void
    {
        $controller = $this->getKernelForTests()->getContainer()->get(CSystemController::class);
        $collection = new CModelObjectCollection();

        /** @var CUser $user */
        $user                = $this->getObjectFromFixturesReference(
            CUser::class,
            UsersFixtures::REF_USER_LOREM_IPSUM
        );
        $user->user_address1 = uniqid('address');
        $collection->add($user);

        $user2  = new CUser();
        $uniqid = uniqid();
        $user2->cloneFrom($user);
        $user2->user_username = $uniqid;
        $collection->add($user2);

        /** @var Response $response */
        $response = $controller->storeCollectionAndRenderApiResponse($collection);

        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertStringContainsString(
            '"user_username": "' . UsersFixtures::REF_USER_LOREM_IPSUM . '"',
            $response->getContent()
        );
        $this->assertStringContainsString(
            '"user_username": "' . $uniqid . '"',
            $response->getContent()
        );
    }

    public function testStore(): void
    {
        $controller = new CSystemController();
        /** @var CUser $model */
        $model                = $this->getObjectFromFixturesReference(
            CUser::class,
            UsersFixtures::REF_USER_LOREM_IPSUM
        );
        $address1             = uniqid('address');
        $model->user_address1 = $address1;

        $collection = new CModelObjectCollection();
        $collection->add($model);

        /** @var Item $item */
        $item = $this->invokePrivateMethod($controller, 'storeCollection', $collection);
        $this->assertEquals($item->getDatas()[0]->user_address1, $address1);
    }

    public function testSelfRequestSourceDisabled(): void
    {
        $source         = new CSourceHTTP();
        $source->active = 0;
        $controller     = new CSystemController();

        $this->expectExceptionObject(
            new ControllerException(Response::HTTP_INTERNAL_SERVER_ERROR, CAppUI::tr('access-forbidden'))
        );
        $this->invokePrivateMethod($controller, 'selfRequest', $source);
    }

    public function testSelfRequestUnautorized(): void
    {
        $source = $this->getMockBuilder(CSourceHTTP::class)
            ->disableOriginalConstructor()
            ->getMock();
        $source->host = '127.0.0.1';
        $source->active = 1;

        $response = new \Nyholm\Psr7\Response(Response::HTTP_UNAUTHORIZED, [], 'loremp ipsum');
        $this->mockSourceForResponse($response, $source);

        $controller = new CSystemController();
        $this->expectExceptionObject(
            new ControllerException(Response::HTTP_INTERNAL_SERVER_ERROR, CAppUI::tr('access-forbidden'))
        );

        $this->invokePrivateMethod($controller, 'selfRequest', $source);
    }

    public function testPrepareHeaderFromResponse(): void
    {
        $response_headers = [
            'Server'            => "test",
            'X-Powered-By'      => "test",
            'X-Mb-RequestInfo'  => "test",
            'Set-Cookie'        => "test",
            'Transfer-Encoding' => "test",
        ];

        $this->assertEmpty(
            $this->invokePrivateMethod($this->getController(), 'prepareHeaderFromResponse', $response_headers)
        );
    }


    public function testRenderXmlResponse(): void
    {
        $controller = $this->getController();
        $content    = $controller->renderXmlResponse(['lorem' => "ipsum"])->getContent();

        $this->assertStringContainsString('<lorem>ipsum</lorem>', $content);
    }
}
