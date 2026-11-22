<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\SalleOp\Controllers\Legacy;

use Exception;
use Hospilibre\SalleOp\CDailyCheckListGroup;
use Hospilibre\SalleOp\Repository\DailyCheckListGroupRepository;
use Ox\Core\CLegacyController;
use Ox\Core\CView;

/**
 * Contrôleur legacy pour un groupe de check-list quotidienne (ou l'un de ses items).
 */
class DailyCheckListController extends CLegacyController
{
    /**
     * Affiche le groupe de check-list quotidienne.
     *
     * @return void
     * @throws Exception
     */
    public function viewDailyCheckListGroup(): void
    {
        $this->checkPermAdmin();

        // Récupération des paramètres de la requête.
        $groupId     = CView::get('check_list_group_id', 'ref class|CDailyCheckListGroup');
        $isDuplicate = CView::get('duplicate', 'bool default|0');

        CView::checkin();

        // Chargement du groupe demandé (ou d'une nouvelle instance) puis de ses check-lists/catégories.
        $selectedGroup = CDailyCheckListGroup::findOrNew($groupId);
        $repository     = new DailyCheckListGroupRepository();
        $groupList      = $repository->findAllWithChecklistAndCategories($selectedGroup);

        // En mode édition (identifiant fourni) on bascule sur le template dédié.
        $template = ($groupId !== null) ? "inc_edit_check_list_group" : "vw_daily_check_list_group";

        $this->renderSmarty(
            $template,
            [
                "check_list_groups" => $groupList,
                "check_list_group"  => $selectedGroup,
                "duplicate"         => $isDuplicate,
            ]
        );
    }
}
