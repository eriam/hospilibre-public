<?php
/**
 * @package Hospilibre\Files
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CMbDT;
use Ox\Core\Module\CModule;
use Ox\Core\CSmartyDP;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Core\CValue;
use Ox\Core\CView;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\Files\CDocumentItem;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;

CCanDo::checkAdmin();

// Get Concrete class
$doc_class = CValue::get("doc_class", "CFile");
if (!is_subclass_of($doc_class, CDocumentItem::class)) {
  trigger_error("Wrong '$doc_class' won't inerit from CDocumentItem", E_USER_ERROR);
  return;
}

CView::enableSlave();

$func = new CFunctions();

/** @var CDocumentItem $doc */
$doc = new $doc_class;
$users_stats = $doc->getUsersStats();
$funcs_stats = array();
$groups_stats = array();

$total = array(
  "docs_weight" => 0,
  "docs_count" => 0,
);

$is_doc = get_class($doc) === CCompteRendu::class;

if ($is_doc) {
    $total['docs_read_time']  = 0;
    $total['docs_write_time'] = 0;
}

if (CModule::getActive("mediusers")) {
  $users_ids = array();
  foreach ($users_stats as $_stat_user) {
    $users_ids[] = $_stat_user["owner_id"];
  }

  $user = new CMediusers();
  $users = $user->loadList(array("user_id" => CSQLDataSource::prepareIn($users_ids)));
  CStoredObject::massLoadFwdRef($users, "function_id");
}

// Stat per user
foreach ($users_stats as &$_stat_user) {
  $total["docs_weight"] += $_stat_user["docs_weight"];
  $total["docs_count"]  += $_stat_user["docs_count"];

  if ($is_doc) {
      $total['docs_read_time'] += $_stat_user['docs_read_time'];
      $total['docs_write_time'] += $_stat_user['docs_write_time'];
  }

  $_stat_user["_docs_average_weight"] = $_stat_user["docs_count"] ?
      ($_stat_user["docs_weight"] / $_stat_user["docs_count"]) : 0;

  if ($is_doc) {
      $_stat_user['_docs_average_read_time'] = $_stat_user["docs_count"] ?
          ($total['docs_read_time'] / $_stat_user["docs_count"]) : 0;
      $_stat_user['_docs_average_write_time'] = $_stat_user["docs_count"] ?
          ($total['docs_write_time'] / $_stat_user["docs_count"]) : 0;
  }

  // Make it mediusers uninstalled compliant
  if (CModule::getActive("mediusers")) {
    // Get the owner
    $user = CMediusers::get($_stat_user["owner_id"]);
    $_stat_user["_ref_owner"] = $user;

    if (!$user->_id) {
      continue;
    }

    // Initialize function data
    $function = $user->loadRefFunction();
    if (!isset($funcs_stats[$function->_id])) {
      $funcs_stats[$function->_id] = array(
        "docs_weight" => 0,
        "docs_count"  => 0,
        "_ref_owner"  => $function,
      );

      if ($is_doc) {
          $funcs_stats[$function->_id]['docs_read_time'] = 0;
          $funcs_stats[$function->_id]['docs_write_time'] = 0;
      }
    }

    // Cummulate data per function
    $stat_func =& $funcs_stats[$function->_id];
    $stat_func["docs_weight"] += $_stat_user["docs_weight"];
    $stat_func["docs_count" ] += $_stat_user["docs_count" ];

    if ($is_doc) {
        $stat_func['docs_read_time'] += $_stat_user['docs_read_time'];
        $stat_func['docs_write_time'] += $_stat_user['docs_write_time'];
    }

    // Initialize group data
    $group = $function->loadRefGroup();
    if (!isset($groups_stats[$group->_id])) {
      $groups_stats[$group->_id] = array(
        "docs_weight" => 0,
        "docs_count"  => 0,
        "_ref_owner"  => $group,
      );

      if ($is_doc) {
          $groups_stats[$group->_id]['docs_read_time'] = 0;
          $groups_stats[$group->_id]['docs_write_time'] = 0;
      }
    }

    // Cummulate data per group
    $stat_group =& $groups_stats[$group->_id];
    $stat_group["docs_weight"] += $_stat_user["docs_weight"];
    $stat_group["docs_count" ] += $_stat_user["docs_count" ];

    if ($is_doc) {
        $stat_group['docs_read_time'] += $_stat_user['docs_read_time'];
        $stat_group['docs_write_time'] += $_stat_user['docs_write_time'];
    }
  }
}

