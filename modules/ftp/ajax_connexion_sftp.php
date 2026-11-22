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
$exchange_source = CExchangeSource::get($exchange_source_name, CSourceSFTP::TYPE, false, null, false);
if (!$exchange_source->_id) {
    CAppUI::stepMessage(UI_MSG_ERROR, "CExchangeSource-no-source", $exchange_source_name);
}

$connection = false;
try {
    $client = $exchange_source->getClient();
    if ($connection = $client->isReachableSource()) {
        CAppUI::stepMessage(E_USER_NOTICE, "CSFTP-success-connection", $exchange_source->host, $exchange_source->port);

        if ($connection = $client->isAuthentificate()) {
            CAppUI::stepMessage(E_USER_NOTICE, "CSFTP-success-authentification", $exchange_source->user);

            $sent_file   = CAppUI::conf('root_dir') . "/ping.php";
            $remote_file = $exchange_source->fileprefix . "test.txt";

            // prefix already given and we don't want use prefix like a directory
            $exchange_source->fileprefix = null;

            $client->addFile($remote_file, $sent_file);
            CAppUI::stepMessage(E_USER_NOTICE, "CSFTP-success-transfer_out", $sent_file, $remote_file);

            $get_file = "tmp/ping.php";
            $client->getData($remote_file);
            CAppUI::stepMessage(E_USER_NOTICE, "CSFTP-success-transfer_in", $remote_file, $get_file);

            $client->delFile($remote_file);
            CAppUI::stepMessage(E_USER_NOTICE, "CFTP-success-deletion", $remote_file);
        }
    }
} catch (CMbException $e) {
    $e->stepAjax();
    if (count($client->connexion->getSFTPErrors())) {
        CAppUI::stepMessage(UI_MSG_ERROR, $client->connexion->getLastSFTPError());
    }
}

if (!$connection && $exchange_source->_message) {
    CAppUI::stepMessage(UI_MSG_ERROR, $exchange_source->_message);
} elseif (!$connection) {
    CAppUI::stepMessage(
        UI_MSG_ERROR,
        'CSourceSFTP-connexion-failed',
        "{$exchange_source->host}:{$exchange_source->port}"
    );
}

CApp::rip();
