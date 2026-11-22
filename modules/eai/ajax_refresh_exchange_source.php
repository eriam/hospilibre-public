<?php
/**
 * @package Hospilibre\Eai
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CMbObject;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Hospilibre\System\CExchangeSource;
use Hospilibre\System\CSourcePOP;

$source_guid = CView::get("source_guid", "guid class|CExchangeSource");
CView::checkin();

/** @var CExchangeSource $source */
$source = CMbObject::loadFromGuid($source_guid);
if ($source instanceof CSourcePOP) {
  $source->loadRefMetaObject();
}

$smarty = new CSmartyDP();
$smarty->assign("_source", $source);
$smarty->display("inc_vw_source.tpl");