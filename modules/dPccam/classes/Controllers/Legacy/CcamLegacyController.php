<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Ccam\Controllers\Legacy;

use Exception;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbArray;
use Ox\Core\CMbDT;
use Ox\Core\CMbPath;
use Ox\Core\CSQLDataSource;
use Ox\Core\CView;
use Hospilibre\Admin\CUser;
use Hospilibre\Ccam\CCCAM;
use Hospilibre\Ccam\CCCAMImport;
use Hospilibre\Ccam\CCodeCCAM;
use Hospilibre\Ccam\CDatedCodeCCAM;
use Hospilibre\Ccam\CFavoriCCAM;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mediusers\CSpecCPAM;
use Hospilibre\Patients\CPatient;
use Hospilibre\SalleOp\CActeCCAM;

class CcamLegacyController extends CLegacyController
{
    public function autocompleteAssociatedCcamCodes(): void
    {
        $this->checkPermRead();

        $requestedCode = CView::get('code', 'str notNull');
        $searchTerms   = CView::post('keywords', 'str');

        CView::checkin();
        CView::enableSlave();

        $datedCode = CDatedCodeCCAM::get($requestedCode);
        $datedCode->getActesAsso($searchTerms, 30);

        $collectedCodes = [];

        foreach ($datedCode->assos as $association) {
            $assoCode                  = $association['code'];
            $collectedCodes[$assoCode] = CDatedCodeCCAM::get($assoCode);
        }

        $this->renderSmarty('code_ccam_autocomplete.tpl', [
            'keywords' => $searchTerms,
            'codes'    => $collectedCodes,
        ]);
    }

    public function autocompleteCcamCodes(): void
    {
        $this->checkPermRead();

        $fieldName    = CView::request("input_field", "str default|_codes_ccam");
        $searchTerms  = CView::request($fieldName, "str default|%%");
        // Accepte aussi bien une date qu'un datetime
        $rawDate      = CView::request("date", 'str default|' . CMbDT::date());
        $mediuserId   = CView::request('user_id', 'ref class|CMediusers');
        $patientId    = CView::request('patient_id', 'ref class|CPatient');

        CView::checkin();
        CView::enableSlave();

        $mediuser = $mediuserId ? CMediusers::loadFromGuid("CMediusers-{$mediuserId}") : null;
        $patient  = $patientId ? CPatient::loadFromGuid("CPatient-{$patientId}") : null;

        $collectedCodes = [];
        $probe          = new CDatedCodeCCAM(null, CMbDT::date($rawDate));

        foreach ($probe->findCodes($searchTerms, $searchTerms) as $found) {
            $foundCode = $found["CODE"];
            $datedCode = CDatedCodeCCAM::get($foundCode, $rawDate);

            if ($datedCode->code != "-") {
                $collectedCodes[$foundCode] = $datedCode;
            }

            $datedCode->getPrice($mediuser, $patient, $rawDate);
        }

        $this->renderSmarty('code_ccam_autocomplete.tpl', [
            'keywords' => $searchTerms,
            'codes'    => $collectedCodes,
        ]);
    }

