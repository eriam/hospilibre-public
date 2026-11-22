<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Framework\Matcher;

use Hospilibre\Admin\CUser;
use Hospilibre\Cabinet\CActeNGAP;
use Hospilibre\Cabinet\CConsultAnesth;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Cabinet\CPlageconsult;
use Hospilibre\Cabinet\Vaccination\CInjection;
use Hospilibre\Cabinet\Vaccination\CVaccination;
use Hospilibre\Files\CFile;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\ObservationResult\CObservationAbnormalFlag;
use Hospilibre\ObservationResult\CObservationIdentifier;
use Hospilibre\ObservationResult\CObservationResult;
use Hospilibre\ObservationResult\CObservationResultSet;
use Hospilibre\ObservationResult\CObservationResultValue;
use Hospilibre\ObservationResult\CObservationValueUnit;
use Hospilibre\Patients\CAntecedent;
use Hospilibre\Patients\CConstantesMedicales;
use Hospilibre\Patients\CCorrespondant;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\Patients\CEvenementPatient;
use Hospilibre\Patients\CMedecin;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\CTraitement;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\SalleOp\CActeCCAM;

/**
 * Interface for object's matcher functions
 */
interface MatcherVisitorInterface
{
    public function matchUser(CUser $user): CUser;

    public function matchPatient(CPatient $patient): CPatient;

    public function matchMedecin(CMedecin $medecin): CMedecin;

    public function matchPlageConsult(CPlageconsult $plage_consult): CPlageconsult;

    public function matchConsultation(CConsultation $consultation): CConsultation;

    public function matchConsultationAnesth(CConsultAnesth $consultation): CConsultAnesth;

    public function matchSejour(CSejour $sejour): CSejour;

    public function matchFile(CFile $file): CFile;

    public function matchAffectation(CAffectation $affectation): CAffectation;

    public function matchAntecedent(CAntecedent $antecedent): CAntecedent;

    public function matchTraitement(CTraitement $trt): CTraitement;

    public function matchCorrespondant(CCorrespondant $correspondant): CCorrespondant;

    public function matchEvenementPatient(CEvenementPatient $evenement_patient): CEvenementPatient;

    public function matchVaccination(CVaccination $vaccination): CVaccination;

    public function matchInjection(CInjection $injection): CInjection;

    public function matchActeCCAM(CActeCCAM $acte_ccam): CActeCCAM;

    public function matchActeNGAP(CActeNGAP $acte_ngap): CActeNGAP;

    public function matchConstante(CConstantesMedicales $constantes_medicales): CConstantesMedicales;

    public function matchDossierMedical(CDossierMedical $dossier_medical): CDossierMedical;

    public function matchOperation(COperation $operation): COperation;

    public function matchObservationResult(CObservationResult $observation_result): CObservationResult;

    public function matchObservationIdentifier(CObservationIdentifier $observation_identifier): CObservationIdentifier;

    public function matchObservationResultValue(CObservationResultValue $observation_result_value): CObservationResultValue;

    public function matchObservationResultSet(CObservationResultSet $observation_result_set): CObservationResultSet;

    public function matchObservationAbnormalFlag(CObservationAbnormalFlag $observation_flag): CObservationAbnormalFlag;

    public function matchObservationValueUnit(CObservationValueUnit $observation_value_unit): CObservationValueUnit;
}
