<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Framework\Transformer;

use Ox\Import\Framework\Entity\ActeCCAM;
use Ox\Import\Framework\Entity\ActeNGAP;
use Ox\Import\Framework\Entity\Affectation;
use Ox\Import\Framework\Entity\Antecedent;
use Ox\Import\Framework\Entity\CImportCampaign;
use Ox\Import\Framework\Entity\Constante;
use Ox\Import\Framework\Entity\Consultation;
use Ox\Import\Framework\Entity\ConsultationAnesth;
use Ox\Import\Framework\Entity\Correspondant;
use Ox\Import\Framework\Entity\DossierMedical;
use Ox\Import\Framework\Entity\EvenementPatient;
use Ox\Import\Framework\Entity\ExternalReferenceStash;
use Ox\Import\Framework\Entity\File;
use Ox\Import\Framework\Entity\Injection;
use Ox\Import\Framework\Entity\Medecin;
use Ox\Import\Framework\Entity\Operation;
use Ox\Import\Framework\Entity\Patient;
use Ox\Import\Framework\Entity\PlageConsult;
use Ox\Import\Framework\Entity\Sejour;
use Ox\Import\Framework\Entity\Traitement;
use Ox\Import\Framework\Entity\User;
use Ox\Import\Framework\Entity\Vaccination;
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
use Hospilibre\OxLaboServer\Import\Entity\ObservationAbnormalFlag;
use Hospilibre\OxLaboServer\Import\Entity\ObservationIdentifier;
use Hospilibre\OxLaboServer\Import\Entity\ObservationResult;
use Hospilibre\OxLaboServer\Import\Entity\ObservationResultSet;
use Hospilibre\OxLaboServer\Import\Entity\ObservationResultValue;
use Hospilibre\OxLaboServer\Import\Entity\ObservationValueUnit;
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
 * Object transformer for import
 */
interface TransformerVisitorInterface
{
    /**
     * Transform an external user
     *
     * @param User                        $external_user
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CUser
     */
    public function transformUser(
        User $external_user,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CUser;

    /**
     * Transform an external patient
     *
     * @param Patient                     $external_patient
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CPatient
     */
    public function transformPatient(
        Patient $external_patient,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CPatient;

    /**
     * Transform an external medecin
     *
     * @param Medecin                     $external_medecin
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CMedecin
     */
    public function transformMedecin(
        Medecin $external_medecin,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CMedecin;

    /**
     * Transform an external plage consult
     *
     * @param PlageConsult                $external_plage_consult
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CPlageconsult
     */
    public function transformPlageConsult(
        PlageConsult $external_plage_consult,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CPlageconsult;

    /**
     * Transform an external consultation
     *
     * @param Consultation                $external_consultation
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CConsultation
     */
    public function transformConsultation(
        Consultation $external_consultation,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CConsultation;

    /**
     * Transform an external consultation anesth
     *
     * @param ConsultationAnesth          $external_consultation
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CConsultAnesth
     */
    public function transformConsultationAnesth(
        ConsultationAnesth $external_consultation,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CConsultAnesth;

    /**
     * Transform an external sejour
     *
     * @param Sejour                      $external_sejour
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CSejour
     */
    public function transformSejour(
        Sejour $external_sejour,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CSejour;

    /**
     * Transform an external file
     *
     * @param File                        $external_file
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CFile
     */
    public function transformFile(
        File $external_file,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CFile;

    /**
     * Transform an external affectation
     *
     * @param Affectation                 $external_affectation
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CAffectation
     */
    public function transformAffectation(
        Affectation $external_affectation,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CAffectation;

    /**
     * Transform an external antecedent
     *
     * @param Antecedent                  $external_atcd
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CAntecedent
     */
    public function transformAntecedent(
        Antecedent $external_atcd,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CAntecedent;

    /**
     * Transform an external traitement
     *
     * @param Traitement                  $external_trt
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CTraitement
     */
    public function transformTraitement(
        Traitement $external_trt,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CTraitement;

    /**
     * Transform an external correspondant
     *
     * @param Correspondant               $external_correspondant
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CCorrespondant
     */
    public function transformCorrespondant(
        Correspondant $external_correspondant,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CCorrespondant;

    /**
     * Transform an external evenement patient
     *
     * @param EvenementPatient            $external_evenement_patient
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CEvenementPatient
     */
    public function transformEvenementPatient(
        EvenementPatient $external_evenement_patient,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CEvenementPatient;

    /**
     * Transform an external vaccination
     *
     * @param Vaccination                 $external_vaccination
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CVaccination
     */
    public function transformVaccination(
        Vaccination $external_vaccination,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CVaccination;

    /**
     * Transform an external injection
     *
     * @param Injection                   $external_injection
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CInjection
     */
    public function transformInjection(
        Injection $external_injection,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CInjection;

    /**
     * Transform an external acte ccam
     *
     * @param ActeCCAM                    $external_acte
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CActeCCAM
     */
    public function transformActeCCAM(
        ActeCCAM $external_acte,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CActeCCAM;

    /**
     * Transform an external acte ngap
     *
     * @param ActeNGAP                    $external_acte
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CActeNGAP
     */
    public function transformActeNGAP(
        ActeNGAP $external_acte,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CActeNGAP;

    /**
     * Transform an external constante
     *
     * @param Constante                   $external_constante
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CConstantesMedicales
     */
    public function transformConstante(
        Constante $external_constante,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CConstantesMedicales;

    /**
     * Transform an external dossier medical
     *
     * @param DossierMedical              $external_dossier
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CDossierMedical
     */
    public function transformDossierMedical(
        DossierMedical $external_dossier,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CDossierMedical;

    public function transformOperation(
        Operation $external_operation,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): COperation;


    /**
     * Transform an external observation result
     *
     * @param ObservationResult           $external_observation_result
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CObservationResult
     */
    public function transformObservationResult(
        ObservationResult $external_observation_result,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CObservationResult;

    /**
     * Transform an external observation identifer
     *
     * @param ObservationIdentifier       $external_observation_identifer
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CObservationIdentifier
     */
    public function transformObservationIdentifier(
        ObservationIdentifier $external_observation_identifer,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CObservationIdentifier;

    /**
     * Transform an external observation result value
     *
     * @param ObservationResultValue      $external_observation_result_value
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CObservationResultValue
     */
    public function transformObservationResultValue(
        ObservationResultValue $external_observation_result_value,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CObservationResultValue;

    /**
     * Transform an external observation result set
     *
     * @param ObservationResultSet        $external_observation_result_set
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CObservationResultSet
     */
    public function transformObservationResultSet(
       ObservationResultSet $external_observation_result_set,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CObservationResultSet;

    /**
     * Transform an external observation normal flag
     *
     * @param ObservationAbnormalFlag     $observation_abnormal_flag
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CObservationAbnormalFlag
     */
    public function transformObservationAbnormalFlag(
        ObservationAbnormalFlag $observation_abnormal_flag,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CObservationAbnormalFlag;

    /**
     * Transform an external observation value unit
     *
     * @param ObservationValueUnit        $observation_value_unit
     * @param ExternalReferenceStash|null $reference_stash
     * @param CImportCampaign|null        $campaign
     *
     * @return CObservationValueUnit
     */
    public function transformObservationValueUnit(
        ObservationValueUnit $observation_value_unit,
        ?ExternalReferenceStash $reference_stash = null,
        ?CImportCampaign $campaign = null
    ): CObservationValueUnit;
}
