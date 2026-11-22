<?php

/**
 * @package Hospilibre
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\CompteRendu\Controllers\Legacy;

use Exception;
use Ox\Core\Cache;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbObject;
use Ox\Core\CMbXMLDocument;
use Ox\Core\CMbDT;
use Ox\Core\CMbPDFMerger;
use Ox\Core\CModelObject;
use Ox\Core\CRequest;
use Ox\Core\CSQLDataSource;
use Ox\Core\CView;
use Ox\Core\Module\CModule;
use Hospilibre\Cabinet\CConsultAnesth;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\CompteRendu\CDestinataire;
use Hospilibre\CompteRendu\CModeleToPack;
use Hospilibre\CompteRendu\CPack;
use Hospilibre\CompteRendu\CTemplateManager;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Files\CFile;
use Hospilibre\Files\CFilesCategory;
use Hospilibre\Files\MailReceiverService;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mediusers\CSecondaryFunction;
use Hospilibre\PlanningOp\COperation;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\Prescription\CElementPrescription;
use Hospilibre\Prescription\CPrescription;
use Hospilibre\Printing\CPrinter;
use Hospilibre\System\CExchangeSource;
use ZipArchive;
use Ox\Core\CMbPath;

/**
 * Controleur legacy des comptes-rendus.
 */
class CCompteRenduController extends CLegacyController
{
    /**
     * Fabrique le PDF d'un compte-rendu puis le diffuse au client
     * (ou vers une imprimante reseau).
     */
    public function streamPDF(): void
    {
        $fileId = CView::get("file_id", "ref class|CFile");

        CView::checkin();

        $file = CFile::findorFail($fileId);

        // On horodate l'impression sur le compte-rendu rattache au fichier.
        $compteRendu = CCompteRendu::findOrFail($file->object_id);
        $compteRendu->loadContent();
        $compteRendu->date_print = "now";

        $error = $compteRendu->store();
        if ($error) {
            CAppUI::setMsg($error, UI_MSG_ERROR);
        }

        $file->streamFile();
    }

