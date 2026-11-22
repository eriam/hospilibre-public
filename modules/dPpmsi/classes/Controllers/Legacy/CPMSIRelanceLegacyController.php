<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Pmsi\Controllers\Legacy;

use Exception;
use Hospilibre\Files\CReadFile;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\Pmsi\CRelancePMSI;
use Hospilibre\Pmsi\Services\PMSIRelanceService;
use Ox\Core\CLegacyController;
use Ox\Core\CMbModelNotFoundException;
use Ox\Core\CStoredObject;
use Ox\Core\CView;

/**
 * Contrôleur historique dédié aux relances PMSI.
 */
class CPMSIRelanceLegacyController extends CLegacyController
{
    /**
     * Affiche le séjour d'un patient.
     *
     * @return void
     * @throws CMbModelNotFoundException
     * @throws Exception
     */
    public function searchRelances(): void
    {
        $this->checkPermEdit();

        // Récupération des critères de recherche transmis dans la requête.
        $status           = CView::get("status", "str", true);
        $urgence          = CView::get("urgence", "str", true);
        $type_doc         = CView::get("type_doc", "str", true);
        $commentaire_med  = CView::get("commentaire_med", "str", true);
        $chir_id          = CView::get("chir_id", "ref class|CMediusers", true);
        $date_min_relance = CView::get("date_min_relance", "date", true);
        $date_max_relance = CView::get("date_max_relance", "date", true);
        $date_min_sejour  = CView::get("date_min_sejour", "date", true);
        $date_max_sejour  = CView::get("date_max_sejour", "date", true);
        $NDA              = CView::get("NDA", "str");
        $export           = CView::get('export', 'bool default|0');
        $type_sejour      = CView::get('type', "enum list|" . implode("|", CSejour::$types), true);
        $order_col        = CView::get('order_col', 'str default|nom');
        $order_way        = CView::get('order_way', 'enum list|ASC|DESC default|DESC');

        CView::checkin();

        $service = new PMSIRelanceService();
        $relance = new CRelancePMSI();

        $order = $service->computeOrder($order_col, $order_way);

        $relances    = [];
        $sejourExist = 0;

        if ($NDA) {
            // Recherche ciblée par numéro de dossier administratif.
            $sejour = $service->getSejourFromNDA($NDA);
            if ($sejour->_id) {
                $relance->sejour_id = $sejour->_id;
                $relance->loadMatchingObject();

                if ($relance->_id) {
                    $relances[] = $relance;
                }

                $sejourExist = 1;
            }
        } else {
            // Recherche multicritères classique.
            $service->joinSejour();
            $service->addGroupFilter();

            if ($date_min_sejour && $date_max_sejour) {
                $service->addDatesFilter($date_min_sejour, $date_max_sejour);
            }

            if ($status) {
                $service->addStatusFilter($status);
            }

            if ($urgence) {
                $service->addUrgenceFilter($urgence);
            }

            if ($type_doc) {
                $service->addDocTypeFilter($type_doc);
            }

            if ($commentaire_med != "") {
                $service->addCommentFilter($commentaire_med);
            }

            if ($chir_id) {
                $service->addChirFilter($chir_id);
            }

            if ($type_sejour) {
                $service->addTypeSejourFilter($type_sejour);
            }

            $service->addRelanceDatesFilter($date_min_relance, $date_max_relance);

            $where    = $service->getWhere();
            $ljoin    = $service->getLJoin();
            $relance  = new CRelancePMSI();
            $relances = $relance->loadList($where, $order, null, null, $ljoin);
        }

        // Chargement groupé des références directes.
        CStoredObject::massLoadFwdRef($relances, "patient_id");
        CStoredObject::massLoadFwdRef($relances, "chir_id");

        /** @var array $sejours */
        $sejours = CStoredObject::massLoadFwdRef($relances, "sejour_id");

        CSejour::massLoadNDA($sejours);

        // Documents déjà consultés par l'utilisateur courant.
        $readFiles          = new CReadFile();
        $readFiles->user_id = CMediusers::get()->_id;
        $readFiles          = $readFiles->loadMatchingList();

        $notReadFiles = [];

        foreach ($relances as $currentRelance) {
            $currentRelance->loadRefSejour();
            $currentRelance->loadRefPatient();
            $currentRelance->loadRefChir();
            $currentRelance->_ref_sejour->loadRefsDocs();

            $sejourId                = $currentRelance->_ref_sejour->_id;
            $notReadFiles[$sejourId] = [];

            foreach ($currentRelance->_ref_sejour->_ref_documents as $document) {
                $alreadyRead = false;

                foreach ($readFiles as $readFile) {
                    if ($readFile->object_id == $document->_id && $readFile->object_class == $document->_class) {
                        $alreadyRead = true;
                        break;
                    }
                }

                if (!$alreadyRead) {
                    $notReadFiles[$sejourId][] = $document;
                }
            }
        }

        // Regroupement des relances par praticien pour l'impression.
        $relancesByPrat = $service->getRelancesByPrat($relances);
        $prats          = $service->getPrats($relances);

        $chir = new CMediusers();
        if ($chir_id) {
            $chir = CMediusers::get($chir_id);
        }

        if ($export) {
            $service->export($relances, $date_min_relance, $date_max_relance);

            return;
        }

        $tplVars = [
            'relances'         => $relances,
            'relances_by_prat' => $relancesByPrat,
            'prats'            => $prats,
            'date_min_relance' => $date_min_relance,
            'date_max_relance' => $date_max_relance,
            'status'           => $status,
            'urgence'          => $urgence,
            'type_doc'         => $type_doc,
            'commentaire_med'  => $commentaire_med,
            'chir'             => $chir,
            'sejour_exist'     => $sejourExist,
            'order_way'        => $order_way,
            'order_col'        => $order_col,
            'notReadFiles'     => $notReadFiles,
        ];

        if ($sejourExist) {
            $tplVars['sejour'] = $sejour;
        }

        $this->renderSmarty('inc_search_relances', $tplVars);
    }
}
