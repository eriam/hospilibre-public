<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Ccam\Controllers\Legacy;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Core\CMbObject;
use Ox\Core\CValue;
use Ox\Core\CView;
use Ox\Core\Sessions\CSessionHandler;
use Ox\Core\Sessions\CSessionManager;
use Hospilibre\Admin\CUser;
use Hospilibre\Cabinet\CActeNGAP;
use Hospilibre\Ccam\CCodable;
use Hospilibre\Ccam\CCodageCCAM;
use Hospilibre\Ccam\CDatedCodeCCAM;
use Hospilibre\Ccam\CDentCCAM;
use Hospilibre\Ccam\CModelCodage;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\SalleOp\CActeCCAM;

class CodageLegacyController extends CLegacyController
{
    public function checkLockCodage(): void
    {
        $this->checkPermRead();

        $praticienId  = CView::get('praticien_id', 'ref class|CMediusers');
        $codableClass = CView::get('codable_class', 'str');
        $codableId    = CView::get('codable_id', 'ref meta|codable_class');
        $day          = CView::get('date', 'date');
        $isLock       = CView::get('lock', 'bool default|1');
        $doExport     = CView::get('export', 'bool default|0');

        CView::checkin();

        $currentUser = CMediusers::get();

        /** @var CCodable $codable */
        $codable = CCodable::loadFromGuid("$codableClass-$codableId");

        if (!$day) {
            $day = CMbDT::date($codable->_datetime);
        }

        $codage = CCodageCCAM::get($codable, $praticienId, 1, $day);

        $lockMode = CAppUI::gconf("dPccam codage lock_codage_ccam");

        if ($lockMode != 'password' && $codableClass != 'CSejour') {
            $filter                = new CCodageCCAM();
            $filter->praticien_id  = $praticienId;
            $filter->codable_class = $codableClass;
            $filter->codable_id    = $codableId;

            foreach ($filter->loadMatchingList() as $matchedCodage) {
                $matchedCodage->locked = $isLock;
                $matchedCodage->store();
            }

            CAppUI::setMsg(
                $isLock ? 'CCodageCCAM-msg-codage_locked' : 'CCodageCCAM-msg-codage_unlocked',
                UI_MSG_OK
            );
            echo CAppUI::getMsg();
            CApp::rip();
        }

        $askPassword = ($lockMode == 'password' && $currentUser->_id != $codage->praticien_id);

        $this->renderSmarty('inc_check_lock_codage.tpl', [
            'praticien_id'  => $praticienId,
            'praticien'     => $codage->loadPraticien(),
            'codable_class' => $codable->_class,
            'codable_id'    => $codable->_id,
            'date'          => $day,
            'lock'          => $isLock,
            'export'        => $doExport,
            'askPassword'   => $askPassword,
        ]);
    }