    /**
     * Cree ou edite un document construit depuis un modele.
     */
    public function edit(array $params = []): ?int
    {
        $compteRenduId = CView::get("compte_rendu_id", 'ref class|CCompteRendu');
        $modeleId      = $params["modele_id"] ?? CView::get("modele_id", 'ref class|CCompteRendu');
        $packId        = CView::get("pack_id", 'ref class|CPack');
        $objectId      = CView::get("object_id", 'str');
        $switchMode    = CView::get("switch_mode", 'bool default|0');
        $targetId      = $params["target_id"] ?? CView::get("target_id", 'str');
        $targetClass   = $params["target_class"] ?? CView::get("target_class", 'str');
        $forceFastEdit = CView::get("force_fast_edit", 'bool default|0');
        $storeHeadless = $params["store_headless"] ?? CView::get("store_headless", "bool default|0");
        $extCabinetId  = CView::get("ext_cabinet_id", "num");

        // Parametres facultatifs.
        $objectClass = CView::get("object_class", 'str');
        $objectGuid  = CView::get("object_guid", 'str');
        $uniqueId    = CView::get("unique_id", 'str');
        $reloadZones = CView::get("reloadzones", 'bool default|0');

        CView::checkin();

        $compteRendu = new CCompteRendu();

        $currentUser = CMediusers::get();
        $userOpener  = new CMediusers();

        if ($compteRenduId) {
            // Cas 1 : edition d'un document existant.
            $compteRendu->load($compteRenduId);
            if (!$compteRendu->_id) {
                CAppUI::stepAjax(CAppUI::tr("CCompteRendu-alert_doc_deleted"));
                CApp::rip();
            }
            $compteRendu->loadContent();
            $compteRendu->loadComponents();
            $compteRendu->loadFile();

            $cache        = new Cache('edit_compte_rendu', $compteRendu->_guid, Cache::DISTR, 1200);
            $openedUserId = $cache->get();

            if ($openedUserId && $currentUser->_id != $openedUserId) {
                // Un autre utilisateur a deja le document ouvert.
                $userOpener->load($openedUserId)->loadRefFunction();
            } elseif (!$cache->exists() || !$openedUserId) {
                // Nous sommes le premier a l'ouvrir.
                $cache->put($currentUser->_id);
            }
        } elseif ($modeleId == 0 && !$packId) {
            // Cas 2 : creation depuis un modele vierge.
            $compteRendu->valueDefaults();
            $compteRendu->object_id    = $objectId;
            $compteRendu->object_class = $targetClass;
            $compteRendu->_ref_object  = new $targetClass();
            $compteRendu->_ref_object->load($objectId);
            $compteRendu->updateFormFields();
        } else {
            // Cas 3 : creation depuis un modele (eventuellement via un pack).
            $compteRendu->load($modeleId);
            $compteRendu->loadFile();
            $compteRendu->loadContent();
            $compteRendu->_id         = null;
            $compteRendu->function_id = null;
            $compteRendu->group_id    = null;
            $compteRendu->object_id   = $objectId;
            $compteRendu->_ref_object = null;
            $compteRendu->modele_id   = $modeleId;
            $headerId                 = null;
            $footerId                 = null;

            // Reprise des entetes/pieds de page du modele.
            if ($compteRendu->header_id || $compteRendu->footer_id) {
                $headerId = $compteRendu->header_id;
                $footerId = $compteRendu->footer_id;
            }

            // Cible transmise explicitement.
            if ($targetId && $targetClass) {
                $compteRendu->object_id    = $targetId;
                $compteRendu->object_class = $targetClass;
            }

            if ($packId) {
                // Construction depuis un pack de modeles.
                $pack = new CPack();
                $pack->load($packId);

                $pack->loadContent();
                $compteRendu->nom              = $pack->nom;
                $compteRendu->object_class     = $pack->object_class;
                $compteRendu->file_category_id = $pack->category_id;
                $compteRendu->fast_edit        = $pack->fast_edit;
                $compteRendu->fast_edit_pdf    = $pack->fast_edit_pdf;
                $compteRendu->_source          = $pack->_source;
                $compteRendu->modele_id        = null;

                $pack->loadHeaderFooter();

                $headerId = $pack->_header_found->_id;
                $footerId = $pack->_footer_found->_id;

                // Marges et mise en page recuperees sur le premier modele du pack.
                /** @var $links CModeleToPack[] */
                $links       = $pack->_back['modele_links'];
                $firstModele = reset($links);
                $firstModele = $firstModele->_ref_modele;

                $compteRendu->factory             = $firstModele->factory;
                $compteRendu->margin_top          = $firstModele->margin_top;
                $compteRendu->margin_left         = $firstModele->margin_left;
                $compteRendu->margin_right        = $firstModele->margin_right;
                $compteRendu->margin_bottom       = $firstModele->margin_bottom;
                $compteRendu->page_height         = $firstModele->page_height;
                $compteRendu->page_width          = $firstModele->page_width;
                $compteRendu->font                = $firstModele->font;
                $compteRendu->size                = $firstModele->size;
                $compteRendu->send                = $firstModele->send;
                $compteRendu->signature_mandatory = $firstModele->signature_mandatory;
            }

            $compteRendu->_source = $compteRendu->generateDocFromModel(null, $headerId, $footerId);
            $compteRendu->updateFormFields();
        }

        $compteRendu->loadRefsFwd();
        $compteRendu->loadRefPrinter();
        $compteRendu->_ref_object->loadRefsFwd();

        $object =& $compteRendu->_ref_object;

        if (!$compteRendu->_id) {
            if (!$compteRendu->font) {
                $compteRendu->font = array_search(
                    CAppUI::conf("dPcompteRendu CCompteRendu default_font"),
                    CCompteRendu::$fonts
                );
            }

            if (!$compteRendu->size) {
                $compteRendu->size = CAppUI::gconf("dPcompteRendu CCompteRendu default_size");
            }

            $compteRendu->guessSignataire();
        } else {
            $compteRendu->getDeliveryStatus();
        }

        // Determination du praticien concerne.
        // Priorite : utilisateur courant, puis anesthesiste, puis praticien de la consultation.
        $user = $currentUser;

        if (!$user->isPraticien()) {
            $user   = new CMediusers();
            $userId = null;

            switch ($object->_class) {
                case "CConsultAnesth":
                    /** @var $object CConsultAnesth */
                    $operation = $object->loadRefOperation();
                    $anesth    = $operation->_ref_anesth;
                    if ($operation->_id && $anesth->_id) {
                        $userId = $anesth->_id;
                    }

                    if ($userId == null) {
                        $userId = $object->_ref_consultation->_praticien_id;
                    }
                    break;

                case "CConsultation":
                    /** @var $object CConsultation */
                    $userId = $object->loadRefPraticien()->_id;
                    break;

                case "CSejour":
                    /** @var $object CSejour */
                    $userId = $object->praticien_id;
                    break;

                case "COperation":
                    /** @var $object COperation */
                    $userId = $object->chir_id;
                    break;

                default:
                    $userId = $currentUser->_id;
            }

            $user->load($userId);
        }

        $function = $user->loadRefFunction();

        // Categories disponibles pour la classe cible.
        $listCategory = CFilesCategory::listCatClass($compteRendu->object_class);
        if (
            $compteRendu->object_class === "CEvenementPatient"
            && CModule::getActive("oxCabinet")
            && !$compteRendu->file_category_id
        ) {
            $compteRendu->loadTargetObject();
            $categorie                     = CAppUI::gconf(
                "oxCabinet CEvenementPatient categorie_{$compteRendu->_ref_object->type}_default"
            );
            $compteRendu->file_category_id = $categorie;
        }

        // Nombre d'imprimantes utilisables pour l'impression serveur.
        $nbPrinters = $currentUser->loadRefFunction()->countBackRefs("printers");

        // Preparation du gestionnaire de template.
        $templateManager           = new CTemplateManager();
        $templateManager->isModele = false;
        $templateManager->document = $compteRendu->_source;
        $object->fillTemplate($templateManager);
        $templateManager->loadHelpers($user->_id, $compteRendu->object_class, $currentUser->function_id);
        $templateManager->loadLists($user->_id, $modeleId ? $modeleId : $compteRendu->modele_id);

        // Un document pose sur un protocole de prescription ou un element de prescription
        // se comporte comme un modele.
        if (($object instanceof CPrescription && !$object->object_id) || $object instanceof CElementPrescription) {
            $templateManager->isModele  = true;
            $templateManager->valueMode = false;
        } else {
            $templateManager->applyTemplate($compteRendu);
        }

        $creation = false;

        // Enregistrement automatique lors d'une creation classique.
        if (!$compteRendu->_id && ($storeHeadless || (!$compteRendu->fast_edit && !$compteRendu->fast_edit_pdf))) {
            $compteRendu->content_id = "";
            $compteRendu->_source    = $templateManager->document;
            $compteRendu->user_id    = $compteRendu->function_id = $compteRendu->group_id = '';

            $creation = true;
        }

        if ($storeHeadless) {
            $compteRendu->store();

            return $compteRendu->_id;
        }

        $lists = $templateManager->getUsedLists($templateManager->allLists);

        // Detection d'un element de publipostage pour afficher le bon bouton.
        $isCourrier = $templateManager->isCourrier();

        $destinataires = [];
        if ($isCourrier) {
            $destinataires = (new MailReceiverService($object))->getReceivers();
        }

        $canLock      = $compteRendu->canLock();
        $canUnlock    = $compteRendu->canUnlock();
        $canDuplicate = $compteRendu->canDuplicate();
        $compteRendu->isLocked();
        $lockBloked = $compteRendu->_is_locked ? !$canUnlock : !$canLock;
        if ($compteRendu->valide && !CAppUI::gconf("dPcompteRendu CCompteRendu unlock_doc")) {
            $lockBloked = 1;
        }
        $compteRendu->canDo();
        $readOnly = $compteRendu->_is_locked || !$compteRendu->_can->edit;

        if ($compteRendu->_is_locked) {
            $templateManager->printMode = true;
        }
        if ($compteRendu->_id && !$compteRendu->canEdit()) {
            $templateManager->printMode = true;
        }

        // A defaut d'object_class dans la requete, on prend celui du compte-rendu.
        if (!$objectClass) {
            $objectClass = $compteRendu->object_class;
        }

        $compteRendu->_ext_cabinet_id = $extCabinetId;
        if ($compteRenduId) {
            $compteRendu->loadLastRefStatutCompteRendu();
        }

        $tplVars = [
            'listCategory'  => $listCategory,
            'compte_rendu'  => $compteRendu,
            'modele_id'     => $modeleId,
            'curr_user'     => $currentUser,
            'user_opener'   => $userOpener,
            'lists'         => $lists,
            'isCourrier'    => $isCourrier,
            'user_id'       => $user->_id,
            'user_view'     => $user->_view,
            'object_id'     => $objectId,
            'object_class'  => $objectClass,
            'nb_printers'   => $nbPrinters,
            'pack_id'       => $packId,
            'destinataires' => $destinataires,
            'lock_bloked'   => $lockBloked,
            'can_duplicate' => $canDuplicate,
            'read_only'     => $readOnly,
            'unique_id'     => $uniqueId,
            'creation'      => $creation,
        ];

        preg_match_all("/(:?\[\[Texte libre - ([^\]]*)\]\])/i", $compteRendu->_source, $matches);

        // Deduplication des zones de texte libre.
        $templateManager->textes_libres = array_unique($matches[2]);

        if (isset($compteRendu->_ref_file->_id)) {
            $tplVars['file'] = $compteRendu->_ref_file;
        }

        $tplVars['textes_libres'] = $templateManager->textes_libres;

        $exchangeSource             = CExchangeSource::get("mediuser-" . $currentUser->_id);
        $tplVars['exchange_source'] = $exchangeSource;

        // Entetes / pieds de page ajoutables a la volee.
        $headers = [];
        $footers = [];

        if (CAppUI::gconf("dPcompteRendu CCompteRendu header_footer_fly")) {
            $headers = CCompteRendu::loadAllModelesFor($user->_id, "prat", $compteRendu->object_class, "header");
            $footers = CCompteRendu::loadAllModelesFor($user->_id, "prat", $compteRendu->object_class, "footer");
        }

        $tplVars['headers'] = $headers;
        $tplVars['footers'] = $footers;

        // Nettoyage des balises meta et link (source de bugs avec entete/pied de page).
        $source = &$templateManager->document;

        $source = preg_replace("/<meta\s*[^>]*\s*[^\/]>/", '', $source);
        $source = preg_replace("/(<\/meta>)+/i", '', $source);
        $source = preg_replace("/<link\s*[^>]*\s*>/", '', $source);

        $pdfAndThumbs = CAppUI::pref("pdf_and_thumbs");

        if ($compteRendu->_id) {
            $compteRendu->loadModele();
        }

        if ($reloadZones == 1) {
            $this->renderSmarty('inc_zones_fields', $tplVars);
        } elseif (
            !$compteRenduId && !$switchMode
            && ($compteRendu->fast_edit || $forceFastEdit || ($compteRendu->fast_edit_pdf && $pdfAndThumbs))
        ) {
            $printers = $function->loadBackRefs("printers") ?? [];

            /** @var $_printer CPrinter */
            foreach ($printers as $_printer) {
                $_printer->loadTargetObject();
            }

            $tplVars['_source']     = $templateManager->document;
            $tplVars['printers']    = $printers;
            $tplVars['object_guid'] = $objectGuid;

            $this->renderSmarty('fast_mode', $tplVars);
        } else {
            // Documents precedent / suivant.
            $prevnext = [];
            if ($compteRendu->_id) {
                $object->loadRefsDocs();
                $prevnext = CMbArray::getPrevNextKeys($object->_ref_documents, $compteRendu->_id);
            }

            $templateManager->initHTMLArea();
            $tplVars['switch_mode']     = $switchMode;
            $tplVars['templateManager'] = $templateManager;
            $tplVars['prevnext']        = $prevnext;
            $this->renderSmarty('edit_compte_rendu', $tplVars);
        }

        return null;
    }

