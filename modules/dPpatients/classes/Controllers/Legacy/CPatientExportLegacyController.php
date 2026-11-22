<?php

/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Core\CMbObject;
use Ox\Core\CMbPath;
use Ox\Core\CSmartyDP;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Core\FileUtil\CCSVFile;
use Ox\Core\Import\CMbObjectExport;
use Ox\Core\Module\CModule;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CCSVImportPatients;
use Hospilibre\Patients\CCSVImportSejours;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\Export\CCSVPatientExport;
use Hospilibre\Patients\Export\CXMLPatientExport;
use Hospilibre\PlanningOp\CChargePriceIndicator;
use Hospilibre\PlanningOp\CModeEntreeSejour;
use Hospilibre\PlanningOp\CModeSortieSejour;
use Hospilibre\PlanningOp\CSejour;

/**
 * Description
 */
class CPatientExportLegacyController extends CLegacyController
{
    public function vw_import(): void
    {
        $this->checkPermAdmin();

        // Nombre de patients
        $patient = new CPatient();

        // import temp file
        $start_pat = 0;
        $count_pat = 20;
        if ($data = @file_get_contents(CAppUI::conf("root_dir") . "/tmp/import_patient.txt", "r")) {
            $nb        = explode(";", $data);
            $start_pat = $nb[0];
            $count_pat = $nb[1];
        }

        $patient_specs = [
            '_IPP'                  => $patient->_props['_IPP'],
            'identifiants_externes' => 'str',
        ];

        $patient_specs = array_merge($patient_specs, $patient->getPlainProps());

        $start_sej = 0;
        $count_sej = 20;
        if ($data = @file_get_contents(CAppUI::conf("root_dir") . "/tmp/import_cegi_sejour.txt", "r")) {
            $nb        = explode(";", $data);
            $start_sej = $nb[0];
            $count_sej = $nb[1];
        }

        $sejour    = new CSejour();
        $patient   = new CPatient();
        $mediusers = new CMediusers();

        $group_id = CGroups::loadCurrent()->_id;

        $mode_traitement           = new CChargePriceIndicator();
        $mode_traitement->group_id = $group_id;
        $mode_traitement->actif    = 1;

        /** @var CChargePriceIndicator[] $modes_traitement */
        $modes_traitement = $mode_traitement->loadMatchingList();
        $MDT              = CMbArray::pluck($modes_traitement, 'code');

        $mode_entree           = new CModeEntreeSejour();
        $mode_entree->group_id = $group_id;
        $mode_entree->actif    = 1;

        /** @var CModeEntreeSejour[] $modes_entree */
        $modes_entree = $mode_entree->loadMatchingList();
        $MDE          = CMbArray::pluck($modes_entree, 'code');

        $mode_sortie           = new CModeSortieSejour();
        $mode_sortie->group_id = $group_id;
        $mode_sortie->actif    = 1;

        /** @var CModeSortieSejour[] $modes_sortie */
        $modes_sortie = $mode_sortie->loadMatchingList();
        $MDS          = CMbArray::pluck($modes_entree, 'code');

        $sejour_specs = [
            '_IPP'  => $patient->_props['_IPP'],
            '_NDA'  => $sejour->_props['_NDA'],
            'adeli' => $mediusers->_props['adeli'],
            'rpps'  => $mediusers->_props['rpps'],
        ];

        if ($MDT) {
            $sejour_specs['MDT'] = 'enum list|' . implode('|', $MDT) . ' notNull';
        }

        if ($MDE) {
            $sejour_specs['MDE'] = 'enum list|' . implode('|', $MDE);
        }

        if ($MDS) {
            $sejour_specs['MDS'] = 'enum list|' . implode('|', $MDS);
        }

        $sejour_specs = array_merge($sejour_specs, $sejour->getPlainProps());

        $patient_options            = CCSVImportPatients::$options;
        $patient_interop            = CCSVImportPatients::$options_interop;
        $patient_found              = CCSVImportPatients::$options_found;
        $patient_identito_main      = CCSVImportPatients::$identito_main;
        $patient_identito_secondary = CCSVImportPatients::$identito_secondary;

        $fields_import_sejour = CCSVImportSejours::$options;

        $allowed_types = ["Chirurgien", "Anesthésiste", "Médecin", "Dentiste", "InfirmiÎ¸re", "Sage Femme"];
        $praticiens    = CMbObjectExport::getPraticiensFromGroup($allowed_types);

        $this->renderSmarty(
            'vw_import',
            [
                'group'                      => CGroups::loadCurrent(),
                'praticiens'                 => $praticiens,
                'praticien_id'               => [],
                'count_pat'                  => $count_pat,
                'start_pat'                  => $start_pat,
                'patient_specs'              => $patient_specs,
                'patient_options'            => $patient_options,
                'patient_interop'            => $patient_interop,
                'patient_identito_main'      => $patient_identito_main,
                'patient_identito_secondary' => $patient_identito_secondary,
                'patient_found'              => $patient_found,
                'start_sej'                  => $start_sej,
                'count_sej'                  => $count_sej,
                'sejour_specs'               => $sejour_specs,
                'fields_import_sejour'       => $fields_import_sejour,
            ]
        );
    }

