<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Ccam\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CDoObjectAddEdit;
use Ox\Core\CLegacyController;
use Ox\Core\CMbObject;
use Ox\Core\CValue;
use Ox\Core\CView;
use Ox\Core\FileUtil\CCSVFile;
use Hospilibre\Admin\CUser;
use Hospilibre\Ccam\CCodeCCAM;
use Hospilibre\Ccam\CDatedCodeCCAM;
use Hospilibre\Ccam\CFavoriCCAM;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\SalleOp\CActeCCAM;
use Hospilibre\System\CTag;
use Hospilibre\System\CTagItem;

class FavorisLegacyController extends CLegacyController
{
    public function viewFavoris(): void
    {
        $this->checkPermRead();

        $filterClass = CView::get("_filter_class", 'str');
        $rawTagId    = CView::get("tag_id", 'num');

        CView::checkin();

        $tagId = ('' !== $rawTagId) ? (int)$rawTagId : null;

        $currentUser = CUser::get();

        // Favoris déduits de l'historique des actes réalisés.
        $groupedByChapter = [];

        if (!$tagId) {
            $acteCcam = new CActeCCAM();
            $usageRows = $acteCcam->getFavoris($currentUser->_id, $filterClass);

            foreach ($usageRows as $row) {
                $datedCode = CDatedCodeCCAM::get($row["code_acte"]);
                $datedCode->getChaps();

                $datedCode->favoris_id = 0;
                $datedCode->occ        = $row["nb_acte"];
                $datedCode->class      = $row["object_class"];

                $firstChapter =& $datedCode->chapitres[0];

                if (!array_key_exists($firstChapter['code'], $groupedByChapter)) {
                    $groupedByChapter[$firstChapter['code']] = [
                        'nom'   => $firstChapter['code'],
                        'codes' => [],
                    ];
                }

                $groupedByChapter[$firstChapter["code"]]["codes"][$row["code_acte"]] = $datedCode;
            }
        }

        // Copie de départ pour la fusion avec les favoris déclarés.
        $merged = $groupedByChapter;

        $declaredByChapter = CFavoriCCAM::getOrdered($currentUser->_id, $filterClass, true, $tagId);

        // Fusion des deux jeux de données (historique + déclarés).
        foreach ($declaredByChapter as $chapterKey => $chapterData) {
            if (!array_key_exists($chapterKey, $merged)) {
                $merged[$chapterKey] = $chapterData;
                continue;
            }

            foreach ($chapterData["codes"] as $codeKey => $codeEntry) {
                if (!array_key_exists($codeKey, $merged[$chapterKey]["codes"])) {
                    $merged[$chapterKey]["codes"][$codeKey] = $codeEntry;
                    continue;
                }

                // Référence vers le favori afin de pouvoir y attacher des tags.
                $merged[$chapterKey]["codes"][$codeKey]->favoris_id  = $codeEntry->favoris_id;
                $merged[$chapterKey]["codes"][$codeKey]->_ref_favori = $codeEntry->_ref_favori;
            }
        }

        $tagTree = CFavoriCCAM::getTree($currentUser->_id);

        $favori                = new CFavoriCCAM();
        $favori->_filter_class = $filterClass;

        $this->renderSmarty('vw_idx_favoris.tpl', [
            'favoris'     => $favori,
            'list'        => $groupedByChapter,
            'fusion'      => $merged,
            'codesByChap' => $declaredByChapter,
            'tag_tree'    => $tagTree,
            'tag_id'      => $tagId,
        ]);
    }

    public function countFavoris(): void
    {
        $this->checkPermAdmin();

        $userId     = CView::get('user_id', 'ref class|CMediusers');
        $functionId = CView::get('function_id', 'ref class|CFunctions');

        CView::checkin();

        $favori = new CFavoriCCAM();

        if ($userId) {
            $favori->favoris_user = $userId;
        } elseif ($functionId) {
            $favori->favoris_function = $functionId;
        }

        $total = $favori->countMatchingList();

        $payload = ($userId || $functionId) ? ['count' => $total] : ['count' => 0];

        $this->renderJson($payload);
    }

    public function storeFavoris(): void
    {
        $doObject = new CDoObjectAddEdit("CFavoriCCAM", "favoris_id");

        CView::checkin();

        // Enrichissement des libellés de confirmation.
        $ownerUser     = CView::post("favoris_user", 'ref class|CUser');
        $ownerFunction = CView::post('favoris_function', 'ref class|CFunctions');

        if ($ownerUser) {
            $mediuser = new CMediusers();
            $mediuser->load($ownerUser);
            $suffix = " pour $mediuser->_view";
            $doObject->createMsg .= $suffix;
            $doObject->modifyMsg .= $suffix;
            $doObject->deleteMsg .= $suffix;
        } elseif ($ownerFunction) {
            $function = new CFunctions();
            $function->load($ownerFunction);
            $suffix = " pour $function->_view";
            $doObject->createMsg .= $suffix;
            $doObject->modifyMsg .= $suffix;
            $doObject->deleteMsg .= $suffix;
        }

        $doObject->redirect = null;

        $doObject->doIt();

        if (CAppUI::pref("new_search_ccam") == 1) {
            echo CAppUI::getMsg();
            CApp::rip();
        }
    }

