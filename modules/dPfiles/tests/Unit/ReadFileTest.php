<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Files\Tests\Unit;

use Hospilibre\Files\CFile;
use Hospilibre\Files\CReadFile;
use Hospilibre\Files\Tests\Fixtures\ReadFileFixtures;
use Hospilibre\PlanningOp\CSejour;
use Ox\Tests\UnitTestHospilibre;

class ReadFileTest extends UnitTestHospilibre
{
    public function testGetUnread(): void
    {
        $sejour     = $this->getObjectFromFixturesReference(CSejour::class, ReadFileFixtures::SEJOUR_TAG);
        $doc_unread = $this->getObjectFromFixturesReference(CFile::class, ReadFileFixtures::FILE2_TAG);

        $unread = CReadFile::getUnread([$sejour]);

        $this->assertArrayHasKey($sejour->_id, $unread);

        $first_doc_unread = reset($unread[$sejour->_id]);

        $this->assertEquals($doc_unread->_id, $first_doc_unread->_id);
    }
}
