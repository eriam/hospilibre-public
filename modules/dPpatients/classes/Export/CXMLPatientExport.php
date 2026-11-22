<?php

/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients\Export;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLogger;
use Ox\Core\CMbException;
use Ox\Core\CMbModelNotFoundException;
use Ox\Core\CMbPath;
use Ox\Core\CStoredObject;
use Ox\Core\Import\CMbObjectExport;
use Ox\Core\Module\CModule;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\Export\Description\CCSVPatientExportDescriptionWriter;
use Hospilibre\Patients\Export\Description\CXMLPatientExportInfosGenerator;

/**
 * Class used to generate a XML export for a set of CPatients.
 */
class CXMLPatientExport
{
    public const OPTION_START                  = 'start';
    public const OPTION_STEP                   = 'step';
    public const OPTION_PRATICIENS             = 'praticien_id';
    public const OPTION_PATIENT                = 'patient_id';
    public const OPTION_DATE_MIN               = 'date_min';
    public const OPTION_DATE_MAX               = 'date_max';
    public const OPTION_IGNORE_CONST_WITH_TAGS = 'ignore_consult_tag';

    public const OPTIONS = [
        self::OPTION_START                  => 0,
        self::OPTION_STEP                   => 10,
        self::OPTION_PRATICIENS             => null,
        self::OPTION_PATIENT                => null,
        self::OPTION_DATE_MIN               => null,
        self::OPTION_DATE_MAX               => null,
        self::OPTION_IGNORE_CONST_WITH_TAGS => false,
    ];

    public const PATIENT_ORDER = 'patient_id ASC';

    public const CLASSES_DESCRIPTION_FILE_NAME = 'classes_description.csv';
    public const EXPORT_DESCRIPTION_FILE_NAME = 'export_description.md';

    /** @var string */
    private $directory;

    /** @var array */
    private $options = [];

    /** @var array */
    private $patients = [];

    /** @var int */
    private $total = 0;

    /** @var array */
    private $fw_tree = [];

    /** @var array */
    private $back_tree = [];

    /** @var string */
    private $current_dir;

    public function __construct(string $directory, array $options = [])
    {
        $this->directory = $directory;
        $this->options   = array_merge(self::OPTIONS, $options);
    }

    /**
     * Do the export using the options passed to the constructor.
     * Log errors using CApp::log.
     *
     * @throws Exception
     */
    public function export(): int
    {
        try {
            $this->patients = $this->getPatientsToExport();
        } catch (CMbModelNotFoundException $e) {
            CApp::log($e->getMessage(), null, CLogger::LEVEL_WARNING);

            $this->patients = [];
        }

        $this->back_tree = $this->buildBackRefsTree();
        $this->fw_tree   = $this->buildFwRefsTree();

        foreach ($this->patients as $patient) {
            try {
                $this->exportPatient($patient);
            } catch (CMbException $e) {
                CApp::log($e->getMessage(), null, CLogger::LEVEL_WARNING);
            }
        }

        // Build description file if not exists
        $this->writeFieldsDescriptionFile();

        $this->writeExportDescriptionFile();

        return count($this->patients);
    }

    /**
     * Write the export description file to the export directory if it's not already present.
     *
     * @throws Exception
     */
    protected function writeFieldsDescriptionFile(): void
    {
        $file_name = $this->directory . DIRECTORY_SEPARATOR . self::CLASSES_DESCRIPTION_FILE_NAME;

        if (!file_exists($file_name)) {
            try {
                $descriptions = (new CXMLPatientExportInfosGenerator($this->fw_tree, $this->back_tree))->generateInfos(new CPatient());
                (new CCSVPatientExportDescriptionWriter($file_name))->writeDescriptions($descriptions);
            } catch (CMbException $e) {
                CApp::log($e->getMessage(), null, CLogger::LEVEL_WARNING);
            }
        }
    }

    protected function writeExportDescriptionFile(): void
    {
        $file_name = $this->directory . DIRECTORY_SEPARATOR . self::EXPORT_DESCRIPTION_FILE_NAME;
        if (!file_exists($file_name)) {
            copy(dirname(__DIR__) . '/../resources/Export/export_format.md', $file_name);
        }
    }

