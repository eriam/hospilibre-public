<?php
/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit\Version;

use Ox\Core\Exceptions\VersionException;
use Ox\Core\Version\Builder;
use Ox\Tests\UnitTestHospilibre;

/**
 * Tests for the build of the release and version files
 */
class BuilderTest extends UnitTestHospilibre
{
    /**
     * @throws VersionException
     */
    public function testBuildVersion(): void
    {
        $this->assertStringStartsWith('Generated version file in', Builder::buildVersion());
    }
}
