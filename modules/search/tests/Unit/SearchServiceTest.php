<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Search\Tests\Unit;

use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Search\CSearch;
use Hospilibre\Search\CSearchHistory;
use Hospilibre\Search\CSearchQueryFilter;
use Hospilibre\Search\SearchResults;
use Hospilibre\Search\SearchService;
use Ox\Tests\UnitTestHospilibre;

class SearchServiceTest extends UnitTestHospilibre
{
    public function testMakeHistory(): void
    {
        $search_builder = new CSearchQueryFilter();
        $search_builder->setWords('words')
            ->setStart(0)
            ->setAggregation(false)
            ->setFuzzySearch(true);
        $search_builder->setPatientId(2);
        $search_builder->setNamesTypes(['CConsultation', 'CCompteRendu']);

        $mediuser      = new CMediusers();
        $mediuser->_id = 1;

        $results = new SearchResults(200, 20);

        $expected             = new CSearchHistory();
        $expected->user_id    = 1;
        $expected->entry      = 'words';
        $expected->agregation = false;
        $expected->date       = 'now';
        $expected->fuzzy      = true;
        $expected->types      = 'CConsultation|CCompteRendu';
        $expected->hits       = 20;

        $search_service = new SearchService($search_builder, new CSearch());

        $this->assertEquals($expected, $search_service->makeHistory($mediuser, $results));
    }
}