    public function lockCodage(): void
    {
        $this->checkPermEdit();

        $codableClass  = CView::post('codable_class', 'str');
        $codableId     = CView::post('codable_id', 'ref meta|codable_class');
        $praticienId   = CView::post('praticien_id', 'ref class|CMediusers');
        $day           = CView::post('date', 'date');
        $userPassword  = CView::post('user_password', 'str');
        $lockEveryone  = CView::post('lock_all_codages', 'bool default|0');
        $isLock        = CView::post('lock', 'bool default|1');
        $doExport      = CView::post('export', 'bool default|0');

        CView::checkin();

        $filter                = new CCodageCCAM();
        $filter->praticien_id  = $praticienId;
        $filter->codable_class = $codableClass;
        $filter->codable_id    = $codableId;
        $filter->date          = ($day && !$lockEveryone) ? $day : null;

        /** @var CCodageCCAM[] $codages */
        $codages     = $filter->loadMatchingList();
        $currentUser = CMediusers::get();
        $praticien   = CMediusers::get($praticienId);

        $lockMode   = CAppUI::gconf("dPccam codage lock_codage_ccam");
        $isSamePrat = ($currentUser->_id === $praticien->_id);

        // checkPassword() a des effets de bord (accès DB / tentative de bind LDAP) :
        // il doit rester évalué en court-circuit, uniquement quand le verrou est
        // par mot de passe et qu'il ne s'agit pas du praticien courant.
        if (
            $lockMode != 'password'
            || ($lockMode == 'password'
                && ($isSamePrat || CUser::checkPassword($praticien->_user_username, $userPassword)))
        ) {
            $object = null;

            foreach ($codages as $eachCodage) {
                $eachCodage->locked = $isLock;

                if (!$eachCodage->store()) {
                    $eachCodage->loadActesCCAM();
                    $object = $eachCodage->loadCodable();

                    foreach ($eachCodage->_ref_actes_ccam as $eachActe) {
                        $eachActe->signe           = $isLock;
                        $eachActe->_no_synchro_eai = true;
                        $eachActe->store();
                    }
                }
            }

            /* Export des actes */
            if ($object && $doExport && $isLock) {
                $wasFactured = $object->facture;

                /* Si l'objet est déjà facturé, on remet facture à 0 puis à 1 : les actes ne sont
                 * exportés que lorsque le champ facture passe effectivement à 1.
                 */
                if ($wasFactured) {
                    $object->facture         = '0';
                    $object->_no_synchro_eai = true;
                    $object->store(false);
                }

                $object->_force_sent     = true;
                $object->_no_synchro_eai = false;
                $object->facture         = '1';
                $object->loadLastLog();

                try {
                    $object->store(false);

                    $object->loadRefsActesCCAM();
                    foreach ($codages as $eachCodage) {
                        foreach ($eachCodage->_ref_actes_ccam as $eachActe) {
                            $eachActe->_no_synchro_eai = true;
                            $eachActe->sent            = 1;
                            $eachActe->store();
                        }
                    }

                    $object->loadRefsCodagesCCAM();
                    $allLocked = true;
                    foreach ($object->_ref_codages_ccam as $codagesForPrat) {
                        foreach ($codagesForPrat as $eachCodage) {
                            if (!$eachCodage->locked) {
                                $allLocked = false;
                            }
                        }
                    }

                    if (!$allLocked) {
                        $object->facture         = '0';
                        $object->_no_synchro_eai = true;
                        $object->_force_sent     = false;
                        $object->store(false);
                    }
                } catch (CMbException $e) {
                    // En cas d'erreur, on restaure l'état de facturation antérieur
                    if (!$wasFactured) {
                        $object->facture         = '0';
                        $object->_no_synchro_eai = true;
                        $object->_force_sent     = false;
                        $object->store(false);
                    }
                }
            } elseif (!$isLock && $object->facture) {
                $object->facture         = 0;
                $object->_ref_actes_ccam = null;
                $object->loadRefsActesCCAM();

                foreach ($object->_ref_actes_ccam as $eachActe) {
                    $eachActe->sent            = 0;
                    $eachActe->_no_synchro_eai = true;
                    $eachActe->store();
                }
            }

            CAppUI::setMsg(
                $isLock ? 'CCodageCCAM-msg-codage_locked' : 'CCodageCCAM-msg-codage_unlocked',
                UI_MSG_OK
            );
            echo CAppUI::getMsg();
        } elseif (
            $lockMode == 'password' && !$isSamePrat
            && !CUser::checkPassword($praticien->_user_username, $userPassword)
        ) {
            CAppUI::setMsg("CUser-user_password-nomatch", UI_MSG_ERROR);
            echo CAppUI::getMsg();
        }
    }

