<?php

/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit;

use Ox\Components\Cache\LayeredCache;
use Ox\Core\Cache;
use Ox\Core\CacheManager;
use Ox\Tests\UnitTestHospilibre;
use Psr\SimpleCache\CacheInterface;

/**
 * Assert the DSHM is not used in clearMandatoryCache
 */
class CacheManagerTest extends UnitTestHospilibre
{

    /**
     * @runInSeparateProcess
     */
    public function testClearMandatoryCacheDoesNotHaveRedisKeys(): void
    {
        $mock = $this->getMockBuilder(CacheInterface::class)
            ->getMock();
        $mock->expects($this->never())->method('get');
        $mock->expects($this->never())->method('set');
        $mock->expects($this->never())->method('delete');
        $mock->expects($this->never())->method('getMultiple');
        $mock->expects($this->never())->method('setMultiple');
        $mock->expects($this->never())->method('deleteMultiple');
        $mock->expects($this->never())->method('clear');
        $mock->expects($this->never())->method('has');

        Cache::getCache(Cache::DISTR)->setAdapter(LayeredCache::DISTR, $mock, ['namespaced' => false]);

        $this->doesNotPerformAssertions();

        CacheManager::clearMandatoryCache();
    }
}