    /**
     * Point d'entree dedie aux editions rapides, afin de les tracer
     * distinctement dans les logs d'acces.
     */
    public function editFast(): void
    {
        (new CCompteRenduController())->edit();
    }

    public function autocomplete(): void
    {
        $this->checkPermRead();

        $userId       = CView::get("user_id", "ref class|CMediusers");
        $functionId   = CView::get("function_id", "ref class|CFunctions");
        $objectClass  = CView::get("object_class", "str");
        $objectId     = CView::get("object_id", "num");
        $keywords     = CView::get("keywords_modele", "str");
        $fastEdit     = CView::get("fast_edit", "bool default|1");
        $modeStore    = CView::get("mode_store", "bool default|1");
        $modeleVierge = CView::get("modele_vierge", "bool default|1");
        $type         = CView::get("type", "str");
        $appFine      = CView::get("appFine", "bool default|0");

        CView::checkin();

        CView::enableSlave();

        $compteRendu = new CCompteRendu();

        $modeles = [];
        $favoris = [];

        $where = [
            "actif"     => "= '1'",
            'object_id' => 'IS NULL',
        ];

        if (!$fastEdit) {
            $where["fast_edit"]     = " = '0'";
            $where["fast_edit_pdf"] = " = '0'";
        }

        if ($modeStore) {
            $where["type"] = "= 'body'";
        } elseif ($type) {
            $where["type"] = "= '$type'";
        }

        if ($objectClass) {
            $where["object_class"] = "= '$objectClass'";
        }

        $module    = CModule::getActive("dPcompteRendu");
        $isAdmin   = $module && $module->canAdmin();
        $isCabinet = CAppUI::isCabinet();

        // --- Niveau utilisateur ---
        if ($userId) {
            $user = CMediusers::get($userId);
            $user->loadRefFunction();

            $usersIds = [$user->_id];

            $currentUser = CMediusers::get();

            if ($modeStore) {
                $usersIds[] = $currentUser->_id;
            }

            if ($objectClass) {
                switch ($objectClass) {
                    default:
                        break;
                    case "COperation":
                        $object = new $objectClass();
                        $object->load($objectId);
                        $fieldsChir = ["chir_id", "chir_2_id", "chir_3_id", "chir_4_id"];

                        foreach ($fieldsChir as $_fieldChir) {
                            if ($object->$_fieldChir) {
                                $usersIds[] = $object->$_fieldChir;
                            }
                        }
                }
            }

            $usersIds = array_unique($usersIds);

            foreach ($usersIds as $key => $_userId) {
                if ($_userId == $currentUser->_id) {
                    continue;
                }
                $_user = CMediusers::get($_userId);
                if (!$_user->getPerm(PERM_EDIT)) {
                    unset($usersIds[$key]);
                }
            }

            $where["user_id"] = CSQLDataSource::prepareIn($usersIds);

            $modeles = $compteRendu->seek($keywords, $where, 100, false, null, "nom");

            if (CAppUI::pref("show_favorites")) {
                $ds           = CSQLDataSource::get('std');
                $whereFavoris = $where;
                unset($whereFavoris["user_id"]);
                unset($whereFavoris["object_id"]);
                $whereFavoris["author_id"] = "= '" . CMediusers::get()->_id . "'";

                $request = new CRequest(false);
                $request->addSelect(["modele_id", "COUNT(*)"]);
                $request->addTable("compte_rendu");
                $request->addWhere($whereFavoris);
                $request->addGroup("modele_id");
                $request->addOrder("COUNT(*) DESC");
                $request->setLimit("10");

                $favoris = $ds->loadList($request->makeSelect());
            }
        }

        // --- Niveau fonction ---
        // On inclut les fonctions secondaires de l'utilisateur connecte et de l'utilisateur concerne.
        unset($where["user_id"]);

        $functionIds = [];

        if ($isAdmin || !$isCabinet || CAppUI::gconf("dPcompteRendu CCompteRenduAcces access_function")) {
            if (CModule::getActive('appFineClient') && $appFine && $isAdmin) {
                $functionAppFine           = new CFunctions();
                $ds                        = $functionAppFine->getDS();
                $whereAppFine              = [];
                $whereAppFine['group_id']  = $ds->prepare('= ?', CGroups::loadCurrent()->_id);
                $functionIds               = $functionAppFine->loadIds($whereAppFine);
            } elseif ($userId) {
                $secFunction             = new CSecondaryFunction();
                $whereSecFunc            = [];
                $whereSecFunc["user_id"] = " = '$currentUser->_id'";
                if ($user->getPerm(PERM_EDIT)) {
                    $whereSecFunc["user_id"] = "IN ('$user->_id', '$currentUser->_id')";
                }

                $functionSec = $secFunction->loadList($whereSecFunc);
                $functionIds = array_merge(
                    CMbArray::pluck($functionSec, "function_id"),
                    [$user->function_id, $currentUser->function_id]
                );
            } else {
                $functionIds = [$functionId];
            }

            $where["function_id"] = CSQLDataSource::prepareIn($functionIds);
            $modeles              = array_merge(
                $modeles,
                $compteRendu->seek($keywords, $where, 100, false, null, "nom")
            );
        }

        // --- Niveau etablissement ---
        // On ajoute l'etablissement courant s'il differe de celui de l'utilisateur.
        if ($isAdmin || !$isCabinet || CAppUI::gconf("dPcompteRendu CCompteRenduAcces access_group")) {
            unset($where["function_id"]);

            if ($functionId && !$userId) {
                $function = new CFunctions();
                $function->load($functionId);
                $where["group_id"] = "= '$function->group_id'";
            } else {
                $where["group_id"] = CSQLDataSource::prepareIn($user->_group_id, CGroups::loadCurrent()->_id);
            }

            $modeles = array_merge($modeles, $compteRendu->seek($keywords, $where, 100, false, null, "nom"));

            $modeles = CModelObject::naturalSort($modeles, ["nom"], true);
        }

        // --- Niveau instance ---
        unset($where["group_id"]);

        $where[] = "user_id IS NULL AND function_id IS NULL AND group_id IS NULL AND object_id IS NULL";

        $modeles = array_merge($modeles, $compteRendu->seek($keywords, $where, 100, false, null, "nom"));

        if (CModule::getActive('appFineClient') && $appFine && $isAdmin) {
            foreach ($modeles as $_modele) {
                if (!$_modele->function_id) {
                    continue;
                }
                $_modele->loadRefFunction();
            }
        }

        $modeles = CModelObject::naturalSort($modeles, ["nom"], true);

        if ($favoris) {
            $modelesWithFavorites = [];
            foreach ($favoris as $_modele) {
                if (array_key_exists($_modele["modele_id"], $modeles)) {
                    $modelesWithFavorites[$_modele["modele_id"]]                = $modeles[$_modele["modele_id"]];
                    $modelesWithFavorites[$_modele["modele_id"]]->_utilisations = $_modele["COUNT(*)"];
                }
            }
            foreach ($modeles as $_id => $_modele) {
                if (!isset($modelesWithFavorites[$_id])) {
                    $modelesWithFavorites[$_id] = $_modele;
                }
            }

            $modeles = $modelesWithFavorites;
        }

        $this->renderSmarty(
            'inc_modele_autocomplete',
            [
                'modeles'       => $modeles,
                'nodebug'       => true,
                'keywords'      => $keywords,
                'modele_vierge' => $modeleVierge,
                'appFine'       => $appFine,
            ]
        );
    }

