<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Ccam\Controllers\Legacy;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbObject;
use Ox\Core\CView;
use Ox\Core\Module\CModule;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Cabinet\CActeNGAP;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Cabinet\CTarif;
use Hospilibre\Ccam\CCodable;
use Hospilibre\Ccam\CDentCCAM;
use Hospilibre\Ccam\CDevisCodage;
use Hospilibre\Ccam\CDevisCodageToPdfFile;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\Files\CFilesCategory;
use Hospilibre\Lpp\CActeLPP;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\COperation;

class DevisLegacyController extends CLegacyController
{
    /**
     * Prépare l'écran de codage d'un devis rattaché à un objet codable.
     *
     * @throws \Exception
     */
    public function codageDevis(): void
    {
        $this->checkPermRead();

        $codableId    = CView::get('object_id', 'ref meta|object_class');
        $codableClass = CView::get('object_class', 'str');

        CView::checkin();

        /** @var CCodable $codable */
        $codable = CMbObject::loadFromGuid("$codableClass-$codableId");

        CAccessMedicalData::logAccess($codable);

        $codable->loadRefPraticien();

        $devisCodage                = new CDevisCodage();
        $devisCodage->codable_class = $codable->_class;
        $devisCodage->codable_id    = $codable->_id;
        $devisCodage->loadMatchingObject();

        if (!$devisCodage->_id) {
            $devisCodage->event_type   = $codable->_class;
            $devisCodage->patient_id   = $codable->loadRefPatient()->_id;
            $devisCodage->praticien_id = $codable->loadRefPraticien()->_id;

            switch ($codable->_class) {
                case 'CConsultation':
                    $devisCodage->libelle = $codable->motif;
                    $codable->loadRefPlageConsult();
                    $devisCodage->date = $codable->_date;
                    break;
                case 'COperation':
                    $devisCodage->libelle = $codable->libelle;
                    $devisCodage->date    = $codable->date;
                    break;
            }

            $devisCodage->codes_ccam = $codable->codes_ccam;
        }

        $this->renderSmarty('inc_devis_codage.tpl', [
            'devis' => $devisCodage,
        ]);
    }

    public function editDevis(): void
    {
        $this->checkPermEdit();

        $devisId = CView::get('devis_id', 'ref class|CDevisCodage');
        $action  = CView::get('action', 'str default|open');

        CView::checkin();

        $devisCodage = new CDevisCodage();

        if ($devisId) {
            $devisCodage->load($devisId);
            $devisCodage->loadRefCodable();
        }

        if ($devisCodage->_id) {
            $devisCodage->canDo();
            $devisCodage->loadRefPatient();
            $devisCodage->loadRefPraticien();
            $devisCodage->getActeExecution();
            $devisCodage->countActes();
            $devisCodage->loadRefsActes();

            foreach ($devisCodage->_ref_actes as $acte) {
                $acte->loadRefExecutant();
            }

            $devisCodage->loadExtCodesCCAM();
            $devisCodage->loadPossibleActes();

            // Récupération des règles de codage
            $devisCodage->loadRefsCodagesCCAM();
            foreach ($devisCodage->_ref_codages_ccam as $codagesParPraticien) {
                foreach ($codagesParPraticien as $codage) {
                    $codage->loadPraticien()->loadRefFunction();
                    $codage->loadActesCCAM();
                    $codage->getTarifTotal();
                    foreach ($codage->_ref_actes_ccam as $acteCcam) {
                        $acteCcam->getTarif();
                    }
                }
            }
        }

        // Praticiens disponibles
        $anesthesistes = new CMediusers();
        $anesthesistes = $anesthesistes->loadAnesthesistes(PERM_EDIT);

        $chirurgiens = CConsultation::loadPraticiens(PERM_EDIT);

        // Acte NGAP vierge
        $acteNgap = CActeNGAP::createEmptyFor($devisCodage);

        // Dents CCAM
        $listesDents = CDentCCAM::loadList();
        $listeDents  = reset($listesDents);

        /* LPP */
        $acteLpp = null;
        if (CModule::getActive('lpp') && CAppUI::gconf('lpp General cotation_lpp')) {
            $devisCodage->loadRefsActesLPP();

            foreach ($devisCodage->_ref_actes_lpp as $acteLppRef) {
                $acteLppRef->loadRefExecutant();
                $acteLppRef->_ref_executant->loadRefFunction();
            }

            $acteLpp = CActeLPP::createFor($devisCodage);
        }

        $currentUser = CMediusers::get();
        $currentUser->isPraticien();
        $currentUser->isProfessionnelDeSante();

        $tarifs = CTarif::loadTarifsUser($devisCodage->_ref_praticien);

        $categorieFichier        = new CFilesCategory();
        $categorieFichier->class = "CDevisCodage";
        $categorieFichier->loadMatchingObjectEsc();

        if (!$categorieFichier->_id) {
            CAppUI::setMsg(
                CAppUI::tr('CDevisCodage-configure a file category to generate pdf'),
                UI_MSG_ALERT
            );
            echo CAppUI::getMsg();
        }

        $template = 'open' === $action ? "inc_edit_devis_container.tpl" : "inc_edit_devis.tpl";

        $this->renderSmarty($template, [
            'devis'       => $devisCodage,
            'acte_ngap'   => $acteNgap,
            'acte_lpp'    => $acteLpp,
            'liste_dents' => $listeDents,
            'listAnesths' => $anesthesistes,
            'listChirs'   => $chirurgiens,
            'user'        => $currentUser,
            'tarifs'      => $tarifs,
        ]);
    }