    public function searchCcamCodes(): void
    {
        $this->checkPermRead();

        $objectClass = CView::get("object_class", 'str', true);
        $searchTerms = CView::get("clefs", 'str', true);
        $singleCode  = CView::get("code", 'str', true);
        $selAccess   = CView::get("selacces", 'str', true);
        $selTopo1    = CView::get("seltopo1", 'str', true);
        $selTopo2    = CView::get("seltopo2", 'str', true);

        $chap1 = CView::get("chap1", 'str');
        $chap2 = $chap1 ? CView::get("chap2", 'str') : null;
        $chap3 = $chap2 ? CView::get("chap3", 'str') : null;
        $chap4 = $chap2 ? CView::get("chap4", 'str') : null;

        CView::checkin();

        $accessList = CCCAM::getAccesses();
        $topoList1  = CCCAM::getFirstLevelTopographies();
        $topoList2  = $selTopo1 ? CCCAM::getSecondLevelTopographies($selTopo1) : [];

        $chapList1 = CCCAM::getChapters();
        $chapList2 = $chap1 ? CCCAM::getChapters($chap1) : [];
        $chapList3 = $chap2 ? CCCAM::getChapters($chap2) : [];
        $chapList4 = $chap3 ? CCCAM::getChapters($chap3) : [];

        $foundCodes = [];

        $hasFilter = $singleCode || $searchTerms || $selAccess || $selTopo1
            || $chap1 || $chap2 || $chap3 || $chap4;

        if ($hasFilter) {
            $rows = (new CDatedCodeCCAM(null, CMbDT::date()))->findCodes(
                $singleCode,
                $searchTerms,
                null,
                null,
                $selAccess,
                $selTopo1,
                $selTopo2,
                $chap1 ? $chapList1[$chap1]["rank"] : null,
                $chap2 ? $chapList2[$chap2]["rank"] : null,
                $chap3 ? $chapList3[$chap3]["rank"] : null,
                $chap4 ? $chapList4[$chap4]["rank"] : null
            );

            foreach ($rows as $row) {
                $foundCodes[] = CDatedCodeCCAM::get($row["CODE"]);
            }
        }

        $this->renderSmarty('vw_find_code.tpl', [
            'object_class' => $objectClass,
            'clefs'        => $searchTerms,
            'selacces'     => $selAccess,
            'seltopo1'     => $selTopo1,
            'seltopo2'     => $selTopo2,
            'chap1'        => $chap1,
            'chap2'        => $chap2,
            'chap3'        => $chap3,
            'chap4'        => $chap4,
            'code'         => $singleCode,
            'acces'        => $accessList,
            'topo1'        => $topoList1,
            'topo2'        => $topoList2,
            'listChap1'    => $chapList1,
            'listChap2'    => $chapList2,
            'listChap3'    => $chapList3,
            'listChap4'    => $chapList4,
            'codes'        => $foundCodes,
            'numcodes'     => count($foundCodes),
        ]);
    }

    public function viewCcamCode(): void
    {
        $this->checkPermRead();

        $mediuser = CMediusers::get();

        $fallbackSpec     = $mediuser->spec_cpam_id ?: 1;
        $fallbackSector   = $mediuser->secteur ?: '1';
        $fallbackContract = $mediuser->pratique_tarifaire ?: '1';

        $acteCode    = CView::get('_codes_ccam', 'str', true);
        $objectClass = CView::get(
            "object_class",
            'enum list|COperation|CSejour|CConsultation default|COperation'
        );
        $hideSelect       = CView::get("hideSelect", 'bool default|0');
        $patientSituation = CView::get('situation_patient', 'enum list|c2s|acs|none default|none');
        $speciality       = CView::get('speciality', "num min|1 max|80 default|$fallbackSpec");
        $contract         = CView::get('contract', "enum list|optam|optamco|none default|$fallbackContract");
        $sector           = CView::get('sector', "enum list|1|1dp|2|nc default|$fallbackSector");
        $requestedDate    = CView::get('date', 'date');

        CView::checkin();

        $requestedDate = CMbDT::date($requestedDate);

        $patient = new CPatient();
        if ($patientSituation == 'c2s') {
            $patient->c2s = '1';
        } elseif ($patientSituation == 'acs') {
            $patient->acs = '1';
        }

        $mediuser->spec_cpam_id       = $speciality;
        $mediuser->secteur            = $sector;
        $mediuser->pratique_tarifaire = $contract;

        $datedCode = new CDatedCodeCCAM();
        if ($acteCode) {
            $datedCode = CDatedCodeCCAM::get($acteCode, $requestedDate);
        }

        // Détermine si l'on doit afficher le code sous sa forme complète
        $fullCode = false;
        if ($datedCode) {
            $acteCode = $datedCode->code;

            if ($datedCode->_activite != "") {
                $fullCode = true;
                $acteCode .= "-$datedCode->_activite";
                if ($datedCode->_phase != "") {
                    $acteCode .= "-$datedCode->_phase";
                }
            }

            $datedCode->getPrice($mediuser, $patient, $requestedDate);
        }
        $acteCode = strtoupper($acteCode);

        $favoris = new CFavoriCCAM();

        $this->renderSmarty('vw_full_code.tpl', [
            'code'              => $datedCode,
            'codeComplet'       => $fullCode,
            'favoris'           => $favoris,
            'codeacte'          => $acteCode,
            'object_class'      => $objectClass,
            'hideSelect'        => $hideSelect,
            'situation_patient' => $patientSituation,
            'speciality'        => $speciality,
            'specialities'      => CSpecCPAM::getList(),
            'contract'          => $contract,
            'sector'            => $sector,
            'date'              => $requestedDate,
            'user'              => $mediuser,
        ]);
    }

