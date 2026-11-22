<?php

/**
 * @package Hospilibre\Stats
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Stats\Controllers\Legacy;

use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;
use Ox\Core\CView;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Hospi\CItemPrestation;
use Hospilibre\Hospi\CPrestationJournaliere;
use Hospilibre\Hospi\CPrestationPonctuelle;
use Hospilibre\Hospi\CService;
use Hospilibre\Mediusers\CDiscipline;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\CSejour;

/**
 * Contrôleur legacy des statistiques.
 */
class CStatsLegacyController extends CLegacyController
{
    /**
     * Affiche le formulaire de filtrage des statistiques de prestations.
     */
    public function vw_prestations(): void
    {
        $this->checkPermRead();

        // Le séjour sert uniquement de porteur des critères de recherche.
        $criteres = new CSejour();

        $criteres->_date_min    = CView::get("_date_min", "str default|" . CMbDT::date("-3 MONTHS"));
        $criteres->_date_max    = CView::get("_date_max", "str default|" . CMbDT::date());
        $criteres->_service     = CView::get("service_id", "ref class|CService");
        $criteres->type         = CView::get("type", "str default|1");
        $criteres->praticien_id = CView::get("prat_id", "ref class|CMediusers");
        $criteres->_specialite  = CView::get("discipline_id", "num");
        $criteres->septique     = CView::get("septique", "bool");

        CView::checkin();

        $praticiens = (new CMediusers())->loadPraticiens(PERM_READ);

        $services = (new CService())->loadGroupList(["cancelled" => "= '0'"]);

        $disciplines = (new CDiscipline())->loadUsedDisciplines();

        $this->renderSmarty(
            "vw_prestations",
            [
                "filter"          => $criteres,
                "listPrats"       => $praticiens,
                "listServices"    => $services,
                "listDisciplines" => $disciplines,
            ]
        );
    }

    /**
     * Calcule et restitue le tableau croisé souhaité/réalisé des prestations,
     * ventilé par item et par mois.
     */
    public function ajax_stats_prestations(): void
    {
        $borneDebut  = CView::get("_date_min", "str default|" . CMbDT::date("-3 MONTHS"));
        $borneFin    = CView::get("_date_max", "str default|" . CMbDT::date());
        $serviceId   = CView::get("service_id", "ref class|CService");
        $type        = CView::get("type", "str default|1");
        $praticienId = CView::get("prat_id", "ref class|CMediusers");
        $specialite  = CView::get("discipline_id", "num");
        $septique    = CView::get("septique", "bool");

        CView::checkin();

        $ds = CSQLDataSource::get('std');

        $where = [
            "entree_reelle" => $ds->prepare("> ?", $borneDebut),
            "sortie_reelle" => $ds->prepare("< ?", $borneFin),
        ];

        if ($type === "1") {
            $where["type"] = $ds->prepareIn(["comp", "ambu"]);
        } elseif ($type !== "") {
            $where["type"] = $ds->prepare("= %", $type);
        }

        if ($serviceId) {
            $where["service_id"] = $ds->prepare("= %", $serviceId);
        }

        if ($praticienId) {
            $where["praticien_id"] = $ds->prepare("= %", $praticienId);
        }

        if ($septique) {
            $where["septique"] = $ds->prepare("= %", $septique);
        }

        if ($specialite) {
            $filtreSpec  = ["users_hospilibre.spec_cpam_id" => $ds->prepare("= ?", $specialite)];
            $pratsFiltre = (new CMediusers())->loadIds($filtreSpec);

            $where["praticien_id"] = $ds->prepareIn($pratsFiltre);
        }

        // Construction de la liste des mois couverts par la période demandée.
        $intervals   = [];
        $borneDebut  = CMbDT::date("first day of this month", $borneDebut);
        $borneFin    = CMbDT::date("last day of this month", $borneFin);
        $curseurMois = $borneDebut;

        while ($curseurMois < $borneFin) {
            $intervals[] = CMbDT::format($curseurMois, "%m/%Y");
            $curseurMois = CMbDT::date("+1 MONTH", $curseurMois);
        }

        $items_prestations = (new CItemPrestation())->loadList(["actif" => "= '1'"]);

        $sejours = (new CSejour())->loadList($where);

        // Pré-initialisation de la matrice : chaque item x chaque mois -> {souhait, reel}.
        $stats_prestations = [];
        foreach ($items_prestations as $idItem => $item) {
            $stats_prestations[$idItem] = [];

            foreach ($intervals as $mois) {
                $stats_prestations[$idItem][$mois] = ["souhait" => 0, "reel" => 0];
            }
        }

        foreach ($sejours as $sejour) {
            $sejour->getPrestationsForStats();

            if (!count($sejour->_ref_prestations)) {
                continue;
            }

            foreach ($sejour->_ref_prestations as $prestasDuJour) {
                foreach ($prestasDuJour as $ligne) {
                    $mois = CMbDT::format($ligne['liaison']->date, "%m/%Y");
                    $qte  = intval($ligne['quantite']);

                    if (array_key_exists('souhait', $ligne)) {
                        // Prestation journalière : souhaité toujours, réalisé si présent.
                        $stats_prestations[$ligne['souhait']->_id][$mois]["souhait"] += $qte;

                        if ($ligne['realise']->_id) {
                            $stats_prestations[$ligne['realise']->_id][$mois]["reel"] += $qte;
                        }
                    } else {
                        // Prestation ponctuelle : souhaité et réalisé identiques.
                        $stats_prestations[$ligne['item']->_id][$mois]["souhait"] += $qte;
                        $stats_prestations[$ligne['item']->_id][$mois]["reel"]    += $qte;
                    }
                }
            }
        }

        $prestas = [
            "CPrestationJournaliere" => [],
            "CPrestationPonctuelle"  => [],
        ];

        $groupId    = CGroups::loadCurrent()->_id;
        $filtreDate = ["group_id" => $ds->prepare(" = ?", $groupId)];

        $prestationsJourn = (new CPrestationJournaliere())->loadList($filtreDate);
        $prestationsPonc  = (new CPrestationPonctuelle())->loadList($filtreDate);

        foreach ($prestationsJourn as $prestation) {
            $prestation->loadRefsItems();
            $prestas["CPrestationJournaliere"][$prestation->nom] = array_keys($prestation->_ref_items);
        }

        foreach ($prestationsPonc as $prestation) {
            $prestation->loadRefsItems();
            $prestas["CPrestationPonctuelle"][$prestation->nom] = array_keys($prestation->_ref_items);
        }

        $this->renderSmarty(
            "inc_stats_prestation",
            [
                "sejours"           => $sejours,
                "prestas"           => $prestas,
                "items_prestations" => $items_prestations,
                "intervals"         => $intervals,
                "stats_prestations" => $stats_prestations,
            ]
        );
    }
}
