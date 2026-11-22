<?php

/**
 * @package Hospilibre\Patients
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Patients\Controllers\Legacy;

use Ox\Core\CLegacyController;
use Ox\Core\CView;
use Hospilibre\Admin\CUser;
use Hospilibre\Patients\CDossierMedical;

class CDossierMedicalController extends CLegacyController
{
    public function editGroupeRhesus()
    {
        // Contrôle des droits d'édition avant toute chose.
        $this->checkPermEdit();

        // Récupération et validation du patient ciblé.
        $patientId = CView::get("patient_id", "ref class|CPatient");
        CView::checkin();

        // Seuls praticiens et infirmières peuvent modifier le groupe/rhésus.
        $currentUser = CUser::get()->loadRefMediuser();
        $canEditGroupeRhesus = $currentUser->isPraticien() || $currentUser->isInfirmiere();

        // Chargement du dossier médical rattaché au patient.
        $dossierMedicalId = CDossierMedical::dossierMedicalId($patientId, "CPatient");
        $dossierMedical   = CDossierMedical::findOrFail($dossierMedicalId);

        $this->renderSmarty("vw_edit_groupe_rhesus", [
            'dossier_medical'        => $dossierMedical,
            'patient_id'             => $patientId,
            'can_edit_groupe_rhesus' => $canEditGroupeRhesus,
        ]);
    }
}