    public function viewDetailCodeCcam(): void
    {
        $this->checkPermRead();

        $acteCode    = CView::get("codeacte", 'str');
        $objectClass = CView::get("object_class", 'str');

        CView::checkin();

        $datedCode = CDatedCodeCCAM::get($acteCode);
        $favoris   = new CFavoriCCAM();

        $this->renderSmarty('inc_vw_detail_ccam.tpl', [
            'code'         => $datedCode,
            'favoris'      => $favoris,
            'object_class' => $objectClass,
        ]);
    }

    public function showCcamCode(): void
    {
        $this->checkPermRead();

        $mediuser = CMediusers::get();

        $fallbackSpec     = $mediuser->spec_cpam_id ?: 1;
        $fallbackSector   = $mediuser->secteur ?: '1';
        $fallbackContract = $mediuser->pratique_tarifaire ?: '1';

        $ccamCode         = CView::get("code_ccam", 'str notNull');
        $versionDate      = CView::get("date_version", 'str');
        $askedDate        = CView::get("date_demandee", 'str');
        $patientSituation = CView::get('situation_patient', 'enum list|c2s|acs|none default|none');
        $speciality       = CView::get('speciality', "num min|1 max|80 default|$fallbackSpec");
        $contract         = CView::get('contract', "enum list|optam|optamco|none default|$fallbackContract");
        $sector           = CView::get('sector', "enum list|1|1dp|2|nc default|$fallbackSector");

        CView::checkin();

        $patient = new CPatient();
        if ($patientSituation == 'c2s') {
            $patient->c2s = '1';
        } elseif ($patientSituation == 'acs') {
            $patient->acs = '1';
        }

        $mediuser->spec_cpam_id       = $speciality;
        $mediuser->secteur            = $sector;
        $mediuser->pratique_tarifaire = $contract;

        $versionTo = null;
        if ($askedDate) {
            $versionTo = CDatedCodeCCAM::mapDateToDash($askedDate);
            $askedDate = CDatedCodeCCAM::mapDateFrom($versionTo);
        }
        if ($versionDate) {
            $versionTo = CDatedCodeCCAM::mapDateToSlash($versionDate);
        }

        $priceDate = CMbDT::dateFromLocale($versionDate);

        $availableVersions = [];
        $fullCode          = CDatedCodeCCAM::get($ccamCode, $versionTo);

        foreach ($fullCode->_ref_code_ccam->_ref_infotarif as $tarifInfo) {
            $availableVersions[] = $fullCode->mapDateFrom($tarifInfo->date_effet);
        }
        foreach ($fullCode->activites as $activite) {
            $fullCode->_count_activite += count($activite->assos);
        }

        $fullCode->getPrice($mediuser, $patient, $priceDate);
        $neighbourActs = $fullCode->loadActesVoisins();

        $parameters = [
            'code_complet'       => $fullCode,
            "numberAssociations" => $fullCode->_count_activite,
            'date_versions'      => $availableVersions,
            'date_version'       => $versionDate,
            'date_demandee'      => $askedDate,
            'code_ccam'          => $ccamCode,
            'acte_voisins'       => $neighbourActs,
            'situation_patient'  => $patientSituation,
            'speciality'         => $speciality,
            'specialities'       => CSpecCPAM::getList(),
            'contract'           => $contract,
            'sector'             => $sector,
        ];

        if ($askedDate && !in_array($askedDate, $availableVersions)) {
            $parameters["no_date_found"] = "CDatedCodeCCAM-msg-No date found for date searched";
        }

        $this->renderSmarty("inc_show_code.tpl", $parameters);
    }

    public function viewSearchCodeCcamHistory(): void
    {
        $this->checkPermRead();

        $pageOffset = intval(CView::get('page', 'num default|0'));

        CView::checkin();

        $rootChapters = CCCAM::getChapters();

        $this->renderSmarty('vw_find_acte.tpl', [
            'listChap1' => $rootChapters,
            'page'      => $pageOffset,
        ]);
    }

