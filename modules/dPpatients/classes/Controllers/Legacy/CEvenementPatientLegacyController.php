<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Patients\Controllers\Legacy;

use Exception;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\Patients\CEvenementAlerteUser;
use Hospilibre\Patients\CEvenementPatient;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\CRegleAlertePatient;
use Hospilibre\Patients\CTypeEvenementPatient;
use Hospilibre\Patients\Services\EvenementPatientDHEService;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Core\CView;

/**
 * Contrôleur legacy pour les évènements patient.
 */
class CEvenementPatientLegacyController extends CLegacyController
{
    /**
     * @throws Exception|CMbException
     */
    public function printDHE(): void
    {
        $this->checkPermRead();

        $evenementId = CView::get('evenement_id', 'ref class|CEvenementPatient');

        CView::checkin();

        $dheService = new EvenementPatientDHEService($evenementId);

        $sihId = $dheService->getSIHId();
        [$dheClass, $dheId] = explode('-', $dheService->getDHEGuid());
        $dheService->prepareContext($dheClass);

        if (!$sihId || !$dheId) {
            throw new CMbException('CAppelSIH-no_synchronize_dhe');
        }

        $sihType = $dheService->getSIHType($sihId);
        $payload = $dheService->requestDHE($sihId, $sihType);
        $dheService->prepareResources($payload);
        $dheService->printDHE($dheClass);
    }