    /**
     * Get the patients to export depending on the context.
     * If a patient_id is given in the options the export will be only for this patient.
     * If no patient_id is given, the export will use the start and step option to get the patients to export.
     *
     * @return CPatient[]
     *
     * @throws CMbModelNotFoundException
     */
    private function getPatientsToExport(): array
    {
        if ($patient_id = $this->options[self::OPTION_PATIENT]) {
            $patient     = CPatient::findOrFail($patient_id);
            $this->total = 1;

            return [$patient];
        }

        if (CAppUI::isGroup() || CAppUI::isCabinet()) {
            [$patients, $this->total] = CMbObjectExport::getPatientToExportFunction(
                $this->options[self::OPTION_PRATICIENS],
                $this->options[self::OPTION_START],
                $this->options[self::OPTION_STEP]
            );
        } else {
            [$patients, $this->total] = CMbObjectExport::getPatientsToExport(
                $this->options[self::OPTION_PRATICIENS],
                $this->options[self::OPTION_DATE_MIN],
                $this->options[self::OPTION_DATE_MAX],
                $this->options[self::OPTION_START],
                $this->options[self::OPTION_STEP],
                self::PATIENT_ORDER
            );
        }


        return $patients;
    }

    /**
     * Build a tree of backrefs that must be exported for each object.
     */
    private function buildBackRefsTree(): array
    {
        $back_tree = CMbObjectExport::DEFAULT_BACKREFS_TREE;

        if (CModule::getInstalled('notifications')) {
            $back_tree = array_merge($back_tree, CMbObjectExport::NOTIF_BACK_TREE);
        }

        return $back_tree;
    }

    /**
     * Build a tree of forward refs that must be exported for each object.
     */
    private function buildFwRefsTree(): array
    {
        $fw_tree = CMbObjectExport::DEFAULT_FWREFS_TREE;

        if (CModule::getInstalled('notifications')) {
            $fw_tree = array_merge($fw_tree, CMbObjectExport::NOTIF_FW_TREE);
        }

        return $fw_tree;
    }

    /**
     * Export a single patient to an XML file with all it's fields, fw_refs and back_refs.
     *
     * @throws CMbException
     */
    private function exportPatient(CPatient $patient): void
    {
        $this->current_dir = $this->directory . DIRECTORY_SEPARATOR . $patient->_guid;

        if (!$this->createDir()) {
            throw new CMbException('CXMLPatientExport-Error-Unable to create directory', $this->current_dir);
        }

        $export = $this->buildObjectExporter($patient);

        $xml = $export->toDOM()->saveXML();
        if (!$this->writeXmlFile($xml)) {
            throw new CMbException('CXMLPatientExport-Error-Unable to write file', $this->current_dir . '/export.xml');
        }
    }

    /**
     * @throws CMbException
     */
    private function buildObjectExporter(CPatient $patient): CMbObjectExport
    {
        $export = new CMbObjectExport($patient, $this->back_tree);
        $export->setForwardRefsTree($this->fw_tree);
        $export->setFilterCallback($this->getFilterCallback());
        $export->setObjectCallback($this->getObjectCallback());

        return $export;
    }

    protected function createDir(): bool
    {
        return CMbPath::forceDir($this->current_dir);
    }

    protected function writeXmlFile(string $xml): bool
    {
        return (bool)file_put_contents($this->current_dir . '/export.xml', $xml);
    }

    /**
     * Filter function which will be used to tell if an object must be exported or not.
     */
    private function getFilterCallback(): callable
    {
        return function (CStoredObject $object) {
            return CMbObjectExport::exportFilterCallback(
                $object,
                $this->options[self::OPTION_DATE_MIN],
                $this->options[self::OPTION_DATE_MAX],
                $this->options[self::OPTION_PRATICIENS],
                [],
                $this->options[self::OPTION_IGNORE_CONST_WITH_TAGS]
            );
        };
    }

    /**
     * Callback function that allow the modification of XML or actions after an object has been converted to
     * a DOMElement.
     */
    private function getObjectCallback(): callable
    {
        return function (CStoredObject $object) {
            CMbObjectExport::exportCallBack(
                $object,
                $this->current_dir,
                true,
                false,
                true
            );
        };
    }

    public function getTotal(): int
    {
        return $this->total;
    }
}