    public function duplicateCodage(): void
    {
        $this->checkPermRead();

        $codageId = CView::get('codage_id', 'ref class|CCodageCCAM');
        $acteId   = CView::get('acte_id', 'ref class|CActeCCAM');

        CView::checkin();

        $codage = new CCodageCCAM();
        $codage->load($codageId);

        if (!$codage->_id) {
            return;
        }

        $codage->canDo();
        if (!$codage->_can->edit) {
            CAppUI::accessDenied();
        }

        $codage->loadCodable();
        $codage->loadPraticien()->loadRefFunction();
        $codage->_ref_praticien->isAnesth();
        $codage->loadActesCCAM();
        $codage->checkRules();
        // Chargement du codable et des actes disponibles
        $codage->loadCodable();

        foreach ($codage->_ref_actes_ccam as $eachActe) {
            $eachActe->getTarif();
            $phase = $eachActe->_ref_code_ccam->activites[$eachActe->code_activite]->phases[$eachActe->code_phase];

            /* Vérification des modificateurs codés */
            if (property_exists($phase, '_modificateurs')) {
                foreach ($phase->_modificateurs as $modificateur) {
                    $modificateur->_checked = (strpos($eachActe->modificateurs, $modificateur->code) !== false)
                        ? $modificateur->code
                        : null;
                }
            }

            CCodageCCAM::precodeModifiers($phase->_modificateurs, $eachActe, $codage->_ref_codable);
        }

        $this->renderSmarty('inc_duplicate_codage.tpl', [
            'codage'  => $codage,
            'acte_id' => $acteId,
        ]);
    }

    public function massCoding(): void
    {
        $this->checkPermRead();

        $objectsGuid   = CView::post("objects_guid", "str");
        $chirId        = CView::post("chir_id", "ref class|CMediusers");
        $libelle       = CView::post("libelle", "str");
        $protocoleId   = CView::post("protocole_id", "ref class|CProtocole");
        $modelCodageId = CView::post("model_codage_id", "ref class|CModelCodage");
        $objectClass   = CView::post('object_class', 'enum list|COperation|CSejour-seances');

        CView::checkin();

        $chir      = CMediusers::get($chirId);
        $chir->loadPraticiens(PERM_DENY);

        $modelCodage = new CModelCodage();

        $protocoleId = $protocoleId ? (int) $protocoleId : null;

        if (!$modelCodage->load($modelCodageId)) {
            $guidList = explode("|", $objectsGuid);
            if (count($guidList) != 0) {
                /** @var CCodable $firstObject */
                $firstObject                = CMbObject::loadFromGuid($guidList[0]);
                $modelCodage->libelle       = $libelle;
                $modelCodage->praticien_id  = $chir->_id;
                $modelCodage->objects_guid  = $objectsGuid;
                $modelCodage->setFromObject($firstObject, $protocoleId);
            }
        }

        $modelCodage->_objects_count = count(explode("|", $modelCodage->objects_guid));
        $modelCodage->loadRefsCodagesCCAM();

        $codages = null;
        if (array_key_exists($chirId, $modelCodage->_ref_codages_ccam)) {
            $codages = $modelCodage->_ref_codages_ccam[$chirId];
            foreach ($codages as $eachCodage) {
                $eachCodage->loadPraticien()->loadRefFunction();
                $eachCodage->_ref_praticien->isAnesth();
                $eachCodage->loadActesCCAM();
                $eachCodage->getTarifTotal();
                $eachCodage->checkRules();

                foreach ($eachCodage->_ref_actes_ccam as $eachActe) {
                    $eachActe->getTarif();
                }

                // Chargement du codable et des actes disponibles
                $eachCodage->loadCodable();
            }
        }

        $modelCodage->loadExtCodesCCAM();
        $modelCodage->loadRefsActesCCAM();
        $praticien = $modelCodage->loadRefPraticien();
        $praticien->loadRefFunction();
        $praticien->isAnesth();
        $modelCodage->getActeExecution();
        $modelCodage->loadPossibleActes($chirId);

        $this->renderSmarty('inc_mass_coding.tpl', [
            'subject'      => $modelCodage,
            'codages'      => $codages,
            'praticien'    => $praticien,
            'object_class' => $objectClass,
            'acte_ngap'    => CActeNGAP::createEmptyFor($modelCodage),
        ]);
    }