    /**
     * Impression groupee d'une selection de documents.
     */
    public function print_docs(): void
    {
        $this->checkPermRead();

        $nbDoc   = CView::get("nbDoc", "str");
        $nbFile  = CView::get("nbFile", "str");
        $nbPresc = CView::get("nbPresc", "str");

        CView::checkin();

        $documents = [];
        $tmpFiles  = [];
        $pdf       = new CMbPDFMerger();

        if (is_array($nbDoc)) {
            CMbArray::removeValue("0", $nbDoc);
        }

        if (is_array($nbFile)) {
            CMbArray::removeValue("0", $nbFile);
        }

        if (is_array($nbPresc)) {
            CMbArray::removeValue("0", $nbPresc);
        }

        if ((!$nbDoc || !count($nbDoc)) && (!$nbFile || !count($nbFile)) && (!$nbPresc || !count($nbPresc))) {
            CAppUI::stepAjax("Aucun document Î° imprimer !");
            CApp::rip();
        }

        if (is_countable($nbDoc) && count($nbDoc)) {
            $compteRendu = new CCompteRendu();
            $where       = ["compte_rendu_id" => CSQLDataSource::prepareIn(array_keys($nbDoc))];

            /** @var $_compte_rendu CCompteRendu */
            foreach ($compteRendu->loadList($where) as $_compte_rendu) {
                $_compte_rendu->date_print = CMbDT::dateTime();
                $_compte_rendu->store();
                $_compte_rendu->makePDFpreview(1);

                $nbPrint = $nbDoc[$_compte_rendu->_id];
                for ($i = 1; $i <= $nbPrint; $i++) {
                    $pdf->addPDF($_compte_rendu->_ref_file->_file_path);
                }
            }
        }

        if (is_countable($nbFile) && count($nbFile)) {
            $file  = new CFile();
            $where = ["file_id" => CSQLDataSource::prepareIn(array_keys($nbFile))];

            /** @var $_file CFile */
            foreach ($file->loadList($where) as $_file) {
                $nbPrint = $nbFile[$_file->_id];
                for ($i = 1; $i <= $nbPrint; $i++) {
                    $tmpFile     = tempnam("./tmp", "file");
                    $tmpFilePdf  = $tmpFile . ".pdf";
                    $tmpFiles[]  = $tmpFilePdf;
                    $pdf->addPDF($pdf->convertPDToVersion14($_file->file_type, $tmpFilePdf, $_file->_file_path));
                }
            }
        }

        if (is_countable($nbPresc) && count($nbPresc)) {
            $prescription = new CPrescription();
            $where        = ["prescription_id" => CSQLDataSource::prepareIn(array_keys($nbPresc))];
            foreach ($prescription->loadList($where) as $_prescription) {
                $prats = [$_prescription->_ref_object->praticien_id];

                // Une ordonnance par praticien prescripteur.
                $_prescription->loadRefsLinesMedComments("0", "0", "1", "", "", "1");
                $_prescription->loadRefsLinesElementsComments("0", "0", "", "", "", "1");
                $_prescription->loadRefsPrescriptionLineMixes("", "0", "0", "1", "", "1");

                $prats = array_merge($prats, CMbArray::pluck($_prescription->_ref_prescription_lines, "praticien_id"));
                $prats = array_merge($prats, CMbArray::pluck($_prescription->_ref_prescription_line_mixes, "praticien_id"));
                $prats = array_merge($prats, CMbArray::pluck($_prescription->_ref_prescription_lines_element, "praticien_id"));

                $prats = array_unique($prats);

                foreach ($prats as $_prat_id) {
                    $params = [
                        "prescription_id"     => $_prescription->_id,
                        "praticien_sortie_id" => $_prat_id,
                        "stream"              => 0,
                    ];

                    CApp::fetch("dPprescription", "print_prescription_fr", $params);

                    $file = new CFile();

                    $where = [
                        "object_class" => "= '" . $_prescription->_ref_object->_class . "'",
                        "object_id"    => "= '" . $_prescription->_ref_object->_id . "'",
                        "file_name"    => "LIKE 'Ordonnance %'",
                    ];

                    if (!$file->loadObject($where, "file_id DESC")) {
                        continue;
                    }

                    $nbPrint = $nbPresc[$_prescription->_id];
                    for ($i = 1; $i <= $nbPrint; $i++) {
                        $pdf->addPDF($file->_file_path);
                    }
                }
            }
        }

        // Diffusion du PDF fusionne dans le navigateur.
        // PDFMerger leve une exception si aucun PDF n'a ete ajoute : on l'intercepte.
        try {
            $pdf->merge("browser", "documents.pdf");
        } catch (Exception $e) {
            $this->rip();
        }

        foreach ($tmpFiles as $_tmpFile) {
            unlink($_tmpFile);
        }
    }

