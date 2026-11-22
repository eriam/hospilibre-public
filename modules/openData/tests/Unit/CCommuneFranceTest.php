<?php

/**
 * @package Hospilibre\OpenData
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\OpenData\Tests\Unit;

use Ox\Core\CSQLDataSource;
use Hospilibre\OpenData\CCommuneFrance;
use Ox\Tests\UnitTestHospilibre;

/**
 * Description
 */
class CCommuneFranceTest extends UnitTestHospilibre
{
    public function setUp(): void
    {
        parent::setUp();

        if (!CSQLDataSource::get('INSEE', true)) {
            $this->markTestSkipped('Database INSEE is missing');
        }
    }

    public function testLoadByInseeFound(): void
    {
        $commune = new CCommuneFrance();
        $commune->loadByInsee('75056');

        $this->assertEquals('Paris', $commune->commune);
    }
    
    public function testLoadByInseeNotFound(): void
    {
        $commune = new CCommuneFrance();
        $commune->loadByInsee('00000');

        $this->assertNull($commune->_id);
    }
}