    public function duplicateCodageCcam(): void
    {
        $this->checkPermEdit();

        $codageId     = CView::post("codage_id", "ref class|CCodageCCAM");
        $acteList     = CView::post("actes", "str");
        $singleDate   = CView::post("date", "date");
        $multipleDate = CView::post("multiple_date", "str");
        $dateKind     = CView::post("type_of_date", "str default|one_date");

        CView::checkin();

        $acteList = explode("|", $acteList);

        $codage = new CCodageCCAM();

        if (!$codage->load($codageId)) {
            return;
        }

        $codage->canDo();

        if (!$codage->_can->edit) {
            CAppUI::accessDenied();
        }

        $codable = $codage->loadCodable();
        $codable->loadExtCodesCCAM();
        $codable->loadRefsActesCCAM();
        $codage->loadActesCCAM();

        /* Comptabilise les actes non codés, regroupés par code / activité / phase */
        $uncodedActs = [];
        $remainingCoded = $codable->_ref_actes_ccam;
        foreach ($codable->_ext_codes_ccam as $extCode) {
            foreach ($extCode->activites as $activite) {
                foreach ($activite->phases as $phase) {
                    $matched = false;
                    foreach ($remainingCoded as $codedActe) {
                        if ($codedActe->code_acte == $extCode->code && !$matched) {
                            if (
                                $codedActe->code_activite == $activite->numero
                                && $codedActe->code_phase == $phase->phase
                            ) {
                                $matched = true;
                                unset($remainingCoded[$codedActe->_id]);
                            }
                        }
                    }

                    if (!$matched) {
                        $key = "$extCode->code-$activite->numero-$phase->phase";
                        $uncodedActs[$key] = array_key_exists($key, $uncodedActs) ? $uncodedActs[$key] + 1 : 1;
                    }
                }
            }
        }

        if ($dateKind != "one_date") {
            $multipleDate = explode(",", $multipleDate);
            $dayCount     = count($multipleDate);
        } else {
            $singleDate = CValue::first($singleDate, $codage->_ref_codable->sortie);
            $dayCount   = CMbDT::daysRelative(
                $codage->date . ' 00:00:00',
                CMbDT::format($singleDate, '%Y-%m-%d 00:00:00')
            );
        }

        for ($index = 1; $index <= $dayCount; $index++) {
            if ($dateKind != "one_date") {
                $targetDate = $multipleDate[$index - 1];
            } else {
                $targetDate = CMbDT::date("+$index DAYS", $codage->date);
            }

            $newCodage                = new CCodageCCAM();
            $newCodage->praticien_id  = $codage->praticien_id;
            $newCodage->codable_class = $codage->codable_class;
            $newCodage->codable_id    = $codage->codable_id;
            $newCodage->date          = $targetDate;

            $newCodage->loadMatchingObject();

            if ($codage->association_mode == 'user_choice') {
                $newCodage->association_mode = $codage->association_mode;
                $newCodage->association_rule = $codage->association_rule;
            }

            $newCodage->store();

            foreach ($acteList as $currentActeId) {
                if (!array_key_exists($currentActeId, $codage->_ref_actes_ccam)) {
                    continue;
                }

                $sourceActe = $codage->_ref_actes_ccam[$currentActeId];

                /* S'il ne reste aucun acte non coté pour ce code ccam, on l'ajoute au codable */
                $key = "$sourceActe->code_acte-$sourceActe->code_activite-$sourceActe->code_phase";
                if (array_key_exists($key, $uncodedActs)) {
                    $uncodedActs[$key]--;
                    if ($uncodedActs[$key] == 0) {
                        unset($uncodedActs[$key]);
                    }
                } else {
                    $codable->codes_ccam .= "|$sourceActe->code_acte";
                    $codable->updateFormFields();
                    $codable->updateCCAMPlainField();
                    $codable->store();
                }

                $sourceActe->execution   = "$targetDate " . CMbDT::time(null, $sourceActe->execution);
                $sourceActe->_ref_object = $codable;
                $sourceActe->_id         = null;
                $sourceActe->store();
            }
        }
    }