    public function exportFavoris(): void
    {
        $this->checkPermAdmin();

        $userId     = CView::post('user_id', 'ref class|CMediusers');
        $functionId = CView::post('function_id', 'ref class|CFunctions');

        CView::checkin();

        $favori = new CFavoriCCAM();

        $ownerClass = '';
        $ownerId    = '';
        $ownerName  = '';

        if ($userId) {
            $favori->favoris_user = $userId;
            $ownerClass           = 'CMediusers';
            $ownerId              = $userId;
            $mediuser             = CMediusers::get($userId);
            $ownerName            = "$mediuser->_user_last_name $mediuser->_user_first_name";
        } elseif ($functionId) {
            $favori->favoris_function = $functionId;
            $ownerClass               = 'CFunctions';
            $ownerId                  = $functionId;
            /** @var CFunctions $function */
            $function  = CMbObject::loadFromGuid("CFunctions-$functionId");
            $ownerName = $function->text;
        }

        /** @var CFavoriCCAM[] $favorisList */
        $favorisList = $favori->loadMatchingList();
        $tagItems    = CMbObject::massLoadBackRefs($favorisList, 'tag_items');
        CMbObject::massLoadFwdRef($tagItems, 'tag_id');

        $csv = new CCSVFile();

        $csv->writeLine([
            'Type',
            'Propriétaire',
            'Tag',
            'Rang',
            'Code',
            'Type objet',
        ]);

        foreach ($favorisList as $currentFavori) {
            $currentFavori->loadRefsTagItems();

            $tagLabels = [];
            foreach ($currentFavori->_ref_tag_items as $tagItem) {
                $tagLabels[] = $tagItem->_view;
            }

            $csv->writeLine([
                $ownerClass,
                $ownerId,
                implode('|', $tagLabels),
                $currentFavori->rang,
                $currentFavori->favoris_code,
                $currentFavori->object_class,
            ]);
        }

        $csv->stream('favoris_ccam_' . str_replace(' ', '_', $ownerName));
        CApp::rip();
    }

    public function importFavoris(): void
    {
        $this->checkPermAdmin();

        $userId       = CView::post('user_id', 'ref class|CMediusers');
        $functionId   = CView::post('function_id', 'ref class|CFunctions');
        $uploadedFile = CValue::files('formfile');

        CView::checkin();

        if (!array_key_exists('tmp_name', $uploadedFile) || $uploadedFile['tmp_name'][0] == '') {
            CAppUI::setMsg('Aucun fichier sélectionné', UI_MSG_ERROR);
            CAppUI::getMsg();
            CApp::rip();
        }

        $csv = new CCSVFile($uploadedFile['tmp_name'][0]);

        $importedCount = 0;
        $errorCount    = 0;

        if ($csv->countLines()) {
            $csv->setColumnNames(['owner_type', 'owner_id', 'tags', 'rank', 'code', 'object_class']);

            $csv->jumpLine(1);

            while ($line = $csv->readLine(true, true)) {
                $favori = new CFavoriCCAM();

                if ($userId) {
                    $favori->favoris_user = $userId;
                } elseif ($functionId) {
                    $favori->favoris_user = $functionId;
                } elseif (in_array(strtolower($line['owner_type']), ['user', 'utilisateur', 'cuser'])) {
                    $favori->favoris_user = $line['owner_id'];
                } elseif (in_array(strtolower($line['owner_type']), ['fonction', 'function', 'cfunctions'])) {
                    $favori->favoris_user = $line['owner_id'];
                }

                if ($line['rank']) {
                    $favori->rang = $line['rank'];
                }

                $code = CCodeCCAM::get(trim($line['code']));
                if ($code->code == '-') {
                    $errorCount++;
                    continue;
                }

                $favori->favoris_code = $code->code;

                if ($line['object_class']) {
                    if (in_array(strtolower($line['object_class']), ['consultation', 'consult', 'cconsultation'])) {
                        $favori->object_class = 'CConsultation';
                    }
                    if (in_array(strtolower($line['object_class']), ['sejour', 'csejour'])) {
                        $favori->object_class = 'CSejour';
                    } else {
                        $favori->object_class = 'COperation';
                    }
                }

                $favori->loadMatchingObject();

                if ($favori->store()) {
                    $errorCount++;
                    continue;
                }

                if ($line['tags']) {
                    $tagNames = explode('|', $line['tags']);

                    foreach ($tagNames as $tagName) {
                        $parentTag = false;

                        /* Prise en charge des tags parents. */
                        if (strpos($tagName, '&raquo;')) {
                            [$parentName, $tagName] = explode(' &raquo; ', $tagName);
                            $parentTag               = new CTag();
                            $parentTag->name         = $parentName;
                            $parentTag->object_class = $favori->_class;
                            $parentTag->loadMatchingObject();
                            if ($parentTag->store()) {
                                continue;
                            }
                        }

                        $tag               = new CTag();
                        $tag->name         = $tagName;
                        $tag->object_class = $favori->_class;

                        if ($parentTag) {
                            $tag->parent_id = $parentTag->_id;
                        }

                        $tag->loadMatchingObject();
                        if ($tag->store()) {
                            continue;
                        }

                        $tagItem               = new CTagItem();
                        $tagItem->tag_id       = $tag->_id;
                        $tagItem->object_id    = $favori->_id;
                        $tagItem->object_class = $favori->_class;

                        $tagItem->loadMatchingObject();
                        $tagItem->store();
                    }
                }

                $importedCount++;
            }
        }

        $csv->close();

        if ($errorCount) {
            CAppUI::setMsg("$errorCount favoris en erreur", UI_MSG_ERROR);
        }

        if ($importedCount) {
            CAppUI::setMsg("$importedCount favoris importés", UI_MSG_OK);
        }

        echo CAppUI::getMsg();
    }
}