    public function viewFields(): void
    {
        $this->checkPerm();

        $sections    = CView::get('sections', 'str');
        $maxSections = CView::get('max_sections', 'num default|3');

        CView::checkin();

        $this->renderSmarty('view_fields', ['sections' => $sections, 'max_sections' => $maxSections]);
    }

    public function viewImport(): void
    {
        $this->checkPermRead();

        $ownerGuid = CView::request("owner_guid", "str");

        CView::checkin();

        $owner = $ownerGuid === "Instance"
            ? CCompteRendu::getInstanceObject()
            : CMbObject::loadFromGuid($ownerGuid);

        $this->renderSmarty(
            'inc_vw_import_modele',
            [
                'owner'   => $owner,
                'classes' => CCompteRendu::getTemplatedClasses(),
            ]
        );
    }

    public function importModeles(): void
    {
        $this->checkPermRead();

        $ownerGuid   = CView::post('owner_guid', 'str');
        $objectClass = CView::post('object_class', 'str');

        CView::checkin();

        $owner = $ownerGuid === 'Instance' ? CCompteRendu::getInstanceObject() : CMbObject::loadFromGuid($ownerGuid);

        if (!$owner || !$owner->_id) {
            CAppUI::stepMessage(UI_MSG_WARNING, 'CCompteRendu-import-Owner wish not found');
        }

        $userId     = "";
        $functionId = "";
        $groupId    = "";

        switch ($owner->_class) {
            case "CMediusers":
                $userId = $owner->_id;
                break;
            case "CFunctions":
                $functionId = $owner->_id;
                break;
            case "CGroups":
                $groupId = $owner->_id;
                break;
            default:
                // Aucun proprietaire.
                break;
        }

        $file = $_FILES["datafile"];

        if (strtolower(pathinfo($file["name"], PATHINFO_EXTENSION) !== "xml")) {
            CAppUI::stepAjax('CCompteRendu-import-File unknown', UI_MSG_ERROR);
            CApp::rip();
        }

        $doc = file_get_contents($file['tmp_name']);

        $xml = new CMbXMLDocument(null);
        $xml->loadXML($doc);

        $root = $xml->firstChild;

        if ($root->nodeName == 'modeles') {
            $root = $root->childNodes;
        } else {
            $root = [$xml->firstChild];
        }

        $modelesIds = [];

        CCompteRendu::$import = true;

        foreach ($root as $_modele) {
            $modele = CCompteRendu::importModele($_modele, $userId, $functionId, $groupId, $objectClass, $modelesIds);

            CAppUI::stepAjax($modele->nom . " - " . CAppUI::tr('CCompteRendu-msg-create'), UI_MSG_OK);
        }

        CCompteRendu::$import = false;

        CAppUI::js("window.opener.getForm('filterModeles').onsubmit()");
    }

