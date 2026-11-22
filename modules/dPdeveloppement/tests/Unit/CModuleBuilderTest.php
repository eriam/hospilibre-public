<?php

/**
 * @package Hospilibre\Developpement
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Developpement\Tests\Unit;

use Ox\Core\CAppUI;
use Ox\Core\CMbPath;
use Hospilibre\Developpement\CModuleBuilder;
use Ox\Tests\UnitTestHospilibre;

/**
 * Description
 */
class CModuleBuilderTest extends UnitTestHospilibre
{
    private const FILES_CHECK = [
        '/classes/CConfigurationDummy.php',
        '/classes/CMyClass.php',
        '/classes/CSetupDummy.php',
        '/classes/CTabsDummy.php',
        '/locales/fr.php',
        '/templates/configure.tpl',
        '/composer.json',
        '/configure.php',
    ];

    public function testBuildModuleExists(): void
    {
        $builder = new CModuleBuilder(
            'dPdeveloppement',
            'Ox\\Hospilibre\\Developpement',
            'dev',
            'dev',
            'GNU GPL',
            'dev',
            'autre',
            'autre',
            'Hospilibre'
        );


        $this->expectExceptionMessage("Module 'dPdeveloppement' existe déjÎ°");
        $builder->build();
    }

    public function testBuildDummyModule(): void
    {
        $builder     = new CModuleBuilder(
            'dummy',
            'Ox\\Hospilibre\\Dummy',
            'dum',
            'dummy',
            'OXOL',
            'dum',
            'autre',
            'autre',
            'Hospilibre'
        );

        $builder->build();

        $dummy_dir = CAppUI::conf('root_dir') . '/modules/dummy';
        $this->assertDirectoryExists($dummy_dir);

        // Check if files have been created
        foreach (self::FILES_CHECK as $_file) {
            $this->assertFileExists($dummy_dir . $_file);
        }

        // Check if keywords have been replaced
        $this->assertNotFalse(strpos(file_get_contents($dummy_dir . '/classes/CMyClass.php'), 'Ox\\Hospilibre\\Dummy'));
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $dummy_dir = CAppUI::conf('root_dir') . '/modules/dummy';
        if (is_dir($dummy_dir)) {
            CMbPath::remove($dummy_dir);
        }
    }
}
