<?php

/**
 * @package Hospilibre\Astreintes
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Astreintes\Controllers\Legacy;

use Exception;
use Hospilibre\Astreintes\CCategorieAstreinte;
use Hospilibre\Etablissement\CGroups;
use Ox\Core\CLegacyController;
use Ox\Core\CView;

/**
 * Contrôleur legacy pour le paramétrage des catégories d'astreintes.
 */
class AstreintesSettingsLegacyController extends CLegacyController
{
    /**
     * Affiche la liste des catégories d'astreintes existantes.
     *
     * @throws Exception
     */
    public function listCategorieAstreintes(): void
    {
        $this->checkPermAdmin();

        $viewData = ["categories" => CCategorieAstreinte::loadListCategories()];

        $this->renderSmarty("vw_list_categories", $viewData);
    }

    /**
     * Ouvre le formulaire d'édition d'une catégorie (nouvelle ou existante).
     *
     * @throws Exception
     */
    public function editCategorie(): void
    {
        $this->checkPermRead();

        $categoryRef = CView::get("category_id", "ref class|CCategorieAstreinte");

        CView::checkin();

        $currentCategory = $categoryRef
            ? CCategorieAstreinte::findOrFail($categoryRef)
            : new CCategorieAstreinte();

        $this->renderSmarty("inc_edit_category", [
            "category" => $currentCategory,
            "groups"   => CGroups::loadGroups(),
        ]);
    }
}