    /**
     * Liste les referentiels exploitables par le plugin CKEditor mbbenchmark.
     *
     * @throws Exception
     */
    public function viewBenchmark(): void
    {
        $this->checkPermRead();

        CView::checkIn();

        $benchmarks = [];
        if (CModule::getActive("dPcim10")) {
            $benchmarks[] = "CIM10";
            $benchmarks[] = "DRC";
            $benchmarks[] = "CISP";
        }

        if (CModule::getActive("loinc")) {
            $benchmarks[] = "LOINC";
        }

        if (CModule::getActive("dPccam")) {
            $benchmarks[] = "CCAM";
        }

        if (CModule::getActive("dPmedicament")) {
            $benchmarks[] = "ATC";
        }

        $this->renderSmarty(
            'inc_vw_benchmark',
            [
                "benchmarks" => $benchmarks,
            ]
        );
    }

    /**
     * Construit une archive ZIP regroupant les documents selectionnes au format PDF.
     *
     * @throws Exception
     */
    public function downloadZipFile(): void
    {
        $this->checkPermRead();
        $nbDoc = CView::get("nbDoc", "str");
        CView::checkin();

        // Creation du repertoire temporaire qui hebergera l'archive.
        $user         = CMediusers::get();
        $functionName = $user->loadRefFunction()->text;
        $tempdir      = CAppUI::getTmpPath("recherche_documentaire_$user->_id");
        CMbPath::forceDir($tempdir);

        $zipname = "$tempdir/archive.zip";
        $archive = new ZipArchive();
        $archive->open($zipname, ZipArchive::CREATE);

        // Ajout des documents dans l'archive.
        $compteRendu = new CCompteRendu();
        $where       = ["compte_rendu_id" => CSQLDataSource::prepareIn(array_keys($nbDoc))];
        $nameFiles   = [];

        foreach ($compteRendu->loadList($where) as $_compte_rendu) {
            $_compte_rendu->makePDFpreview(true, false);
            $tmpFileName = basename($_compte_rendu->_ref_file->file_name, ".pdf");

            // En cas d'homonymie on suffixe le nom du fichier
            // (ex : si tmp2.pdf existe, le suivant devient tmp2(2).pdf).
            if (array_key_exists($tmpFileName, $nameFiles)) {
                $nameFiles[$tmpFileName] += 1;
                $tmpFileName             = $tmpFileName . "(" . $nameFiles[$tmpFileName] . ").pdf";
            } else {
                $nameFiles[$tmpFileName] = 1;
                $tmpFileName             = $tmpFileName . ".pdf";
            }
            $archive->addFile($_compte_rendu->_ref_file->_file_path, $tmpFileName);
        }

        $archive->close();
        $outputName = sprintf(
            "Recherche documentaire du %s - %s.zip",
            CMbDT::date(),
            strtr($functionName, './\\"\'', '')
        );

        ob_end_clean();
        header('Content-Type: application/zip');
        header(sprintf('Content-Disposition: attachment; filename="%s"', $outputName));
        readfile($zipname);

        // Menage : on retire l'archive et son repertoire.
        unlink($zipname);
        rmdir($tempdir);

        CApp::rip();
    }

    /**
     * Ouvre une modale enumerant tous les statuts du compte-rendu.
     */
    public function showAllStatut(): void
    {
        $this->checkPermRead();
        $compteRenduId = CView::get("compte_rendu_id", 'ref class|CCompteRendu');
        CView::checkin();

        $compteRendu = CCompteRendu::find($compteRenduId);
        $compteRendu->loadRefStatutCompteRendu();

        $this->renderSmarty(
            'inc_statuts_compte_rendu',
            [
                'statuts' => $compteRendu->_ref_statut_compte_rendu,
            ]
        );
    }
}
