<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Maternite\Controllers\Legacy;


use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CView;
use Hospilibre\Maternite\CDepistageGrossesse;
use Hospilibre\Maternite\CDossierPerinat;
use Hospilibre\Maternite\CGrossesse;
use Hospilibre\Maternite\CNaissance;
use Hospilibre\Maternite\CSurvEchoGrossesse;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CConstantesMedicales;

class CPerinatalFolderController extends CLegacyController
{
    /**
     * Édition du dossier périnatal dans la vue allégée.
     *
     * @throws Exception
     */
    public function ajax_vw_edit_perinatal_folder(): void
    {
        $this->checkPermEdit();
        $pregnancyId = CView::get("grossesse_id", "ref class|CGrossesse");
        CView::checkin();

        $pregnancy = CGrossesse::findOrNew($pregnancyId);

        $mother = $pregnancy->loadRefParturiente();
        $mother->loadLastAllaitement();

        $folder = $pregnancy->loadRefDossierPerinat();

        $father = $pregnancy->loadRefPere();
        $father->loadRefDossierMedical();

        $pregnancy->loadRefsNaissances();

        $lastStay        = $pregnancy->loadLastSejour();
        $recentConstants = CConstantesMedicales::getLatestFor(
            $mother,
            null,
            ["poids", "taille"],
            $lastStay,
            false
        );

        $motherConstants = $folder->loadRefConstantesAntecedentsMaternels();

        $weightDelta = 0;
        if ($motherConstants->poids && $recentConstants[0]->poids) {
            $weightDelta = $recentConstants[0]->poids - $motherConstants->poids;
        }

        $screenings     = $pregnancy->loadBackRefs("depistages", "date ASC");
        $latestScreening = end($screenings);

        $screening = $latestScreening;

        if (!$latestScreening) {
            $latestScreening       = new CDepistageGrossesse();
            $screening             = new CDepistageGrossesse();
            $latestScreening->date = "now";
            $screening->date       = "now";
        }

        $this->renderSmarty('inc_vw_edit_perinatal_folder', [
            'grossesse'          => $pregnancy,
            'last_poids'         => $recentConstants,
            'constantes_maman'   => $motherConstants,
            'depistage'          => $screening,
            'last_depistage'     => $latestScreening,
            'naissance'          => new CNaissance(),
            'echographique'      => new CSurvEchoGrossesse(),
            'pathologies_fields' => $folder->getMotherPathologiesFields(),
        ]);
    }

    /**
     * Autocomplétion de la liste des pathologies maternelles.
     */
    public function motherPathologiesAutocomplete(): void
    {
        $this->checkPermRead();
        $fieldName = CView::get("input_field", "str");
        $search    = CView::get("{$fieldName}", "str");
        $folderId  = CView::get("dossier_perinat_id", "ref class|CDossierPerinat");
        CView::checkin();

        $folder = CDossierPerinat::findOrNew($folderId);

        $this->renderSmarty('vw_mother_pathologies_autocomplete', [
            'dossier'            => $folder,
            'pathologies_fields' => $folder->getMotherPathologiesFields($search),
        ]);
    }

    /**
     * Affichage des tags de pathologies maternelles.
     */
    public function motherPathologiesTags(): void
    {
        $this->checkPermRead();
        $folderId = CView::get("dossier_perinat_id", "ref class|CDossierPerinat");
        CView::checkin();

        $folder = CDossierPerinat::findOrNew($folderId);

        $this->renderSmarty('dossier_perinatal_light/inc_vw_mother_pathologies_tags', [
            'dossier'            => $folder,
            'pathologies_fields' => $folder->getMotherPathologiesFields(),
        ]);
    }

    /**
     * Affichage du dossier maternité au moment du premier contact.
     */
    public function dossier_mater_premier_contact(): void
    {
        $this->checkPermEdit();

        $pregnancyId = CView::get("grossesse_id", "ref class|CGrossesse");
        $printMode   = CView::get("print", "bool default|0");
        CView::checkin();

        $pregnancy = new CGrossesse();
        $pregnancy->load($pregnancyId);
        $pregnancy->loadRefGroup();
        $pregnancy->loadRefPere();
        $pregnancy->getDateAccouchement();
        $pregnancy->loadRefsNaissances();

        $mother = $pregnancy->loadRefParturiente();
        $mother->loadIPP($pregnancy->group_id);
        $mother->loadRefsCorrespondants();
        $mother->loadRefsCorrespondantsPatient();

        $folder = $pregnancy->loadRefDossierPerinat();

        // Âge gestationnel au premier contact, si la date est connue
        if ($folder->date_premier_contact) {
            $gestationalInfo = $pregnancy->getAgeGestationnel($folder->date_premier_contact);
            $gestationalAge  = $gestationalInfo["SA"];
        } else {
            $gestationalAge = "--";
        }

        foreach ($pregnancy->loadRefsConsultations() as $consultation) {
            $consultation->loadRefPraticien();
            $consultation->loadRefSuiviGrossesse();
            $consultation->getSA();
        }

        // Liste des consultants (professionnels de santé habilités)
        $practitioner    = new CMediusers();
        $consultantsList = $practitioner->loadProfessionnelDeSanteByPref(PERM_EDIT);

        $preselectConsultant = !$folder->consultant_premier_contact_id
            && in_array(CAppUI::$user->_id, array_keys($consultantsList));

        if ($preselectConsultant) {
            $folder->consultant_premier_contact_id = CAppUI::$user->_id;
        }

        $this->renderSmarty("dossier_mater_premier_contact", [
            "grossesse"       => $pregnancy,
            "age_gest"        => $gestationalAge,
            "listConsultants" => $consultantsList,
            "print"           => $printMode,
        ]);
    }
}
