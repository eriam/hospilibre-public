<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Sample\Controllers\Legacy;

use Exception;
use Ox\Core\Api\Exceptions\ApiException;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CCanDo;
use Ox\Core\CLegacyController;
use Ox\Core\CMbException;
use Ox\Core\CView;
use Ox\Core\EntryPoint;
use Hospilibre\Sample\Import\MovieDb\SampleMovieImport;
use Hospilibre\Sample\Import\SampleCategoryImport;
use Hospilibre\Sample\Import\SampleNationalityImport;
use Hospilibre\System\CExchangeSource;
use Hospilibre\System\CSourceHTTP;

/**
 * Acces to the configuration of the module
 */
class SampleConfigureController extends CLegacyController
{
    public function configure(): void
    {
        $this->checkPermAdmin();

        $this->renderSmarty(
            'configure',
            [
                'source_available' => SampleMovieImport::isSourceAvailable(),
                'source'           => SampleMovieImport::getSource(),
                'base_url'         => rtrim(CApp::getBaseUrl(), '/'),
            ]
        );
    }

    /**
     * Display a list of persons as json api.
     *
     * @throws ApiException|CMbException|Exception
     */
    public function displayPersons(): void
    {
        $this->checkPermAdmin();

        CView::checkin();

        $entry = new EntryPoint('SampleMovieSettings');
        $entry->setScriptName('sampleMovieSettings')
            ->setLinks(
                [
                    'nationalities' => $this->generateUrl('sample_nationalities_list'),
                    'personsList'   => $this->generateUrl('sample_persons_list'),
                ]
            );

        if (CCanDo::edit()) {
            $entry->addLinkValue('personsCreate', $this->generateUrl('sample_persons_create'));
        }

        $this->renderEntryPoint($entry);
    }

    /**
     * Legacy route for the admin that allow the import of categories.
     *
     * @throws Exception
     */
    public function importCategories(): void
    {
        $this->checkPermAdmin();

        $import = new SampleCategoryImport();
        $count  = $import->import();

        CAppUI::setMSg('CSampleCategory-msg-Imported', UI_MSG_OK, $count);

        foreach ($import->getErrors() as $msg) {
            CAppUI::setMsg($msg, UI_MSG_WARNING);
        }

        echo CAppUI::getMsg();

        CApp::rip();
    }

    /**
     * Legacy route for the admin that allow the import of nationalities.
     *
     * @throws Exception
     */
    public function importNationalities(): void
    {
        $this->checkPermAdmin();

        $import = new SampleNationalityImport();
        $count  = $import->import();

        CAppUI::setMSg('CSampleNationality-msg-Imported', UI_MSG_OK, $count);

        foreach ($import->getErrors() as $msg) {
            CAppUI::setMsg($msg, UI_MSG_WARNING);
        }

        echo CAppUI::getMsg();

        CApp::rip();
    }
}
