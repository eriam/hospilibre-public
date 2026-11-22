<?php

/**
 * @package Hospilibre
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Maternite\Controllers\Legacy;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CView;
use Hospilibre\Maternite\CGrossesse;
use Hospilibre\Maternite\CNaissance;

class CGrossesseController extends CLegacyController
{
    /**
     * Clôture les grossesses ayant atteint 43 SA (semaines d'aménorrhée).
     */
    public function ajax_close_pregnancies(): void
    {
        CCanDo::checkEdit();

        $groupId = CView::get("group_id", "ref class|CGroups");
        CView::checkin();

        $model = new CGrossesse();
        $dataSource = $model->getDS();

        $filters = [
            "group_id" => $dataSource->prepare('= ?', $groupId),
            "active"   => $dataSource->prepare('= ?', '1'),
        ];

        $pregnancies = $model->loadList($filters);

        $closedCount = 0;

        foreach ($pregnancies as $pregnancy) {
            $pregnancy->updateFormFields();

            if ($pregnancy->_semaine_grossesse < 43) {
                continue;
            }

            $pregnancy->active           = 0;
            $pregnancy->datetime_cloture = $pregnancy->datetime_accouchement ?: CMbDT::dateTime();
            $pregnancy->store();

            $closedCount++;
        }

        CAppUI::setMsg(CAppUI::tr("CGrossesse-msg-modify") . " x $closedCount", UI_MSG_OK);
        echo CAppUI::getMsg();

        $this->rip();
    }

    /**
     * Rafraîchit la liste des réanimateurs attachés à une naissance.
     *
     * @throws Exception
     */
    public function updateResuscitatorsList(): void
    {
        CCanDo::checkEdit();

        $naissanceId = CView::get('naissance_id', "ref class|CNaissance");
        CView::checkin();

        $naissance = new CNaissance();
        $naissance->load($naissanceId);
        $naissance->loadRefsResuscitators();

        $this->renderSmarty('inc_resuscitators', [
            'naissance' => $naissance,
        ]);
    }

    /**
     * Affiche la vue d'association d'une grossesse.
     *
     * @throws Exception
     */
    public function bindPregnancy(): void
    {
        $this->checkPermRead();

        $objectGuid     = CView::get("object_guid", "str");
        $parturienteId  = CView::get("parturiente_id", "ref class|CPatient");
        $grossesseId    = CView::get("grossesse_id", "ref class|CGrossesse");

        CView::checkin();

        $this->renderSmarty(
            "inc_bind_grossesse",
            [
                "object_guid"    => $objectGuid,
                "parturiente_id" => $parturienteId,
                "grossesse_id"   => $grossesseId,
            ]
        );
    }
}