    public function do_import_patient_csv(): void
    {
        $this->checkPermAdmin();

        ini_set("auto_detect_line_endings", true);

        // Basic
        $start    = CView::post("start", "num default|0");
        $count    = CView::post("count", 'num default|100');
        $callback = CView::post("callback", "str");

        // Action when a patient is found
        $patient_found = CView::post("patient_found", "str default|0");

        // interop fields
        $by_IPP          = CView::post("by_IPP", "str default|0");
        $generate_IPP    = CView::post("generate_IPP", "str default|0");
        $diable_handlers = CView::post('disable_handlers', "str default|0");

        // advanced options
        $no_create     = CView::post("no_create", "str default|0");
        $fail_on_empty = CView::post("fail_on_empty", "str default|0");

        // Identito fields
        $identito_nom            = CView::post("identito_nom", "str default|0");
        $identito_prenom         = CView::post("identito_prenom", "str default|0");
        $identito_naissance      = CView::post("identito_naissance", "str default|0");
        $identito_sexe           = CView::post("identito_sexe", "str default|0");
        $identito_prenoms_autres = CView::post("identito_prenoms_autres", "str default|0");
        $identito_tel            = CView::post("identito_tel", "str default|0");
        $identito_matricule      = CView::post("identito_matricule", "str default|0");
        $secondary_operand       = CView::post("secondary_operand", "str default|or");

        CView::checkin();

        CApp::setTimeLimit(600);
        CApp::setMemoryLimit("1024M");

        if ($diable_handlers) {
            CApp::disableCacheAndHandlers();
        }

        CAppUI::stepAjax("Désactivation du gestionnaire", UI_MSG_OK);

        CMbObject::$useObjectCache = false;

        $import_patients = new CCSVImportPatients($start, $count);
        $import_patients->setOptions($by_IPP, $generate_IPP, $patient_found, $no_create, $fail_on_empty);
        $import_patients->setIdentito(
            $identito_nom,
            $identito_prenom,
            $identito_naissance,
            $identito_sexe,
            $identito_prenoms_autres,
            $identito_tel,
            $identito_matricule,
            $secondary_operand
        );

        $ret = $import_patients->import();

        $start += $count;
        file_put_contents(CAppUI::conf("root_dir") . "/tmp/import_patient.txt", "$start;$count");

        echo CAppUI::getMsg();

        if ($callback && $ret) {
            CAppUI::js("$callback($start,$count)");
        }

        CMbObject::$useObjectCache = true;
        CApp::rip();
    }

