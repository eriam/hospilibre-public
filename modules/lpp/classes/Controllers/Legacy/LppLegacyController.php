<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Lpp\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CLegacyController;
use Ox\Core\CMbObject;
use Ox\Core\CView;
use Hospilibre\Ccam\CCodable;
use Hospilibre\Lpp\CActeLPP;
use Hospilibre\Lpp\Exceptions\LppDatabaseException;
use Hospilibre\Lpp\Repository\LppChapterRepository;
use Hospilibre\Lpp\Repository\LppCodeRepository;
use Hospilibre\Lpp\Repository\LppPricingRepository;
use Hospilibre\Mediusers\CMediusers;

class LppLegacyController extends CLegacyController
{
    /**
     * Affiche la page de recherche des codes LPP (vue racine).
     *
     * @throws \Exception
     */
    public function viewSearch(): void
    {
        $this->checkPermRead();

        CView::checkin();

        try {
            $rootChapters = LppChapterRepository::getInstance()->loadChaptersFromParent('0');
        } catch (LppDatabaseException $error) {
            $error->stepAjax(UI_MSG_ERROR);
            CApp::rip();

            return;
        }

        $this->renderSmarty('vw_search', [
            'chapters' => $rootChapters,
            'codes'    => [],
            'start'    => 0,
            'total'    => 0,
        ]);
    }

    /**
     * Renvoie en JSON les chapitres directement rattachés au chapitre demandé.
     *
     * @throws \Exception
     */
    public function getDescendantChapters(): void
    {
        $this->checkPermRead();

        $parentId = CView::get('parent_id', 'str');

        CView::checkin();

        try {
            $childChapters = LppChapterRepository::getInstance()->loadChaptersFromParent($parentId);
        } catch (LppDatabaseException $error) {
            $this->renderJson(['msg' => $error->getMessage()]);

            return;
        }

        $payload = [
            'level'    => strlen($parentId),
            'chapters' => [],
        ];

        foreach ($childChapters as $chapter) {
            $payload['chapters'][] = [
                'id'   => $chapter->id,
                'view' => "$chapter->rank - $chapter->name",
            ];
        }

        $this->renderJson($payload);
    }

    /**
     * Recherche les codes LPP correspondant aux critères transmis.
     *
     * @throws \Exception
     */
    public function searchLppCodes(): void
    {
        $this->checkPermRead();

        $codeFilter = CView::get('code', 'str');
        $textFilter = CView::get('text', 'str');
        $chapterId  = CView::get('chapter_id', 'str');
        $offset     = CView::get('start', 'num default|0');

        CView::checkin();

        try {
            $repository = LppCodeRepository::getInstance();
            $foundCodes = $repository->search($codeFilter, $textFilter, $chapterId, null, $offset, 100);
            $matchCount = $repository->count($codeFilter, $textFilter, $chapterId);
        } catch (LppDatabaseException $error) {
            $error->stepAjax(UI_MSG_ERROR);
            CApp::rip();

            return;
        }

        $this->renderSmarty('inc_search_results', [
            'codes' => $foundCodes,
            'start' => $offset,
            'total' => $matchCount,
        ]);
    }

    /**
     * Affiche le détail d'un code LPP avec ses tarifs et relations.
     *
     * @throws \Exception
     */
    public function viewCode(): void
    {
        $this->checkPermRead();

        $codeValue = CView::get('code', 'str notNull');

        CView::checkin();

        try {
            $lppCode = LppCodeRepository::getInstance()->load($codeValue);
            $lppCode->loadLastPricing();
            $lppCode->loadPricings();
            $lppCode->loadParent();
            $lppCode->loadCompatibilities();
            $lppCode->loadIncompatibilities();
        } catch (LppDatabaseException $error) {
            $error->stepAjax(UI_MSG_ERROR);
            CApp::rip();

            return;
        }

        $this->renderSmarty('inc_code', ['code' => $lppCode]);
    }

    /**
     * Affiche le tarif LPP applicable à un code pour une date donnée.
     *
     * @throws \Exception
     */
    public function viewCodePricing(): void
    {
        $this->checkPermRead();

        $codeValue    = CView::get('code', 'str notNull');
        $pricingDate  = CView::get('date', 'date notNull');

        CView::checkin();

        try {
            $pricing = LppPricingRepository::getInstance()->loadFromDate($codeValue, $pricingDate);
        } catch (LppDatabaseException $error) {
            $error->stepAjax(UI_MSG_ERROR);
            CApp::rip();

            return;
        }

        $this->renderSmarty('inc_pricing', ['pricing' => $pricing]);
    }

    /**
     * Fournit les suggestions d'autocomplétion pour les codes LPP.
     *
     * @throws \Exception
     */
    public function codeAutocomplete(): void
    {
        $this->checkPermRead();

        $searchTerm = CView::post('code', 'str');
        $executantId = CView::post('executant_id', 'ref class|CMediusers');
        $referenceDate = CView::post('date', 'date');

        CView::checkin();

        try {
            $repository  = LppCodeRepository::getInstance();
            $suggestions = $repository->search($searchTerm, $searchTerm, null, $referenceDate, 0, 100);

            $allowedPrestationCodes = [];
            if ($executantId) {
                $executant = CMediusers::get($executantId);
                if ($executant->spec_cpam_id) {
                    $allowedPrestationCodes = $repository->getAllowedPrestationCodesForSpeciality(
                        $executant->spec_cpam_id
                    );
                }
            }

            foreach ($suggestions as $index => $suggestion) {
                $suggestion->loadLastPricing($referenceDate);
                $suggestion->getQualificatifsDepense();

                if (!$suggestion->_last_pricing->code) {
                    unset($suggestions[$index]);
                    continue;
                }

                if ($executantId
                    && !in_array($suggestion->_last_pricing->prestation_code, $allowedPrestationCodes)) {
                    unset($suggestions[$index]);
                }
            }
        } catch (LppDatabaseException $error) {
            $error->stepAjax(UI_MSG_ERROR);
            CApp::rip();

            return;
        }

        $this->renderSmarty('inc_code_autocomplete', ['codes' => $suggestions]);
    }

    /**
     * Affiche l'interface de codage LPP pour un objet codable.
     *
     * @throws \Exception
     */
    public function codageLpp(): void
    {
        $this->checkPermRead();

        $objectClass = CView::get('object_class', 'str');
        $objectId    = CView::get('object_id', 'ref meta|object_class');

        CView::checkin();

        /** @var CCodable $codable */
        $codable = CMbObject::loadFromGuid("$objectClass-$objectId");

        $codable->loadRefsActesLPP();
        foreach ($codable->_ref_actes_lpp as $acte) {
            $acte->loadRefExecutant();
            $acte->_ref_executant->loadRefFunction();
        }

        $newActeLpp = CActeLPP::createFor($codable);

        $this->renderSmarty('inc_codage_lpp', [
            'codable'  => $codable,
            'acte_lpp' => $newActeLpp,
        ]);
    }

    /**
     * Affiche l'écran de configuration du module.
     */
    public function configure(): void
    {
        $this->checkPermAdmin();

        $this->renderSmarty('configure');
    }
}
