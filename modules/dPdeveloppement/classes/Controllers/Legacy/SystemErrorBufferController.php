<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Developpement\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CError;
use Ox\Core\CLegacyController;
use Ox\Core\CMbPath;
use Ox\Core\CMbString;
use Ox\Core\CView;
use Ox\Core\Mutex\CMbMutex;

class SystemErrorBufferController extends CLegacyController
{
    public function ajax_list_error_log_buffer(): void
    {
        $this->checkPermRead();

        $paths        = CError::globWaitingBuffer();
        $array_mapped = array_map('filemtime', $paths);
        array_multisort($array_mapped, SORT_NUMERIC, SORT_DESC, $paths);

        $files = [];
        foreach ($paths as $path) {
            $files[$path] = [
                'name'  => basename($path),
                'time'  => filemtime($path),
                'size'  => CMbString::toDecaBinary(filesize($path)),
                'lines' => CMbPath::countLines($path),
            ];
        }

        $this->renderSmarty(
            "inc_list_error_log_buffer",
            [
                "files" => $files,
            ]
        );
    }

    public function ajax_save_error_buffer(): void
    {
        $this->checkPermEdit();

        $buffer_file = CView::get("buffer_file", "str");
        CView::checkin();

        $root_dir        = CAppUI::conf("root_dir");
        $path_tmp_buffer = CError::PATH_TMP_BUFFER;

        // ressources
        if ($buffer_file === 'all') {
            $files = CError::globWaitingBuffer();
        } else {
            $file  = $root_dir . $path_tmp_buffer . $buffer_file;
            $files = [$file];
        }
        $nb_file = count($files);

        // lock
        $lock = new CMbMutex("CError-file-buffer");
        if (!$lock->lock(60)) {
            CAppUI::stepAjax("CError-msg-locked");

            return;
        }

        // store
        foreach ($files as $_key => $_file) {
            if (file_exists($_file) && is_writable($_file)) {
                $retour = CError::storeBuffer($_file, false);
                if ($retour) {
                    unset($files[$_key]);
                }
            }
        }

        $lock->release();

        // noty
        $nb_file_after = count($files);
        if ($nb_file_after === 0) {
            CAppUI::displayAjaxMsg("CError-msg-%s files processed", UI_MSG_OK, $nb_file);
        } else {
            CAppUI::displayAjaxMsg("CError-error-Failed to process %s files", UI_MSG_ERROR, $nb_file_after);
        }

        CAppUI::callbackAjax('Control.Modal.refresh');

        CApp::rip();
    }

    public function ajax_delete_error_buffer(): void
    {
        $this->checkPermEdit();

        $buffer_file = CView::get("buffer_file", "str");
        CView::checkin();

        $root_dir        = CAppUI::conf("root_dir");
        $path_tmp_buffer = CError::PATH_TMP_BUFFER;

        // ressources
        if ($buffer_file === 'all') {
            $files = CError::globWaitingBuffer();
        } else {
            $file  = $root_dir . $path_tmp_buffer . $buffer_file;
            $files = [$file];
        }
        $nb_file = count($files);

        // lock
        $lock = new CMbMutex("CError-file-buffer");
        if (!$lock->lock(60)) {
            CAppUI::stepAjax("CError-msg-locked");

            return;
        }

        // unlink
        foreach ($files as $_key => $_file) {
            if (file_exists($_file) && is_writable($_file)) {
                if (unlink($_file)) {
                    unset($files[$_key]);
                }
            }
        }

        $lock->release();

        // noty
        $nb_file_after = count($files);
        if ($nb_file_after === 0) {
            CAppUI::displayAjaxMsg("CError-msg-%s files deleted", UI_MSG_OK, $nb_file);
        } else {
            CAppUI::displayAjaxMsg("CError-error-Failed to delete %s files", UI_MSG_ERROR, $nb_file_after);
        }

        CAppUI::callbackAjax('Control.Modal.refresh');

        CApp::rip();
    }
}
