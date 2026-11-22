<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Controllers;

use Exception;
use Ox\Core\Api\Resources\Item;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CController;
use Symfony\Component\HttpFoundation\Response;

class CSystemController extends CController
{

    /**
     * @throws Exception
     */
    public function offline(string $message): Response
    {
        $external_url = CApp::getBaseUrl();
        $base_href    = str_ends_with($external_url, '/') ? $external_url : $external_url . '/';

        $root_dir  = $this->getRootDir();
        $path      = "./images/pictures";
        $bg_custom = "./images/pictures/bg_custom.jpg";

        $vars     = [
            "bg_custom"   => $bg_custom,
            "bg"          => is_file($bg_custom),
            "src_logo"    => (file_exists(
                "$root_dir/$path/logo_custom.png"
            ) ? "$path/logo_custom.png" : "$path/logo.png"),
            "message"     => $message,
            "application" => CAppUI::conf("product_name"),
            "base_href"   => $base_href,
        ];
        $response = $this->render('offline.html.twig', $vars);

        $headers = [
            "Retry-After"  => 300,
            "Content-Type" => "text/html; charset=utf-8",
        ];
        $response->headers->add($headers);

        return $response;
    }

    /**
     * @api public
     */
    public function status()
    {
        [$header, $status] = explode(':', CApp::getProxyHeader());

        $resource = new Item(
            [
                'status'  => $status,
                'version' => (string)CApp::getVersion(),
                'release' => CApp::getVersion()->getCode() ?? 'undefined',
                'date'    => CApp::getVersion()->getCompleteDate() ?? 'undefined',
            ]
        );

        $resource->setType('api_status');

        return $this->renderApiResponse($resource, 200, [$header => $status]);
    }
}