    public function searchCodeCcamHistory(): void
    {
        $this->checkPermRead();

        $pageOffset  = intval(CView::get('page', 'num default|0'));
        $pageSize    = 22;
        $searchTerms = CView::get('keywords', 'str');
        $singleCode  = CView::get('code', 'str');
        $askedDate   = CView::get('date_demandee', 'date');
        $resChap1    = CView::get('result_chap1', 'str');
        $resChap2    = CView::get('result_chap2', 'str');
        $resChap3    = CView::get('result_chap3', 'str');
        $resChap4    = CView::get('result_chap4', 'str');

        CView::checkin();

        $versionDate = $askedDate ? CDatedCodeCCAM::mapDateToDash($askedDate) : null;

        if (!$singleCode && $askedDate) {
            $this->renderSmarty('inc_result_search_acte.tpl', [
                "no_code_for_date" => "CDatedCodeCCAM-msg-No code for date",
            ]);
            CApp::rip();
        }

        if (!$singleCode && !$searchTerms && !$resChap1 && !$askedDate) {
            $this->renderSmarty('inc_result_search_acte.tpl', [
                "no_filter" => "CDatedCodeCCAM-msg-No filter",
            ]);
            CApp::rip();
        }

        $where = "DATEFIN = '00000000'";
        $limit = "LIMIT {$pageOffset}, {$pageSize}";
        if ($singleCode && $versionDate) {
            $where = "DATEEFFET >= '{$versionDate}'";
            $limit = "LIMIT 0, 1";
        }

        $total = CCodeCCAM::countCodes(
            $singleCode,
            $searchTerms,
            null,
            $where,
            null,
            null,
            null,
            $resChap1,
            $resChap2,
            $resChap3,
            $resChap4
        );

        $rows = CCodeCCAM::findCodes(
            $singleCode,
            $searchTerms,
            null,
            $where,
            null,
            null,
            null,
            $resChap1,
            $resChap2,
            $resChap3,
            $resChap4,
            $limit
        );

        $foundCodes = [];
        foreach ($rows as $row) {
            $datedCode                                = CDatedCodeCCAM::get($row["CODE"]);
            $datedCode->_ref_code_ccam->date_creation = CDatedCodeCCAM::mapDateFrom(
                $datedCode->_ref_code_ccam->date_creation
            );
            foreach ($datedCode->_ref_code_ccam->_ref_infotarif as $tarifInfo) {
                $tarifInfo->date_effet = CDatedCodeCCAM::mapDateFrom($tarifInfo->date_effet);
            }
            $foundCodes[] = $datedCode;
        }

        $this->renderSmarty('inc_result_search_acte.tpl', [
            'keywords_multiple' => $searchTerms,
            'codes'             => $foundCodes,
            'nbResultat'        => $total,
            'page'              => $pageOffset,
            'step'              => $pageSize,
            'date_demandee'     => $askedDate,
        ]);
    }

