<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Search\Tests\Unit;

use Hospilibre\Search\AdvancedSearchQueryBuilder;
use Ox\Tests\UnitTestHospilibre;
use PHPUnit\Framework\TestCase;

class AdvancedSearchQueryFilterTest extends UnitTestHospilibre
{
    public function testContainWords(): void
    {
        $builder = new AdvancedSearchQueryBuilder("contain words", AdvancedSearchQueryBuilder::CONTAIN_WORDS);
        $actual = $builder->getExpression();

        $this->assertEquals("(contain AND words)", $actual);
    }

    public function testExactMatch(): void
    {
        $builder = new AdvancedSearchQueryBuilder("contain words", AdvancedSearchQueryBuilder::EXACT_MATCH);
        $actual = $builder->getExpression();

        $this->assertEquals("(\"contain words\")", $actual);
    }

    public function testContainsAWord(): void
    {
        $builder = new AdvancedSearchQueryBuilder("contain words", AdvancedSearchQueryBuilder::CONTAINS_A_WORD);
        $actual = $builder->getExpression();

        $this->assertEquals("(contain OR words)", $actual);
    }

    public function testWithoutWords(): void
    {
        $builder = new AdvancedSearchQueryBuilder("a word", AdvancedSearchQueryBuilder::CONTAIN_WORDS);
        $builder->setWithoutWords('contain words');
        $actual = $builder->getExpression();

        $this->assertEquals("(a AND word) AND (NOT contain OR NOT words)", $actual);
    }
}