    public function setDentsCodage(): void
    {
        $this->checkPermRead();

        $view        = CView::get('acte_view', 'str');
        $code        = CView::get('code', 'str');
        $activite    = CView::get('activite', 'str');
        $phase       = CView::get('phase', 'str');
        $day         = CView::get('date', ['dateTime', 'default' => CMbDT::dateTime()]);
        $nullable    = CView::get('nullable', 'bool default|0');

        CView::checkin();

        $acte = new CActeCCAM();
        $acte->code_acte     = $code;
        $acte->code_activite = $activite;
        $acte->code_phase;
        $acte->execution     = $day;
        $acte->loadRefCodeCCAM();

        $datedCode = CDatedCodeCCAM::get($code, $day);
        $activite  = $datedCode->activites[$activite];
        $phase     = $activite->phases[$phase];

        $dents      = CDentCCAM::loadList();
        $listeDents = reset($dents);

        $this->renderSmarty('inc_set_dents.tpl', [
            'acte_view'   => $view,
            'acte'        => $acte,
            'phase'       => $phase,
            'liste_dents' => $listeDents,
            'nullable'    => $nullable,
        ]);
    }

    public function updateActsCounter(): void
    {
        $this->checkPermRead();

        $subjectGuid = CView::get('subject_guid', 'guid class|CCodable');
        $type        = CView::get('type', 'str default|ccam');
        CView::checkin();

        $count = 0;
        if ($subjectGuid) {
            /** @var CCodable $subject */
            $subject = CMbObject::loadFromGuid($subjectGuid);
            switch ($type) {
                case 'ngap':
                    $subject->loadRefsActesNGAP();
                    $count = count($subject->_ref_actes_ngap);
                    break;
                case 'ccam':
                default:
                    $subject->loadRefsActesCCAM();
                    $count = count($subject->_ref_actes_ccam);
            }
        }

        $this->renderSmarty('inc_acts_counter.tpl', [
            'count'        => $count,
            'subject_guid' => $subjectGuid,
            'type'         => $type,
        ]);
    }

    public function updateMontantBase(): void
    {
        $this->checkPermAdmin();

        $day          = CView::get('date', 'str');
        $step         = CView::get('step', 'num default|100');
        $codableClass = CView::get('codable_class', 'str');

        $offset = CView::get('start_update_montant', 'num default|0', true);

        CView::checkin();

        $acte  = new CActeCCAM();
        $where = [
            'execution'        => " > '$day 00:00:00'",
            'code_association' => ' > 1',
        ];

        if ($codableClass) {
            $where['object_class'] = " = '$codableClass'";
        }
        $total = $acte->countList($where);

        /** @var CActeCCAM[] $acts */
        $acts = $acte->loadList($where, 'execution DESC', "$offset, $step");

        foreach ($acts as $eachActe) {
            $eachActe->_calcul_montant_base = 1;
            $eachActe->store();
        }

        CSessionHandler::start();
        CView::setSession('start_update_montant', $offset + $step);
        CSessionHandler::writeClose();

        $this->renderSmarty('inc_status_update_montant.tpl', [
            'total'   => $total,
            'current' => $offset + $step,
        ]);
    }

