<?php
/**
 * @package Hospilibre\Rpps
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Rpps\Tests\Unit\Entity;

use Ox\Import\Rpps\CExternalMedecinBulkImport;
use Ox\Import\Rpps\Entity\CSavoirFaire;
use Hospilibre\Patients\CMedecin;
use Ox\Tests\UnitTestHospilibre;

/**
 * Description
 */
class CSavoirFaireTest extends UnitTestHospilibre
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create schema for tables to be available
        $import = new CExternalMedecinBulkImport();
        $import->createSchema();
    }

    public function testSynchronizeEmpty()
    {
        $savoir_faire = new CSavoirFaire();
        $this->assertEquals(new CMedecin(), $savoir_faire->synchronize());
    }
}
