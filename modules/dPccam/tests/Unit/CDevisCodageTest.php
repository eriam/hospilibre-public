<?php

/**
 * @package Hospilibre\Ccam\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Ccam\Tests\Unit;

use Exception;
use Hospilibre\Ccam\CDevisCodage;
use Hospilibre\Files\CFile;
use Hospilibre\Files\CFilesCategory;
use Ox\Tests\UnitTestHospilibre;

class CDevisCodageTest extends UnitTestHospilibre
{

    /**
     * Create category file object
     *
     * @throws Exception
     */
    protected static function createCategoryFile(): CFilesCategory
    {
        $file_category        = new CFilesCategory();
        $file_category->class = "CDevisCodage";
        $file_category->loadMatchingObjectEsc();

        if (!$file_category->_id) {
            $file_category->nom = "devis codage";

            if ($msg = $file_category->store()) {
                self::fail($msg);
            }
        }

        return $file_category;
    }

    /**
     * Test to generate the CFile object from CDevisCodage object
     *
     * @throws Exception
     */
    public function testGenerateFileFromDevisCodage(): void
    {
        self::createCategoryFile();
        $devis = $this->getRandomObjects(CDevisCodage::class);

        $devis->_generate_pdf = 1;

        if ($msg = $devis->store()) {
            self::fail($msg);
        }

        $devis_file               = new CFile();
        $devis_file->object_class = $devis->_class;
        $devis_file->object_id    = $devis->_id;
        $devis_file->loadMatchingObject();

        //$this->assertEquals($devis_file->_id);
    }
}