    public function applyModelCodage(): void
    {
        set_time_limit(300);

        $this->checkPermEdit();

        $modelCodageId = CView::post('model_codage_id', 'ref class|CModelCodage');
        $doApply       = CView::post('apply', 'bool default|1');
        $doExport      = CView::post('export', 'bool default|0');
        $forceMessage  = CView::post('force_message', 'bool default|0');
        $objectClass   = CView::post('object_class', 'enum list|COperation|CSejour-seances');

        CView::checkin();

        $model = new CModelCodage();
        $model->load($modelCodageId);

        $model->loadRefPraticien();
        $model->loadRefsActesCCAM();
        $model->loadRefsActesNGAP();
        $model->loadRefsCodagesCCAM();

        /** @var CCodageCCAM[] $modelCodages */
        $modelCodages = $model->_ref_codages_ccam[$model->praticien_id];
        $objects      = $model->loadObjects();

        $modelActs = [
            'ccam' => [],
            'ngap' => [],
        ];

        $modelCodes = explode('|', $model->codes_ccam);

        foreach ($model->_ref_actes_ccam as $modelActe) {
            $key = "$modelActe->code_acte-$modelActe->code_activite-$modelActe->code_phase";
            if (!array_key_exists($key, $modelActs)) {
                $modelActs['ccam'][$key] = [];
            }

            $modelActs['ccam'][$key][] = $modelActe;
        }

        foreach ($model->_ref_actes_ngap as $modelActe) {
            $modelActs['ngap'][] = $modelActe;
        }

        $successCount = 0;

        if ($doApply) {
            foreach ($objects as $object) {
                $error = 0;
                $object->loadRefsCodagesCCAM();
                $object->loadRefsActesCCAM();
                $object->getActeExecution();
                $localModelActs = $modelActs;

                $objectCodes = $object->codes_ccam != '' ? explode('|', $object->codes_ccam) : [];

                $diff        = array_diff($objectCodes, $modelCodes);
                $mergedCodes = (empty($objectCodes) || empty($diff))
                    ? $modelCodes
                    : array_merge($modelCodes, $diff);

                $object->codes_ccam  = implode('|', $mergedCodes);
                $object->_codes_ccam = $mergedCodes;

                $storeMsg = $object->_class == 'COperation' ? $object->store(false) : $object->store();

                if ($storeMsg) {
                    continue;
                }

                /* Vérification de l'affectation des dépassements d'honoraires */
                $depassementDone       = false;
                $depassementAnesthDone = false;
                foreach ($object->_ref_actes_ccam as $currentActe) {
                    if ($currentActe->code_activite == 1 && $currentActe->montant_depassement) {
                        $depassementDone = true;
                    } elseif ($currentActe->code_activite == 4 && $currentActe->montant_depassement) {
                        $depassementAnesthDone = true;
                    }
                }

                switch ($object->_class) {
                    case 'COperation':
                        $execDate = $object->date;
                        break;
                    case 'CConsultation':
                        $object->loadRefPlageConsult();
                        $execDate = $object->_date;
                        break;
                    default:
                        $execDate = CMbDT::date();
                }

                foreach ($modelCodages as $modelCodage) {
                    $codage                  = new CCodageCCAM();
                    $codage->codable_class   = $object->_class;
                    $codage->codable_id      = $object->_id;
                    $codage->praticien_id    = $modelCodage->praticien_id;
                    $codage->activite_anesth = $modelCodage->activite_anesth;
                    $codage->date            = $execDate;

                    $codage->loadMatchingObject();

                    $codage->association_mode = $modelCodage->association_mode;
                    $codage->association_rule = $modelCodage->association_rule;

                    if ($error = $codage->store()) {
                        break;
                    }
                }

                if ($error) {
                    continue;
                }

                foreach ($object->_ref_actes_ccam as $currentActe) {
                    $key = "$currentActe->code_acte-$currentActe->code_activite-$currentActe->code_phase";

                    if ($currentActe->executant_id != $model->praticien_id) {
                        continue;
                    }

                    if (!array_key_exists($key, $localModelActs['ccam'])) {
                        if ($currentActe->montant_depassement && $currentActe->code_activite == 1) {
                            $depassementDone = false;
                        } elseif ($currentActe->montant_depassement && $currentActe->code_activite == 4) {
                            $depassementAnesthDone = false;
                        }

                        $currentActe->delete();
                        continue;
                    }

                    /** @var CActeCCAM $refModelActe */
                    $refModelActe = reset($localModelActs['ccam'][$key]);

                    $currentActe->code_association = $refModelActe->code_association;
                    $currentActe->code_extension   = $refModelActe->code_extension;

                    /* Affectation des dépassements en priorité aux actes existants */
                    if (!$depassementDone && $currentActe->code_activite == 1) {
                        if ($object->_acte_depassement) {
                            $currentActe->montant_depassement = $object->_acte_depassement;
                            $depassementDone                  = true;
                        } elseif ($refModelActe->montant_depassement) {
                            $currentActe->montant_depassement = $refModelActe->montant_depassement;
                            $depassementDone                  = true;
                        }
                    } elseif (!$depassementAnesthDone && $currentActe->code_activite == 4) {
                        if ($object->_acte_depassement_anesth) {
                            $currentActe->montant_depassement = $object->_acte_depassement_anesth;
                            $depassementAnesthDone            = true;
                        } elseif ($refModelActe->montant_depassement) {
                            $currentActe->montant_depassement = $refModelActe->montant_depassement;
                            $depassementAnesthDone            = true;
                        }
                    }

                    $currentActe->motif_depassement      = $refModelActe->motif_depassement;
                    $currentActe->facturable             = $refModelActe->facturable;
                    $currentActe->extension_documentaire = $refModelActe->extension_documentaire;
                    $currentActe->rembourse              = $refModelActe->rembourse;
                    $currentActe->execution              = $object->_acte_execution;
                    $currentActe->modificateurs          = $refModelActe->modificateurs;
                    $currentActe->position_dentaire      = $refModelActe->position_dentaire;
                    $currentActe->commentaire            = $refModelActe->commentaire;
                    $currentActe->precodeModifiers();

                    if ($error = $currentActe->store()) {
                        break;
                    }

                    if (count($localModelActs['ccam'][$key]) == 1) {
                        unset($localModelActs['ccam'][$key]);
                    } else {
                        unset($localModelActs['ccam'][$key][0]);
                    }
                }

                if ($error) {
                    continue;
                }

                foreach ($localModelActs['ccam'] as $code => $modelActeGroup) {
                    foreach ($modelActeGroup as $refModelActe) {
                        $newActe                   = new CActeCCAM();
                        $newActe->object_class     = $object->_class;
                        $newActe->object_id        = $object->_id;
                        $newActe->code_acte        = $refModelActe->code_acte;
                        $newActe->code_activite    = $refModelActe->code_activite;
                        $newActe->code_phase       = $refModelActe->code_phase;
                        $newActe->code_extension   = $refModelActe->code_extension;
                        $newActe->executant_id     = $refModelActe->executant_id;
                        $newActe->code_association  = $refModelActe->code_association;

                        /* Affectation des dépassements */
                        if (!$depassementDone && $newActe->code_activite == 1) {
                            if ($object->_acte_depassement) {
                                $newActe->montant_depassement = $object->_acte_depassement;
                                $depassementDone              = true;
                            } elseif ($refModelActe->montant_depassement) {
                                $newActe->montant_depassement = $refModelActe->montant_depassement;
                                $depassementDone              = true;
                            }
                        } elseif (!$depassementAnesthDone && $newActe->code_activite == 4) {
                            if ($object->_acte_depassement_anesth) {
                                $newActe->montant_depassement = $object->_acte_depassement_anesth;
                                $depassementAnesthDone        = true;
                            } elseif ($refModelActe->montant_depassement) {
                                $newActe->montant_depassement = $refModelActe->montant_depassement;
                                $depassementAnesthDone        = true;
                            }
                        }

                        $newActe->motif_depassement      = $refModelActe->motif_depassement;
                        $newActe->facturable             = $refModelActe->facturable;
                        $newActe->extension_documentaire = $refModelActe->extension_documentaire;
                        $newActe->rembourse              = $refModelActe->rembourse;
                        $newActe->execution              = $object->_acte_execution;
                        $newActe->modificateurs          = $refModelActe->modificateurs;
                        $newActe->position_dentaire      = $refModelActe->position_dentaire;
                        $newActe->commentaire            = $refModelActe->commentaire;
                        $newActe->precodeModifiers();

                        if ($newActe->code_activite == 4) {
                            if (!$newActe->extension_documentaire) {
                                $newActe->extension_documentaire =
                                    $object->getExtensionDocumentaire($newActe->executant_id);
                            }

                            /* Pour les actes d'activité 4, la date d'exécution reprend celle de
                               l'activité 1 lorsque celle-ci est codée */
                            $acteChir = $newActe->loadActeActiviteAssociee();
                            if ($acteChir->_id) {
                                $newActe->execution = $acteChir->execution;
                                if ($acteChir->code_extension) {
                                    $newActe->code_extension = $acteChir->code_extension;
                                }
                            }
                        }

                        if ($error = $newActe->store()) {
                            break;
                        }
                    }
                }

                foreach ($localModelActs['ngap'] as $refModelActe) {
                    $newActe                      = new CActeNGAP();
                    $newActe->object_class        = $object->_class;
                    $newActe->object_id           = $object->_id;
                    $newActe->code                = $refModelActe->code;
                    $newActe->quantite            = $refModelActe->quantite;
                    $newActe->coefficient         = $refModelActe->coefficient;
                    $newActe->montant_depassement = $refModelActe->montant_depassement;
                    $newActe->montant_base        = $refModelActe->montant_base;
                    $newActe->demi                = $refModelActe->demi;
                    $newActe->complement          = $refModelActe->complement;
                    $newActe->executant_id        = $refModelActe->executant_id;
                    $newActe->lettre_cle          = $refModelActe->lettre_cle;
                    $newActe->facturable          = $refModelActe->facturable;
                    $newActe->lieu                = $refModelActe->lieu;
                    $newActe->exoneration         = $refModelActe->exoneration;
                    $newActe->gratuit             = $refModelActe->gratuit;
                    $newActe->qualif_depense      = $refModelActe->qualif_depense;
                    $newActe->accord_prealable    = $refModelActe->accord_prealable;
                    $newActe->date_demande_accord = $refModelActe->date_demande_accord;
                    $newActe->reponse_accord      = $refModelActe->reponse_accord;
                    $newActe->execution           = $object->_acte_execution;

                    if ($error = $newActe->store()) {
                        break;
                    }
                }

                if ($error) {
                    continue;
                }

                if ($doExport) {
                    $codage->locked = 1;
                    $codage->store();

                    $object->facture     = 1;
                    $object->_force_sent = true;
                    $object->loadLastLog();

                    try {
                        $object->store();

                        $object->_ref_actes_ccam = null;
                        $object->loadRefsCodagesCCAM();
                        $object->loadRefsActesCCAM();

                        foreach ($object->_ref_actes_ccam as $currentActe) {
                            $currentActe->sent = 1;
                            $currentActe->store();
                        }

                        $allLocked = true;

                        foreach ($object->_ref_codages_ccam as $codagesForPrat) {
                            foreach ($codagesForPrat as $codage) {
                                if (!$codage->locked) {
                                    $allLocked = false;
                                    break 2;
                                }
                            }
                        }

                        if (!$allLocked) {
                            $object->facture         = 0;
                            $object->_no_synchro_eai = true;
                            $object->store(false);
                        }
                    } catch (CMbException $e) {
                        // En cas d'erreur, on restaure l'état de facturation antérieur
                        $object->facture = 0;
                        $object->store();
                        $error = 1;
                    }
                }

                if (!$error) {
                    $successCount++;
                }
            }
        }

        $model->delete();

        $objectLabel = $objectClass == 'CSejours-seances' ? 'séances' : 'interventions';

        if ($forceMessage) {
            CAppUI::stepAjax('CActeNGAP-applied', UI_MSG_OK);
        } elseif (!$doApply && !$doExport) {
            CAppUI::stepAjax('Codage en masse annulé', UI_MSG_OK);
        } elseif ($successCount == count($objects)) {
            CAppUI::stepAjax("Le codage a été appliqué avec succÎ¸s Î° $successCount $objectLabel", UI_MSG_OK);
        } else {
            $failureCount = count($objects) - $successCount;
            CAppUI::stepAjax(
                "Le codage a été appliqué avec succÎ¸s Î° $successCount $objectLabel,"
                . " $failureCount $objectLabel marquées en erreurs.",
                UI_MSG_WARNING
            );
        }
    }

    /**
     * @throws Exception
     */
    public function showChooseDate(): void
    {
        $this->checkPermRead();
        $codageGuid  = CView::get('codage_id', 'guid');
        $codableGuid = CView::get("codable_guid", 'guid');

        CView::checkin();
        $codable = null;

        if ($codableGuid) {
            $codable = CMbObject::loadFromGuid($codableGuid);
        }

        $codage = CMbObject::loadFromGuid($codageGuid);
        if ($codage->_id && $codage instanceof CCodageCCAM) {
            $codage->loadCodable();
        }

        $this->renderSmarty("choose_date_duplication.tpl", [
            "codage"  => $codage,
            "codable" => $codable,
        ]);
    }
}
