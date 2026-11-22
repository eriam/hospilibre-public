<?php

/**
 * @package Hospilibre\System
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Unit\Controllers;

use Ox\Core\Api\Request\RequestApi;
use Ox\Core\Api\Request\RequestFilter;
use Ox\Core\Cache;
use Ox\Core\CAppUI;
use Hospilibre\System\Api\ArrayFilter;
use Hospilibre\System\Controllers\CLocalesController;
use Hospilibre\System\CTranslationOverwrite;
use Ox\Tests\UnitTestHospilibre;
use Symfony\Component\HttpFoundation\Request;

/**
 * Description
 */
class CLocalesControllerTest extends UnitTestHospilibre
{
    public function testModuleDoesNotExists(): void
    {
        $request_api = RequestApi::createFromRequest(new Request());

        $controller = new CLocalesController();
        $this->expectExceptionMessage("Module 'dPtoto' does not exists or is not active");
        $controller->listLocales('fr', 'toto', $request_api);
    }

    public function testResponseIsOk(): void
    {
        $request_api = RequestApi::createFromRequest(new Request());
        $controller = new CLocalesController();
        $response = $controller->listLocales('fr', 'system', $request_api);

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('locales', $content['data']['type']);

        $this->assertTrue(count($content['data']['attributes']) > 0);
    }

    public function testResponseIsOkWithDp(): void
    {
        $request_api = RequestApi::createFromRequest(new Request());
        $controller = new CLocalesController();
        $response = $controller->listLocales('fr', 'patients', $request_api);

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('locales', $content['data']['type']);

        $this->assertTrue(count($content['data']['attributes']) > 0);
    }

    public function testFilterEmptyResult(): void
    {
        $request_api = RequestApi::createFromRequest(new Request(['filter' => 'key.contains.' . uniqid()]));
        $controller = new CLocalesController();
        $response = $controller->listLocales('fr', 'system', $request_api);

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('locales', $content['data']['type']);

        $this->assertCount(0, $content['data']['attributes']);
    }

    public function testFilterOk(): void
    {
        $request_api = RequestApi::createFromRequest(
            new Request(
                ['filter' => ArrayFilter::SEARCH_IN_KEY . '.' . RequestFilter::FILTER_CONTAINS . '.CAbon',]
            )
        );

        $controller = new CLocalesController();
        $response_filtered = $controller->listLocales('fr', 'system', $request_api);

        $this->assertEquals(200, $response_filtered->getStatusCode());

        $content_filter = json_decode($response_filtered->getContent(), true);
        $this->assertEquals('locales', $content_filter['data']['type']);

        $request_api = RequestApi::createFromRequest(new Request());
        $controller = new CLocalesController();
        $response = $controller->listLocales('fr', 'system', $request_api);
        $content = json_decode($response->getContent(), true);

        // Filtered response must have less elements than base response
        $this->assertTrue(count($content_filter['data']['attributes']) < count($content['data']['attributes']));
    }

    public function testApplyTranslationsOverwrite(): void
    {
        $new_trans = uniqid();

        $translation = new CTranslationOverwrite();
        $translation->language = 'fr';
        $translation->source = 'Aggregate';
        $translation->loadMatchingObjectEsc();
        $translation->translation = $new_trans;
        if ($msg = $translation->store()) {
            $this->fail($msg);
        }

        $cache = new Cache('locales', 'fr' . '-' . CAppUI::LOCALES_OVERWRITE, Cache::INNER_OUTER, 3600);
        $cache->rem();

        $request_api = RequestApi::createFromRequest(new Request());
        $controller = new CLocalesController();
        $response = $controller->listLocales('fr', 'system', $request_api);

        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('locales', $content['data']['type']);

        $this->assertEquals($new_trans, $content['data']['attributes']['Aggregate']);

        $translation->delete();
    }
}
