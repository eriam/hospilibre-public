<?php
/**
 * @package Hospilibre\Admin
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CMbException;
use Ox\Core\CMbString;
use Ox\Core\CSmartyDP;
use Ox\Core\CValue;
use Hospilibre\Admin\CLDAP;
use Hospilibre\Admin\CUser;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;

CCanDo::checkRead();

$object_id = CValue::get("object_id");

$mediuser = new CMediusers();
$mediuser->load($object_id);
$user = $mediuser->_ref_user;

try {
    $chain_ldap = CLDAP::poolConnect(null, CGroups::loadCurrent()->_id);

    if ($chain_ldap === false || $chain_ldap->areUnreachable()) {
        CAppUI::stepAjax('CSourceLDAP_all-unreachable', UI_MSG_ERROR);
    }

    // Ne pas mettre de retours chariots
    $filter = "(|(givenname=" . CLDAP::escape($mediuser->_user_first_name) . "*)(sn=" . CLDAP::escape(
            $mediuser->_user_last_name
        ) . "*)(samaccountname=" . CLDAP::escape($mediuser->_user_username) . "*))";

    $alternative_filter = "(|(givenname=" . CLDAP::escape($mediuser->_user_first_name) . "*)(sn=" . CLDAP::escape(
            $mediuser->_user_last_name
        ) . "*)(cn=" . CLDAP::escape($mediuser->_user_username) . "*))";

    $filter             = utf8_encode($filter);
    $alternative_filter = utf8_encode($alternative_filter);

    $chain_ldap->bind(true);

    $results_by_source = $chain_ldap->filter($filter, $alternative_filter);
} catch (CMbException $e) {
    $e->stepAjax(UI_MSG_ERROR);
}

$nb_users = 0;
$nb_users = $results_by_source["count"];
unset($results_by_source["count"]);

$users = [];

foreach ($results_by_source as $_source_id => $_results) {
    unset($_results['count']);

    foreach ($_results as $key => $_result) {
        $_source = $chain_ldap->getBoundSourceById($_source_id);

        $objectguid                = CLDAP::getObjectGUID($_result, $_source);
        $users[$key]["objectguid"] = $objectguid;

        $users[$key]["user_username"] =
            $_source->isAlternativeBinding()
                ? CLDAP::getValue($_result, 'cn')
                : CLDAP::getValue($_result, "samaccountname");

        $users[$key]["user_first_name"] = CLDAP::getValue($_result, "givenname");
        $users[$key]["user_last_name"]  = CLDAP::getValue($_result, "sn");
        $users[$key]["actif"]           = (CLDAP::getValue($_result, 'useraccountcontrol') & 2) ? 0 : 1;

        $_user = CUser::loadFromLdapUid($objectguid);

        $users[$key]["associate"] = ($_user && $_user->_id) ? $_user->_id : null;
    }
}

$smarty = new CSmartyDP();
$smarty->assign("users", $users);
$smarty->assign("mediuser", $mediuser);
$smarty->assign("nb_users", $nb_users);
$smarty->assign("givenname", CMbString::capitalize($mediuser->_user_first_name));
$smarty->assign("sn", strtoupper($mediuser->_user_last_name));
$smarty->assign("samaccountname", strtolower($mediuser->_user_username));
$smarty->assign("close_modal", '1');
$smarty->display("inc_search_user_ldap.tpl");