    public function ajax_export_patients_csv(): void
    {
        $this->checkPermAdmin();

        $praticien_id = CView::get("praticien_id", "str");
        $date_min     = CView::get('date_min', 'str');
        $date_max     = CView::get('date_max', 'str');
        $patient_id   = CView::get('patient_id', 'ref class|CPatient');
        $all_prats    = CView::get('all_prats', 'str');

        CView::enforceSlave();
        CView::checkin();

        // Set system limits
        CApp::setTimeLimit(300);
        CApp::setMemoryLimit("1024M");

        $group = CGroups::loadCurrent();

        $export = new CCSVPatientExport($group, $praticien_id ?: []);
        if ($patient_id) {
            $export->exportPatient($patient_id);
        } else {
            $export->doExport((bool)$all_prats, $date_min, $date_max);
        }
    }

    public function do_export_patients(): void
    {
        $this->checkPermAdmin();

        // Common vars
        $directory      = CView::post('directory', 'str notNull');
        $directory_name = CView::post('directory_name', 'str');

        $directory = stripslashes($directory);

        if (!is_dir($directory) || !is_writable($directory)) {
            CAppUI::stepAjax('CXMLPatientExport-Error-Directory must exists and be writable', UI_MSG_ERROR, $directory);
        }

        $directory_full = $directory . DIRECTORY_SEPARATOR . (($directory_name) ?: ("export-" . CMbDT::date()));

        $options = [
            CXMLPatientExport::OPTION_STEP       => CView::post(CXMLPatientExport::OPTION_STEP, 'num default|10'),
            CXMLPatientExport::OPTION_START      => CView::post(CXMLPatientExport::OPTION_START, 'num default|0'),
            CXMLPatientExport::OPTION_PRATICIENS =>
                explode(',', CView::post(CXMLPatientExport::OPTION_PRATICIENS, 'str')),
            CXMLPatientExport::OPTION_PATIENT    =>
                CView::post(CXMLPatientExport::OPTION_PATIENT, 'ref class|CPatient'),
            CXMLPatientExport::OPTION_DATE_MIN   => CView::post(CXMLPatientExport::OPTION_DATE_MIN, 'date'),
            CXMLPatientExport::OPTION_DATE_MAX   => CView::post(CXMLPatientExport::OPTION_DATE_MAX, 'date'),
            CXMLPatientExport::OPTION_IGNORE_CONST_WITH_TAGS =>
                (bool)CView::post(CXMLPatientExport::OPTION_IGNORE_CONST_WITH_TAGS, 'str'),
        ];

        $archive_mode         = CView::post("archive_mode", "str"); // ??

        if (!$options[CXMLPatientExport::OPTION_PRATICIENS]) {
            CAppUI::stepAjax('CXMLPatientExport-Error-Praticien is mandatory', UI_MSG_ERROR);
        }

        CApp::setTimeLimit(600);
        CApp::setMemoryLimit("4096M");

        CView::setSession(CXMLPatientExport::OPTION_PRATICIENS, $options[CXMLPatientExport::OPTION_PRATICIENS]);
        CView::setSession(CXMLPatientExport::OPTION_START, $options[CXMLPatientExport::OPTION_START]);
        CView::setSession(CXMLPatientExport::OPTION_STEP, $options[CXMLPatientExport::OPTION_STEP]);
        CView::setSession(CXMLPatientExport::OPTION_DATE_MIN, $options[CXMLPatientExport::OPTION_DATE_MIN]);
        CView::setSession(CXMLPatientExport::OPTION_DATE_MAX, $options[CXMLPatientExport::OPTION_DATE_MAX]);
        CView::setSession(CXMLPatientExport::OPTION_PATIENT, $options[CXMLPatientExport::OPTION_PATIENT]);
        CView::setSession("directory", $directory);
        CView::setSession("directory_name", $directory_name);

        CView::enforceSlave();

        CView::checkin();

        $export        = new CXMLPatientExport($directory_full, $options);
        $patient_count = $export->export();

        CAppUI::stepAjax("%d patients Î° exporter", UI_MSG_OK, $export->getTotal());

        CAppUI::stepAjax("%d patients au total", UI_MSG_OK, $patient_count);

        if ($patient_count && !$options[CXMLPatientExport::OPTION_PATIENT]) {
            CAppUI::js("nextStepPatients()");
        }
    }
}
