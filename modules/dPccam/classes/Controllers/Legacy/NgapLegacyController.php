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
use Ox\Core\CMbObject;
use Ox\Core\CView;
use Hospilibre\Cabinet\CActeNGAP;
use Hospilibre\Ccam\CCCAMImport;
use Hospilibre\Ccam\CCodable;
use Hospilibre\Ccam\CCodeNGAP;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mediusers\CSpecCPAM;
use Hospilibre\PlanningOp\CSejour;

class NgapLegacyController extends CLegacyController
{
    public function ngapCodeAutocomplete(): void
    {
        $this->checkPermRead();

        $codableClass = CView::get("object_class", 'str');
        $codableId    = CView::get("object_id", "ref class|$codableClass");
        $searchTerm   = CView::post("code", 'str');
        $practitionerId = CView::post("executant_id", 'ref class|CMediusers');
        $executionDate  = CView::post('date', ['date', 'default' => CMbDT::date()]);
        $specialityCode = CView::post('speciality', 'str');
        $isEmergency    = CView::request('urgences', 'bool default|0');

        CView::checkin();
        CView::enableSlave();

        /** @var CCodable $codable */
        $codable = new $codableClass();
        $codable->load($codableId);
        $codable->countActes();
        $codable->loadRefsActes();

        $practitioner = CMediusers::get($practitionerId);
        if (!$practitionerId && $specialityCode) {
            $practitioner               = new CMediusers();
            $practitioner->spec_cpam_id = $specialityCode;
        }

        // La spécialité est indéfinie tant qu'aucun spec_cpam_id n'est renseigné.
        $specUndefined = !$practitioner->spec_cpam_id;

        $matchingCodes = CCodeNGAP::search(
            $searchTerm,
            $practitioner,
            $executionDate,
            !$codable->_count_actes,
            true,
            (bool)$isEmergency
        );

        foreach ($matchingCodes as $codeKey => $ngapCode) {
            $ngapCode->getTarifFor($practitioner, $executionDate);

            if ($ngapCode->lettre_cle || empty($ngapCode->associations)) {
                continue;
            }

            $associationAllowed = false;

            if (in_array('NGAP', $ngapCode->associations) && count($codable->_ref_actes_ngap)) {
                $associationAllowed = true;
            } elseif (in_array('CCAM', $ngapCode->associations) && count($codable->_ref_actes_ccam)) {
                $associationAllowed = true;
            } else {
                foreach ($codable->_ref_actes_ngap as $existingActe) {
                    if (in_array($existingActe->code, $ngapCode->associations)) {
                        $associationAllowed = true;
                        break;
                    }
                }
            }

            if (!$associationAllowed) {
                unset($matchingCodes[$codeKey]);
            }
        }

        $this->renderSmarty('code_ngap_autocomplete.tpl', [
            'code'          => $searchTerm,
            'codes'         => $matchingCodes,
            'spe_undefined' => $specUndefined,
        ]);
    }

    public function ngapIndex(): void
    {
        $this->checkPermRead();
        CView::checkin();

        $specialities = CSpecCPAM::getList();

        $this->renderSmarty('vw_ngap.tpl', [
            'specs' => $specialities,
            'spec'  => reset($specialities),
            'date'  => CMbDT::date(),
        ]);
    }

    public function ngapCodeSearch(): void
    {
        $this->checkPermRead();

        $specId       = CView::get('spec', 'num default|1');
        $searchDate   = CView::get('date', ['date', 'default' => CMbDT::date()]);
        $geoZone      = CView::get('zone', 'enum list|metro|antilles|mayotte|guyane-reunion default|metro');
        $isEmergency  = CView::post('urgence', 'bool default|0');

        CView::checkin();
        CView::enableSlave();

        $speciality = CSpecCPAM::get($specId);
        $codeList   = CCodeNGAP::getForSpeciality($speciality, $searchDate, $geoZone, (bool)$isEmergency);

        $this->renderSmarty('inc_list_codes_ngap.tpl', [
            'spec'  => $speciality,
            'date'  => $searchDate,
            'codes' => $codeList,
        ]);
    }

    public function viewDuplicateNgap(): void
    {
        $this->checkPermEdit();

        $codableGuid = CView::get('codable_guid', 'guid class|CCodable');
        $acteGuid    = CView::get('acte_guid', 'guid class|CActeNGAP');

        CView::checkin();

        /** @var CCodable $codable */
        $codable = CMbObject::loadFromGuid($codableGuid);

        if (!$codable->_id) {
            return;
        }

        $acte = CActeNGAP::loadFromGuid($acteGuid);

        $this->renderSmarty('inc_duplicate_ngap.tpl', [
            'codable' => $codable,
            'acte'    => $acte,
        ]);
    }

    public function duplicateNgap(): void
    {
        $this->checkPermEdit();

        $codableGuid   = CView::post('codable_guid', 'str');
        $acteIds       = explode('|', CView::post('actes', 'str'));
        $multipleDates = CView::post("multiple_date", "str");
        $dateType      = CView::post("type_of_date", "str default|one_date");

        /** @var CSejour $codable */
        $codable   = CMbObject::loadFromGuid($codableGuid);
        $endDate   = CView::post('date', ['date', 'default' => $codable->sortie]);

        CView::checkin();

        if (!$codable->_id) {
            return;
        }

        $codable->loadRefsActesNGAP();

        foreach ($acteIds as $acteId) {
            if (!array_key_exists($acteId, $codable->_ref_actes_ngap)) {
                continue;
            }

            $acte = $codable->_ref_actes_ngap[$acteId];

            $baseDateTime = null;
            if ($dateType != "one_date") {
                $multipleDates = explode(",", $multipleDates);
                $iterations    = count($multipleDates);
            } else {
                $iterations   = CMbDT::daysRelative($acte->execution, CMbDT::format($endDate, '%Y-%m-%d 00:00:00')) + 1;
                $baseDateTime = CMbDT::dateTime(null, $acte->execution);
            }

            $timePart = CMbDT::time(null, $acte->execution);

            for ($step = 1; $step <= $iterations; $step++) {
                if ($dateType != "one_date") {
                    $acte->execution = $multipleDates[$step - 1] . " " . $timePart;
                } else {
                    $acte->execution = CMbDT::date("+$step DAYS", $baseDateTime) . " $timePart";
                }

                $acte->_id = null;
                $acte->store();
            }
        }
    }

    /**
     * @throws Exception
     */
    public function importNgapDatabase(): void
    {
        $this->checkPermAdmin();
        CView::enableSlave();
        CApp::setTimeLimit(360);
        ini_set("memory_limit", "128M");

        $import = new CCCAMImport();
        $import->importDatabase(['ngap']);

        foreach ($import->getMessages() as $importMessage) {
            CAppUI::stepAjax(...$importMessage);
        }
    }
}