    public function listDevis(): void
    {
        $this->checkPermRead();

        $eventClass = CView::get('object_class', "str");
        $eventId    = CView::get('object_id', "ref class|$eventClass");
        CView::checkin();

        $event = CMbObject::loadFromGuid("$eventClass-$eventId");

        if ($event instanceof COperation || $event instanceof CConsultation) {
            $event->loadRefPraticien();
            $event->loadRefPatient();
        }

        $devisList = $event->loadBackRefs('devis_codage', 'creation_date ASC', null, 'devis_codage_id');

        foreach ($devisList as $devisItem) {
            $devisItem->updateFormFields();
            $devisItem->countActes();
        }

        $this->renderSmarty('inc_list_devis.tpl', [
            'object'     => $event,
            'list_devis' => $devisList,
        ]);
    }

    public function printDevis(): void
    {
        $this->checkPermRead();

        $devisId = CView::get('devis_id', 'ref class|CDevisCodage');

        CView::checkin();

        $devisCodage = new CDevisCodage();
        $devisCodage->load($devisId);

        if (!$devisCodage->_id) {
            return;
        }

        $devisCodage->updateFormFields();
        $devisCodage->loadRefPatient();
        $devisCodage->loadRefCodable();
        $devisCodage->loadRefPraticien();
        $devisCodage->_ref_praticien->loadRefFunction();
        $devisCodage->getActeExecution();
        $devisCodage->countActes();
        $devisCodage->loadRefsActes();
        $devisCodage->loadRefsFraisDivers();

        foreach ($devisCodage->_ref_actes_ccam as $acteCcam) {
            $acteCcam->getTarif();
        }
        foreach ($devisCodage->_ref_frais_divers as $frais) {
            $frais->loadRefType();
        }

        $modeleDocument = CCompteRendu::getSpecialModel($devisCodage->_ref_praticien, $devisCodage->_class, '[DEVIS]');

        if ($modeleDocument->_id) {
            CCompteRendu::streamDocForObject($modeleDocument, $devisCodage);
        }

        // Persistance du devis au format PDF
        try {
            CDevisCodageToPdfFile::generateFileFromDevisCodage($devisCodage, true);
        } catch (Exception $e) {
        }

        $this->renderSmarty('print_devis_codage.tpl', ['devis' => $devisCodage]);
    }
}