    /**
     * @throws Exception
     */
    public function ajax_generate_alertes_evt(): void
    {
        $functionId = CView::get("function_id", "ref class|CFunctions");

        CView::checkin();

        $currentUser = CMediusers::get();
        $groupId     = CGroups::loadCurrent()->_id;

        $functionsList = $functionId ? [] : $currentUser->loadFonctions(PERM_EDIT, $groupId);
        $functionsIds  = array_keys($functionsList);

        $today = CMbDT::date();

        // Chargement des règles d'alerte actives applicables au groupe/aux fonctions
        $ruleFilter          = [];
        $ruleFilter["actif"] = " = '1'";
        $ruleFilter[]        = " group_id = '$groupId' OR function_id "
            . CSQLDataSource::prepareIn($functionsIds, $functionId);

        $rules = (new CRegleAlertePatient())->loadList($ruleFilter);

        $ruleUsersAlert = CStoredObject::massLoadBackRefs($rules, "users_alert_evt");
        CStoredObject::massLoadFwdRef($ruleUsersAlert, "user_id");

        $patientModel   = new CPatient();
        $medicalRecord  = new CDossierMedical();

        CStoredObject::massLoadFwdRef($rules, 'function_id');

        /** @var CRegleAlertePatient $rule */
        foreach ($rules as $rule) {
            $rule->loadRefsUsers();

            $ds = $rule->getDS();

            $conditions = [
                'patients.group_id' => $ds->prepare(
                    '= ?',
                    $rule->group_id ?: $rule->loadRefFunction()->group_id
                ),
            ];

            $joins = [];

            // Sexe
            if ($rule->sexe) {
                $conditions["sexe"] = $ds->prepare('= ?', $rule->sexe);
            }

            // Âge
            if ($rule->age_valeur) {
                $birthDate = CMbDT::transform("-$rule->age_valeur years", $today, "%Y-%m-%d");
                $comparator = $rule->age_operateur == "sup" ? "<" : ">";
                $conditions["patients.naissance"] = $ds->prepare($comparator . ' ?', $birthDate);
            }

            // CIM
            if ($rule->diagnostics || $rule->pathologies) {
                $joins = [
                    "dossier_medical" => "dossier_medical.object_class = 'CPatient' AND dossier_medical.object_id = patients.patient_id",
                ];

                $conditions["dossier_medical.dossier_medical_id"] = "IS NOT NULL";
            }

            // Programme clinique
            if ($rule->programme_clinique_id) {
                $joins["inclusion_programme"] = "inclusion_programme.patient_id = patients.patient_id";
                $conditions["inclusion_programme.programme_clinique_id"] =
                    $ds->prepare('= ?', $rule->programme_clinique_id);
            }

            // ALD
            if ($rule->ald) {
                $conditions['patients.ald'] = "= '1'";
            }

            $patientIds = $patientModel->loadIds($conditions, null, null, "patients.patient_id", $joins);

            $minDate = CMbDT::date("-" . ($rule->periode_refractaire + $rule->nb_anticipation) . " days");

            foreach ($patientIds as $patientId) {
                $medicalRecordId = CDossierMedical::dossierMedicalId($patientId, "CPatient");

                // On écarte les patients ayant déjà un évènement lié à cette règle sur la période réfractaire
                $recentEventFilter                       = [];
                $recentEventFilter["dossier_medical_id"] = $ds->prepare('= ?', $medicalRecordId);
                $recentEventFilter["regle_id"]           = $ds->prepare('= ?', $rule->_id);
                $recentEventFilter["date"]               = $ds->prepare('>= ?', $minDate);
                $recentEventFilter['alerter']            = "= '1'";

                $existingEvent = new CEvenementPatient();
                $existingEvent->loadObject($recentEventFilter);

                if ($existingEvent->_id) {
                    continue;
                }

                // Tous les codes CIM configurés doivent figurer dans le dossier médical du patient
                if ($rule->diagnostics) {
                    $medicalRecord->load($medicalRecordId);
                    $everyCimPresent = true;

                    foreach ($rule->_ext_diagnostics as $cimCode => $ruleCim) {
                        if (!isset($medicalRecord->_ext_codes_cim[$cimCode])) {
                            $everyCimPresent = false;
                        }
                    }
                    if (!$everyCimPresent) {
                        continue;
                    }
                }

                // Toutes les pathologies configurées doivent figurer dans le dossier médical du patient
                if ($rule->pathologies) {
                    $medicalRecord->load($medicalRecordId);
                    $pathologyCimCodes = CMbArray::pluck($medicalRecord->loadRefsPathologies(), 'code_cim10');
                    $everyCimPresent = true;

                    foreach ($rule->_ext_pathologies as $cimCode => $ruleCim) {
                        if (!in_array($cimCode, $pathologyCimCodes)) {
                            $everyCimPresent = false;
                        }
                    }

                    if (!$everyCimPresent) {
                        continue;
                    }
                }

                // Création de l'évènement patient
                $event                     = new CEvenementPatient();
                $event->libelle            = $rule->name;
                $event->dossier_medical_id = $medicalRecordId;
                $event->date               = CMbDT::date("+$rule->nb_anticipation days");
                $event->alerter            = '1';
                $event->regle_id           = $rule->_id;
                $event->store();

                if ($event->_id) {
                    // Rattachement de la liste des utilisateurs à alerter
                    foreach ($rule->_ref_users as $userToAlert) {
                        $alert               = new CEvenementAlerteUser();
                        $alert->object_id    = $event->_id;
                        $alert->object_class = $event->_class;
                        $alert->user_id      = $userToAlert->_id;
                        $alert->store();
                    }
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    public function editTypeEvenement(): void
    {
        $this->checkPermEdit();

        /** @var int $typeId */
        $typeId = CView::get('type_evenement_patient_id', 'num');

        CView::checkin();

        $eventType = CTypeEvenementPatient::findOrNew($typeId);

        $functions = (new CFunctions())->loadListWithPerms(PERM_EDIT, null, 'text');

        $report = new CCompteRendu();
        $ds     = $report->getDS();

        $functionsIn = CSQLDataSource::prepareIn(CMbArray::pluck($functions, '_id'));
        $userId      = CMediusers::get()->_id;
        $groupId     = CGroups::get()->_id;

        $filter = [
            $ds->prepare('user_id = ?', $userId) . " OR function_id $functionsIn OR "
                . $ds->prepare('group_id = ?', $groupId),
            'object_id'    => 'IS NULL',
            'type'         => "= 'body'",
            'actif'        => "= '1'",
            'object_class' => CSQLDataSource::prepareIn(['CEvenementPatient', 'CPatient', 'CConsultation']),
        ];

        $mailingModels = $report->loadList($filter);

        $this->renderSmarty(
            'inc_edit_types_evenement_patient',
            [
                'type'           => $eventType,
                'functions'      => $functions,
                'mailing_models' => $mailingModels,
            ]
        );
    }
}
