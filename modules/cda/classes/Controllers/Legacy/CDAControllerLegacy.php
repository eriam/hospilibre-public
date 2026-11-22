<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Cda\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbException;
use Ox\Core\CMbObject;
use Ox\Core\CSmartyDP;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Interop\Cda\CCDAFactory;
use Ox\Interop\Cda\CCdaTools;
use Ox\Interop\Eai\CItemReport;
use Ox\Interop\Xds\Factory\CXDSFactory;
use Ox\Interop\Xds\XDM\CXDMRepository;
use Hospilibre\Admin\CAccessMedicalData;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Cabinet\Vaccination\CInjection;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\Files\CFile;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\PlanningOp\CSejour;

class CDAControllerLegacy extends CLegacyController
{
    public function ajax_create_cda_vsm(): void
    {
        /** @var CSejour|CConsultation $target */
        $target      = $this->loadObject();
        $categoryId  = CView::get('file_category_id', 'ref class|CFilesCategory');
        CView::checkin();

        // Le dossier médical doit exister avant toute génération de CDA
        $patient = $target->loadRefPatient();
        CDossierMedical::dossierMedicalId($patient->_id, $patient->_class);

        CAccessMedicalData::logAccess($target);

        // Construction du dépôt XDM pour la VSM
        $repository = new CXDMRepository($target, CCDAFactory::TYPE_VSM, CXDSFactory::TYPE_ANS);

        $cdaOptions = [];
        if ($categoryId) {
            $cdaOptions['file_category_id'] = $categoryId;
        }

        $this->render($repository, 'VSM', $cdaOptions);

        // Produit le PDF associé à la VSM
        $cdaFile = $repository->getFileCDA();
        CCdaTools::generatePdfVSM($target, $cdaFile);
    }

    public function ajax_create_vaccination(): void
    {
        /** @var CSejour|CConsultation $target */
        $target      = $this->loadObject();
        $injectionId = CView::get("injection_id", "str");
        CView::checkin();

        if (!$injectionId) {
            CAppUI::stepAjax("injection_id ne peut pas Îºtre nul", UI_MSG_ERROR);
        }

        $injection = new CInjection();
        $injection->load($injectionId);
        if (!$injection->_id) {
            CAppUI::stepAjax("Impossible de charger l'injection", UI_MSG_ERROR);
        }

        // Rattachement de l'injection à l'objet courant
        $target->addToStore(CInjection::class, $injection);

        $repository = new CXDMRepository($target, CCDAFactory::TYPE_VACCINATION_NOTE, CXDSFactory::TYPE_ANS);

        $this->render($repository, 'Vaccination');
    }

    public function ajax_create_ldl(): void
    {
        /** @var CSejour|CConsultation $target */
        $target  = $this->loadObject();
        $ldlType = CView::get("type_ldl", "str");
        CView::checkin();

        $sesMismatch = $ldlType == CCDAFactory::TYPE_LDL_SES && !$target instanceof CSejour;
        $eesMismatch = $ldlType == CCDAFactory::TYPE_LDL_EES
            && !$target instanceof CConsultation && !$target instanceof CSejour;

        if ($sesMismatch || $eesMismatch) {
            CAppUI::stepAjax("ObjectClass incorrect", UI_MSG_ERROR);
        }

        $patient = $target->loadRefPatient();

        if (!$patient || !$patient->_id) {
            CAppUI::stepAjax('common-error-Object not found', UI_MSG_ERROR);
        }

        // Le dossier médical doit exister avant toute génération de CDA
        $patient = $target->loadRefPatient();
        CDossierMedical::dossierMedicalId($patient->_id, $patient->_class);

        // Construction du dépôt XDM pour la lettre de liaison
        $repository = new CXDMRepository($target, $ldlType, CXDSFactory::TYPE_ANS);

        $this->render($repository, 'Lettre de liaison');
    }

    public function ajax_create_IHE_XDM_CDA_LVL1(): void
    {
        /** @var CFile|CCompteRendu $target */
        $target = $this->loadDocumentItem();

        // Construction du dépôt XDM (niveau 1)
        $repository = new CXDMRepository($target, CCDAFactory::TYPE_ANS_L1, CXDSFactory::TYPE_ANS);

        $this->render($repository, 'CDA Level 1');
    }

    public function ajax_create_IHE_XDM_CDA_LVL3(): void
    {
        $fileId = CView::get('file_id', 'ref class|CFile');
        CView::checkin();

        /** @var CFile|CCompteRendu $file */
        $file = CMbObject::loadFromGuid("CFile-$fileId");

        // Construction du dépôt XDM (niveau 3)
        $repository = new CXDMRepository($file->loadTargetObject(), CCDAFactory::TYPE_VACCINATION_NOTE, CXDSFactory::TYPE_ANS);
        $repository->setFileCDA($file);
        $this->render($repository, 'CDA Level 3');
    }

    private function loadObject(): CStoredObject
    {
        $objectId    = CView::get("object_id", "str");
        $objectClass = CView::get("object_class", "str");

        if (!$objectId) {
            CAppUI::stepAjax("ObjectId ne peut pas Îºtre nul", UI_MSG_ERROR);
        }

        if (!$objectClass) {
            CAppUI::stepAjax("object_class ne peut pas Îºtre nul", UI_MSG_ERROR);
        }

        if ($objectClass !== "CSejour" && $objectClass !== "CConsultation") {
            CAppUI::stepAjax("ObjectClass doit Îºtre égale Î° CSejour ou CConsultation", UI_MSG_ERROR);
        }

        $object = new $objectClass();
        $object->load($objectId);

        if (!$object || !$object->_id) {
            CAppUI::stepAjax('common-error-Object not found', UI_MSG_ERROR);
        }

        return $object;
    }

    private function loadDocumentItem(): CStoredObject
    {
        $objectId    = CView::get("object_id", "str");
        $objectClass = CView::get("object_class", "str");
        CView::checkin();

        if (!$objectId) {
            CAppUI::stepAjax("ObjectId ne peut pas Îºtre nul", UI_MSG_ERROR);
        }

        if (!$objectClass) {
            CAppUI::stepAjax("object_class ne peut pas Îºtre nul", UI_MSG_ERROR);
        }

        if ($objectClass !== "CFile" && $objectClass !== "CCompteRendu") {
            CAppUI::stepAjax("ObjectClass doit Îºtre égale Î° CSejour ou CConsultation", UI_MSG_ERROR);
        }

        $object = new $objectClass();
        $object->load($objectId);

        if (!$object || !$object->_id) {
            CAppUI::stepAjax('common-error-Object not found', UI_MSG_ERROR);
        }

        return $object;
    }

    private function render(CXDMRepository $repository, string $type, array $options_cda = []): void
    {
        // Tentative de génération du XDM
        try {
            $repository->generateXDM($options_cda);
        } catch (CMbException $e) {
            $repository->getReport()->addData($e->getMessage(), CItemReport::SEVERITY_ERROR);
        }

        // En cas d'erreur, on affiche le rapport et on s'arrête là
        if ($repository->hasErrors()) {
            $smarty = new CSmartyDP();
            $smarty->assign('report', $repository->getReport());
            $smarty->display("inc_create_cda_vsm.tpl");

            return;
        }

        CAppUI::stepAjax("Fichier $type créé");
    }
}