    public function selectorCodeCcam(): void
    {
        $this->checkPermRead();

        $chir         = CView::get("chir", 'ref class|CMediusers');
        $anesth       = CView::get("anesth", 'ref class|CMediusers');
        $keywordsCode = CView::get("_keywords_code", 'str');
        $date         = CMbDT::date(null, CView::get("date", 'str'));
        $objectClass  = CView::get("object_class", 'str');
        $onlyList     = CView::get("only_list", 'bool default|0');
        $tagId        = CView::get("tag_id", 'ref class|CTagItem');
        $access       = CView::get('access', 'str');
        $appareil     = CView::get('appareil', 'str');
        $system       = CView::get('system', 'str');
        $chapter1     = CView::get('chapter_1', 'str');
        $chapter2     = CView::get('chapter_2', 'str');
        $chapter3     = CView::get('chapter_3', 'str');
        $chapter4     = CView::get('chapter_4', 'str');
        $ged          = CView::get('ged', 'bool default|0');

        CView::checkin();

        $currentUser = CUser::get();
        $profiles    = [
            "chir"   => $chir,
            "anesth" => $anesth,
            "user"   => $currentUser->_id,
        ];

        if ($profiles["user"] == $profiles["anesth"] || $profiles["user"] == $profiles["chir"]) {
            unset($profiles["user"]);
        }

        if (!$profiles["anesth"]) {
            unset($profiles["anesth"]);
        }

        if (!$profiles["chir"]) {
            unset($profiles["chir"]);
        }

        $ds = CSQLDataSource::get('ccamV2');

        // Voies d'accès
        $accessWays = CCCAM::getAccesses();

        // Appareils
        $appareils = CCCAM::getFirstLevelTopographies();

        // Systèmes
        $systems = CCCAM::getSecondLevelTopographies();

        // Chapitres
        $chapters1 = CCCAM::getChapters();
        $chapters2 = $chapter1 ? CCCAM::getChapters($chapter1) : [];
        $chapters3 = $chapter2 ? CCCAM::getChapters($chapter2) : [];
        $chapters4 = $chapter3 ? CCCAM::getChapters($chapter3) : [];

        $hasSearchFilter = $keywordsCode || $access || $system || $appareil
            || $chapter1 || $chapter2 || $chapter3 || $chapter4;

        if ($hasSearchFilter) {
            $profiles['search'] = null;
        }

        $listByProfile = [];
        $users         = [];

        foreach ($profiles as $profile => $profileUserId) {
            $isSearch        = $profile == 'search';
            $profileUser     = $isSearch ? new CMediusers() : CMediusers::get($profileUserId);
            $users[$profile] = $profileUser;

            $list      = [];
            $codeStats = [];

            // Statistiques
            if (!$tagId && $profileUserId) {
                $acte      = new CActeCCAM();
                $codeStats = $acte->getFavoris($profileUserId, $objectClass);

                foreach ($codeStats as $statKey => $statEntry) {
                    $codeStats[$statEntry["code_acte"]] = $statEntry;
                    unset($codeStats[$statKey]);
                }
            }

            // Favoris
            $codeFavoris = [];
            if (!$isSearch) {
                $profileUser = CMediusers::get($profileUserId);
                $favori      = new CFavoriCCAM();
                $where       = [
                    "ccamfavoris.favoris_user = '$profileUser->_id' OR ccamfavoris.favoris_function = $profileUser->function_id",
                    "ccamfavoris.object_class" => " = '$objectClass'",
                ];

                $ljoin = [];
                if ($tagId) {
                    $where["tag_item.tag_id"]       = "= '$tagId'";
                    $where['tag_item.object_class'] = " = 'CFavoriCCAM'";
                    $ljoin["tag_item"]              = "tag_item.object_id = ccamfavoris.favoris_id";
                }

                // rang DESC place les rangs null en fin de liste
                /** @var CFavoriCCAM[] $codeFavoris */
                $codeFavoris = $favori->loadList($where, "rang DESC", 100, null, $ljoin);

                foreach ($codeFavoris as $favKey => $favEntry) {
                    $codeFavoris[$favEntry->favoris_code] = $favEntry;
                    unset($codeFavoris[$favKey]);
                }
            }

            // Recherche des codes, avec ou sans inclusion de l'ensemble des codes
            $datedCode = new CDatedCodeCCAM("");
            $where     = null;

            if (!$isSearch && (count($codeStats) || count($codeFavoris))) {
                // En recherche par tag, on écarte les stats (les tags sont posés sur les favoris)
                $codeKeys = $tagId
                    ? array_keys($codeFavoris)
                    : array_keys(array_merge($codeStats, $codeFavoris));
                $where    = "CODE " . $ds->prepareIn($codeKeys);
            }

            if (!$isSearch && count($codeStats) == 0 && count($codeFavoris) == 0) {
                // Aucune stat ni favori et recherche restreinte à ceux-ci : résultat vide
                $codes = [];
            } else {
                $codes = $datedCode->findCodes(
                    $keywordsCode,
                    $keywordsCode,
                    null,
                    $where,
                    $access,
                    $appareil,
                    $system,
                    $chapter1 ? $chapters1[$chapter1]["rank"] : null,
                    $chapter2 ? $chapters2[$chapter2]["rank"] : null,
                    $chapter3 ? $chapters3[$chapter3]["rank"] : null,
                    $chapter4 ? $chapters4[$chapter4]["rank"] : null,
                );
            }

            $listFav = $listFavNoRang = [];
            foreach ($codes as $codeRow) {
                $rowCode   = $codeRow["CODE"];
                $datedCode = CDatedCodeCCAM::get($rowCode, $date);

                if (!$isSearch && $datedCode->code != "-") {
                    $list[$rowCode] = $datedCode;
                    $nbActe         = 0;
                    if (isset($codeFavoris[$rowCode])) {
                        $list[$rowCode]->nb_acte  = 0.5;
                        $list[$rowCode]->_favoris = $codeFavoris[$rowCode];

                        $firstChapter         =& $datedCode->chapitres[0];
                        $list[$rowCode]->chap = $firstChapter["nom"];

                        if (isset($list[$rowCode]->_favoris) && !$list[$rowCode]->_favoris->rang) {
                            $listFavNoRang[$rowCode] = $list[$rowCode];
                        } else {
                            $listFav[$rowCode] = $list[$rowCode];
                        }
                    }

                    $nbActe = isset($codeStats[$rowCode]) ? $codeStats[$rowCode]["nb_acte"] : 0;

                    $list[$rowCode]->nb_acte = $nbActe;
                    if (!$nbActe) {
                        $listFavNoRang[$rowCode] = $list[$rowCode];
                        unset($list[$rowCode]);
                    }
                } elseif ($datedCode->code != "-") {
                    $list[$rowCode] = $datedCode;
                }
            }

            if ($tagId || $isSearch) {
                $sorter = CMbArray::pluck($list, "code");
                array_multisort($sorter, SORT_ASC, $list);
            } else {
                $sorter = CMbArray::pluck($list, "nb_acte");
                array_multisort($sorter, SORT_DESC, $list);
            }

            $rangSorter = CMbArray::pluck($listFav, "_favoris", "rang");
            $codeSorter = CMbArray::pluck($listFav, "code");
            array_multisort(
                $rangSorter,
                SORT_ASC,
                $codeSorter,
                SORT_ASC,
                $listFav
            );
            $listFav = array_merge($listFav, $listFavNoRang);

            $listByProfile[$profile]["favoris"] = $codeFavoris;
            $listByProfile[$profile]["stats"]   = $codeStats;
            $listByProfile[$profile]["list"]    = ["favoris" => $listFav, "stats" => $list];
            $listByProfile[$profile]['total']   = count($listFav) + count($list);
        }

        $tagTree = CFavoriCCAM::getTree($currentUser->_id);

        $template   = 'inc_code_selector_ccam.tpl';
        $parameters = [
            'listByProfile'  => $listByProfile,
            'users'          => $users,
            'object_class'   => $objectClass,
            'date'           => $date,
            '_keywords_code' => $keywordsCode,
            'tag_tree'       => $tagTree,
            'tag_id'         => $tagId,
            'curr_user'      => CMediusers::get(),
            'ged'            => $ged,
        ];

        if (!$onlyList) {
            $parameters['chir']       = $chir;
            $parameters['anesth']     = $anesth;
            $parameters['access']     = $accessWays;
            $parameters['appareils']  = $appareils;
            $parameters['systems']    = $systems;
            $parameters['chapters_1'] = $chapters1;
            $parameters['chapters_2'] = $chapters2;
            $parameters['chapters_3'] = $chapters3;
            $parameters['chapters_4'] = $chapters4;

            $template = "code_selector_ccam.tpl";
        }

        $this->renderSmarty($template, $parameters);
    }

