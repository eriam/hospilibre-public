<?php
/**
 * @package Hospilibre\Mediusers
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CValue;
use Hospilibre\Admin\CUser;
use Hospilibre\Mediusers\Api\CAPITools;
use Hospilibre\Mediusers\CMediusers;

CCanDo::checkRead();

$prettify = CValue::get('prettify');

$user = CMediusers::get();

$profile  = new CUser();
$where    = array(
  'template' => "= '1'"
);
$profiles = $profile->loadListWithPerms(PERM_READ, $where);

uasort(
  $profiles,
  function ($a, $b) {
    return strcmp($a->_view, $b->_view);
  }
);

$api_objects = array(
  'account'  => $user->_view,
  'profiles' => array()
);

/** @var CUser $_profil */
foreach ($profiles as $_profil) {
  $_api_profil = array(
    'id'  => $_profil->_id,
    'nom' => trim($_profil->_view),
  );

  $api_objects['profiles'][] = array(
    'profil' => $_api_profil,
  );
}

CAPITools::json($api_objects, 200, $prettify);