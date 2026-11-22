<?php

/**
 * @package Hospilibre\Admin
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Admin\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CRequest;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\Admin\CUser;
use Hospilibre\System\CUserAuthentication;
use Hospilibre\System\CUserAuthenticationError;

/**
 * Description
 */
class CUserAuthenticationLegacyController extends CLegacyController
{
    public const AUTH_PURGE_COUNT = 100000;
    public const AUTH_PAGINATION  = 30;

    public function ajax_vw_user_authentications_success(): void
    {
        $this->displayUserAuthentications(
            'authentications',
            'countConnections',
            'datetime_login',
            'inc_vw_user_authentications_success',
            true
        );
    }

    public function ajax_vw_user_authentications_errors(): void
    {
        $this->displayUserAuthentications(
            'authentication_errors',
            'countConnectionsErrors',
            'datetime',
            'inc_vw_user_authentications_errors'
        );
    }

    private function displayUserAuthentications(
        string $back_name,
        string $load_back_func,
        string $datetime_field,
        string $template_name,
        bool $load_user_agent = false
    ): void {
        $this->checkPermEdit();

        // Récuperation de l'utilisateur sélectionné
        $user_id = CView::getRefCheckRead("user_id", "ref class|CMediusers notNull");
        $total   = CView::get('total', 'num');
        $start   = CView::get("start", "num default|0");

        CView::checkin();

        CView::enforceSlave(false);

        $user = CUser::get($user_id);

        if ($total) {
            $user->_count[$back_name] = $total;
        } else {
            $user->{$load_back_func}();
        }

        $auth_list = $user->loadBackRefs(
            $back_name,
            $datetime_field . ' DESC',
            intval($start) . ',' . self::AUTH_PAGINATION
        );

        if ($load_user_agent) {
            CStoredObject::massLoadFwdRef($auth_list, 'user_agent_id');

            foreach ($auth_list as $_list) {
                $_list->loadRefUserAgent();
            }
        }

        $this->renderSmarty(
            $template_name,
            [
                'list' => $auth_list,
                'user' => $user,
            ]
        );
    }

    public function ajax_vw_user_authentications(): void
    {
        $this->checkPermEdit();

        // Récuperation de l'utilisateur sélectionné
        $user_id = CView::getRefCheckRead("user_id", "ref class|CMediusers notNull");

        CView::checkin();

        CView::enforceSlave(false);

        $user = CUser::get($user_id);
        $user->countConnections();
        $user->countConnectionsErrors();

        $this->renderSmarty(
            'inc_vw_user_authentications',
            [
                'user' => $user,
            ]
        );
    }

    public function do_user_authentication_purge(): void
    {
        $this->checkPermAdmin();

        $user_id = CView::postRefCheckEdit('user_id', 'ref class|CUser notNull');
        $error   = CView::post('error', 'bool default|0');

        CView::checkin();

        $user = CUser::get($user_id);

        if (!$user->_id || !$user->dont_log_connection) {
            CAppUI::stepAjax('CUser-msg-Cannot purge real user authentications', UI_MSG_ERROR);
        }

        $auth = ($error) ? new CUserAuthenticationError() : new CUserAuthentication();
        $ds   = $auth->getDS();

        $request = new CRequest();
        $request->addWhere(
            [
                'user_id' => $ds->prepare('= ?', $user_id),
            ]
        );
        $request->setLimit(self::AUTH_PURGE_COUNT);

        $ds->exec($request->makeDelete($auth));

        $count = $ds->affectedRows();

        CAppUI::stepAjax(
            ($error) ? 'CUser-msg-Count authentication errors deleted' : 'CUser-msg-Count authentications deleted',
            UI_MSG_OK,
            number_format($count, 0, ',', ' ')
        );

        CAppUI::js("UserAuth.updateAfterPurge('$count', '{$error}', '{$user_id}')");

        CApp::rip();
    }
}
