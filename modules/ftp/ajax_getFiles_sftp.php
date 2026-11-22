<?php
/**
 * @package Hospilibre\Ftp
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CMbException;
use Ox\Core\CSmartyDP;
use Ox\Core\CView;
use Ox\Interop\Ftp\CSourceSFTP;
use Hospilibre\System\CExchangeSource;

CCanDo::check();

// Check params
$exchange_source_name = CView::get("exchange_source_name", "str");
CView::checkin();

if ($exchange_source_name == null) {
    CAppUI::stepMessage(UI_MSG_ERROR, "CSourceFTP-no-source", $exchange_source_name);
}

/** @var CSourceSFTP $exchange_source */
$exchange_source = CExchangeSource::get($exchange_source_name, CSourceSFTP::TYPE, true, null, false);
if (!$exchange_source->_id) {
    CAppUI::stepMessage(UI_MSG_ERROR, "CExchangeSource-no-source", $exchange_source_name);
}

$connection = false;
try {
    if ($connection = $exchange_source->getClient()->isAuthentificate()) {
        CAppUI::stepMessage(UI_MSG_OK, "CSFTP-success-connection", $exchange_source->host, $exchange_source->user);

        $files = $exchange_source->getClient()->getListFilesDetails($exchange_source->fileprefix, true);
    }
} catch (CMbException $e) {
    $e->stepMessage();
    CAppUI::stepMessage(UI_MSG_ERROR, $exchange_source->getClient()->getError());
}

if ($connection) {
    // Création du template
    $smarty = new CSmartyDP();
    $smarty->assign("exchange_source", $exchange_source);
    $smarty->assign("files", $files);
    $smarty->display("inc_ftp_files.tpl");
} elseif ($exchange_source->_message) {
    CAppUI::stepMessage(UI_MSG_ERROR, $exchange_source->_message);
} else {
    CAppUI::stepMessage(
        UI_MSG_ERROR,
        'CSourceSFTP-connexion-failed',
        "{$exchange_source->host}:{$exchange_source->port}"
    );
}