// Get user data percentages
foreach ($users_stats as &$_stat_user) {
  $_stat_user["_docs_weight_percent"] = $total["docs_weight"] ?
      ($_stat_user["docs_weight"] / $total["docs_weight"]) : 0;
  $_stat_user["_docs_count_percent"] = $total["docs_count"] ? ($_stat_user["docs_count"] / $total["docs_count"]) : 0;
}

// Get function data percentages
foreach ($funcs_stats as $function_id => &$_stat_func) {
  $_stat_func["_docs_weight_percent"] = $total["docs_weight"] ?
      ($_stat_func["docs_weight"] / $total["docs_weight"]) : 0;
  $_stat_func["_docs_count_percent"] = $total["docs_count"] ? ($_stat_func["docs_count" ] / $total["docs_count"]) : 0;
  $_stat_func["_docs_average_weight"] = $_stat_func["docs_count"] ?
      ($_stat_func["docs_weight"] / $_stat_func["docs_count"]) : 0;
}

// Get function data percentages
foreach ($groups_stats as $group_id => &$_stat_group) {
  $_stat_group["_docs_weight_percent"] = $total["docs_weight"] ?
      ($_stat_group["docs_weight"] / $total["docs_weight"]) : 0;
  $_stat_group["_docs_count_percent" ] = $total["docs_count"] ? ($_stat_group["docs_count"] / $total["docs_count"]) : 0;
  $_stat_group["_docs_average_weight"] = $_stat_group["docs_count"] ?
      ($_stat_group["docs_weight"] / $_stat_group["docs_count"]) : 0;
}

$total["_docs_average_weight"] = $total["docs_count"] ? ($total["docs_weight"] / $total["docs_count"]) : 0;

if ($is_doc) {
    $total['docs_read_time'] = CMbDT::friendlyDuration($total['docs_read_time'])['locale'];
    $total['docs_write_time'] = CMbDT::friendlyDuration($total['docs_write_time'])['locale'];

    foreach ($users_stats as $_key => $_user_stats) {
        $users_stats[$_key]['docs_read_time'] = CMbDT::friendlyDuration($_user_stats['docs_read_time'])['locale'];
        $users_stats[$_key]['docs_write_time'] = CMbDT::friendlyDuration($_user_stats['docs_write_time'])['locale'];
    }

    foreach ($funcs_stats as $_key => $_func_stats) {
        $funcs_stats[$_key]['docs_read_time'] = CMbDT::friendlyDuration($_func_stats['docs_read_time'])['locale'];
        $funcs_stats[$_key]['docs_write_time'] = CMbDT::friendlyDuration($_func_stats['docs_write_time'])['locale'];
    }

    foreach ($groups_stats as $_key => $_group_stats) {
        $groups_stats[$_key]['docs_read_time'] = CMbDT::friendlyDuration($_group_stats['docs_read_time'])['locale'];
        $groups_stats[$_key]['docs_write_time'] = CMbDT::friendlyDuration($_group_stats['docs_write_time'])['locale'];
    }
}

// Création du template
$smarty = new CSmartyDP();
$smarty->assign("doc_class", $doc_class);
$smarty->assign("users_stats", $users_stats);
$smarty->assign("funcs_stats", $funcs_stats);
$smarty->assign("groups_stats", $groups_stats);
$smarty->assign("total", $total);
$smarty->assign('is_doc', $is_doc);
$smarty->display("vw_stats.tpl");
