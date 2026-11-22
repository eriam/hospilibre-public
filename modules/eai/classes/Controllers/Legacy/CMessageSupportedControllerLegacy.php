<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Eai\Controllers\Legacy;


use Ox\Core\CAppUI;
use Ox\Core\CClassMap;
use Ox\Core\CLegacyController;
use Ox\Core\CView;
use Ox\Interop\Eai\CInteropNorm;
use Ox\Interop\Eai\CMessageSupported;

class CMessageSupportedControllerLegacy extends CLegacyController
{
    public function messageRefreshSectionVersion(): void
    {
        $familyClass = CView::get("family", "str");

        /** @var CInteropNorm $family */
        $family = new $familyClass();

        $supported               = new CMessageSupported();
        $supported->object_id    = CView::get("object_id", "str");
        $supported->object_class = CView::get("object_class", "str");
        $supported->transaction  = CView::get("transaction", "str");
        $supported->profil       = CView::get("profil", "str");
        $supported->message      = CView::get("message", "str");
        CView::checkin();

        $shortName = CClassMap::getInstance()->getShortName($family);

        $this->renderSmarty(
            'inc_message_supported_section_version',
            [
                '_families'          => $family,
                '_family_name'       => $shortName,
                '_category_name'     => $supported->transaction,
                '_message_supported' => $supported,
            ]
        );
    }

    public function updateMessageSupported(): void
    {
        $newTransaction  = CView::get("transaction", "str");
        $previousTxn     = CView::get("old_transaction", "str");
        $targetVersion   = CView::get("version", "str");

        // Lorsqu'une transaction differente est fournie en ancienne valeur,
        // on filtre la selection sur cette derniere avant de la renommer.
        $hasRename = $previousTxn && $previousTxn !== $newTransaction;

        $supported               = new CMessageSupported();
        $supported->object_id    = CView::get("object_id", "str");
        $supported->object_class = CView::get("object_class", "str");
        $supported->profil       = CView::get("profil", "str");
        $supported->transaction  = $hasRename ? $previousTxn : $newTransaction;
        $supported->active       = 1;
        CView::checkin();

        /** @var CMessageSupported[] $matches */
        $matches = $supported->loadMatchingList();

        foreach ($matches as $entry) {
            $entry->version     = $targetVersion;
            $entry->transaction = $newTransaction;

            $error = $entry->store();
            if ($error) {
                CAppUI::stepAjax($error, UI_MSG_ALERT);
                continue;
            }

            CAppUI::stepAjax('CMessageSupported-msg-modify');
        }
    }

    public function refreshMessageSupported(): void
    {
        $familyClass = CView::get("family", "str");

        /** @var CInteropNorm $family */
        $family = new $familyClass();

        $supported               = new CMessageSupported();
        $supported->object_id    = CView::get("object_id", "str");
        $supported->object_class = CView::get("object_class", "str");
        $supported->transaction  = CView::get("transaction", "str");
        $supported->message      = CView::get("message", "str");
        $supported->profil       = CView::get("profil", "str");
        $supported->version      = CView::get("version", "str");
        CView::checkin();

        $supported->loadMatchingObject();

        $this->renderSmarty(
            'inc_container_active_message_supported_form',
            [
                '_families'          => $family,
                '_family_name'       => CClassMap::getInstance()->getShortName($family),
                '_category_name'     => $supported->transaction,
                '_message_supported' => $supported,
            ]
        );
    }

}
