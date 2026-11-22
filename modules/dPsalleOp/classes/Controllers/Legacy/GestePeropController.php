<?php

/**
 * @package Hospilibre
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\SalleOp\Controllers\Legacy;

use Exception;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\SalleOp\CAnesthPerop;
use Hospilibre\SalleOp\CAnesthPeropCategorie;
use Hospilibre\SalleOp\CGestePerop;
use Hospilibre\SalleOp\CGestePeropPrecision;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CView;

/**
 * Contrôleur legacy dédié aux gestes peropératoires.
 */
class GestePeropController extends CLegacyController
{
    /**
     * Édition d'un évènement peropératoire.
     *
     * @return void
     * @throws Exception
     */
    public function ajax_edit_evenement_perop(): void
    {
        $this->checkPermEdit();

        $guid        = CView::get("evenement_guid", "str");
        $interventionId = CView::get("operation_id", "ref class|COperation");
        $horodatage  = CView::get("datetime", "dateTime");
        $categorie   = CView::get("type", "str default|perop");

        CView::checkin();

        $intervention = COperation::findOrNew($interventionId);

        CAccessMedicalData::logAccess($intervention);

        $intervention->loadRefAnesth();
        $intervention->loadRefPatient();

        // Si aucune date fournie, on se cale sur la date de l'intervention à l'heure courante
        if (!$horodatage) {
            $horodatage = CMbDT::date($intervention->_datetime) . " " . CMbDT::time();
        }

        [$evenementClass, $evenementId] = explode("-", $guid);

        /** @var CAnesthPerop $evenement */
        $evenement               = CAnesthPerop::findOrNew($evenementId);
        $evenement->operation_id = $intervention->_id;

        $evenement->loadRefsNotes();
        $evenement->loadRefCategorie();

        $geste = $evenement->loadRefGestePerop();
        $geste->loadRefPrecisions();

        $precisionEvenement = $evenement->loadRefGestePeropPrecision();
        $precisionEvenement->loadRefValeurs();

        if (!$evenement->_id) {
            $evenement->datetime = $horodatage;
        }

        $categories = (new CAnesthPeropCategorie())->loadGroupList();

        foreach ($categories as $categorieCourante) {
            $categorieCourante->loadRefFile();
        }

        // Verrouillage de la date minimale : en SSPI on ne peut pas remonter avant l'entrée en réveil
        $dateMinimale = null;

        if ($categorie == 'sspi' && $intervention->entree_reveil) {
            $dateMinimale = $intervention->entree_reveil;
        }

        $this->renderSmarty(
            "inc_edit_evenement_perop",
            [
                "evenement"            => $evenement,
                "evenement_categories" => $categories,
                "datetime"             => $horodatage,
                "operation"            => $intervention,
                "limit_date_min"       => $dateMinimale,
            ]
        );
    }

    /**
     * Affichage des précisions rattachées à un geste peropératoire.
     *
     * @return void
     */
    public function vwGestePrecisions(): void
    {
        $this->checkPermEdit();

        $gesteId = CView::get("geste_perop_id", "ref class|CGestePerop");
        CView::checkin();

        $geste      = CGestePerop::findOrNew($gesteId);
        $precisions = $geste->loadRefPrecisions();

        $this->renderSmarty(
            "inc_vw_geste_precisions",
            [
                "precisions" => $precisions,
                "evenement"  => new CAnesthPerop(),
            ]
        );
    }

    /**
     * Affichage des valeurs d'une précision de geste peropératoire.
     *
     * composer @return void
     */
    public function vwPrecisionValeurs(): void
    {
        $this->checkPermEdit();

        $precisionId = CView::get("geste_perop_precision_id", "ref class|CGestePeropPrecision");
        CView::checkin();

        $precision = CGestePeropPrecision::findOrNew($precisionId);
        $valeurs   = $precision->loadRefValeurs();

        $this->renderSmarty(
            "inc_vw_precision_valeurs",
            [
                "valeurs"   => $valeurs,
                "evenement" => new CAnesthPerop(),
            ]
        );
    }
}
