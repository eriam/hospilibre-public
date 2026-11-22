<?php
/**
 * @package Hospilibre\Ftp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Check params
use Ox\Core\CAppUI;
use Ox\Interop\Ftp\CFTP;
use Ox\Core\CMbException;
use Ox\Core\CSmartyDP;
use Ox\Core\CValue;
use Ox\Interop\Ftp\CSourceFTP;
use Hospilibre\System\CExchangeSource;

if (null == $exchange_source_name = CValue::get("exchange_source_name")) {
  CAppUI::stepAjax("Aucun nom de source d'échange spécifié", UI_MSG_ERROR);
}

$exchange_source = CExchangeSource::get($exchange_source_name, CSourceFTP::TYPE, true, null, false);

$ftp = new CFTP();
$ftp->init($exchange_source);

try {
  $ftp->connect();
  CAppUI::stepAjax("Connecté au serveur $ftp->hostname et authentifié en tant que $ftp->username");
} catch (CMbException $e) {
  $e->stepAjax();
  return;
}

if ($ftp->passif_mode) {
  CAppUI::stepAjax("Activation du mode passif");
}

try {
    if($ftp->fileprefix == null){
        $ftp->fileprefix = "";
    }
  $files = $ftp->getListFilesDetails($ftp->fileprefix);
} catch (CMbException $e) {
  $e->stepAjax();
  return;
}

// Création du template
$smarty = new CSmartyDP();

$smarty->assign("exchange_source", $exchange_source);
$smarty->assign("files", $files);

$smarty->display("inc_ftp_files.tpl");