    public function refreshChapters(): void
    {
        $this->checkPermRead();

        $selectedValue = CView::get("value_selected", 'str');
        $parentCode    = CView::get("codePere", 'str');

        CView::checkin();

        $chapters = CCCAM::getChapters($parentCode);

        $this->renderSmarty('inc_select_codes.tpl', [
            'value_selected' => $selectedValue,
            'list'           => $chapters,
        ]);
    }

    public function listChapters(): void
    {
        $this->checkPermRead();

        $parent = CView::get('parent', 'str');
        CView::checkin();

        $this->renderJson(CCCAM::getChapters($parent));
    }

    /**
     * @throws Exception
     */
    public function importCcamDatabase(): void
    {
        $this->checkPermAdmin();
        CView::checkin();

        CApp::setTimeLimit(360);

        $import = new CCCAMImport();
        $import->importDatabase(['ccamv2']);

        foreach ($import->getMessages() as $message) {
            CAppUI::stepAjax(...$message);
        }

        CApp::rip();
    }

    /**
     * @throws Exception
     */
    public function importCccamForfaitsDatabase(): void
    {
        $this->checkPermAdmin();
        CView::checkin();

        CApp::setTimeLimit(360);
        ini_set("memory_limit", "128M");

        $import = new CCCAMImport();
        $import->importDatabase(['ccamv2_forfaits']);

        foreach ($import->getMessages() as $message) {
            CAppUI::stepAjax(...$message);
        }

        CApp::rip();
    }

    public function configure(): void
    {
        $this->checkPermAdmin();
        $this->renderSmarty('configure.tpl');
    }
}
