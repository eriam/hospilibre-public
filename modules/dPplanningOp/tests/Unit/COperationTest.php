<?php

/**
 * @package Hospilibre\Patient\Tests
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\PlanningOp\Tests\Unit;

use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\COperation;
use Ox\Tests\UnitTestHospilibre;

class COperationTest extends UnitTestHospilibre
{
    /**
     * @param COperation  $operation
     * @param string|null $expected
     *
     * @dataProvider getLibellesActesPrevusProvider
     */
    public function testGetLibellesActesPrevus(COperation $operation, ?string $expected = null): void
    {
        $actual = $operation->getLibellesActesPrevus();

        $this->assertEquals($expected, $actual);
    }

    public function getLibellesActesPrevusProvider(): array
    {
        $chir             = $this->getRandomObjects(CMediusers::class);
        $op_null          = new COperation();
        $op_null->libelle = null;
        $op_null->chir_id = $chir->_id;

        // Operation avec un libelle vide
        $op_empty_string          = new COperation();
        $op_empty_string->libelle = "";
        $op_empty_string->chir_id = $chir->_id;

        // Operation avec un libelle sans code ccam
        $op_without_code          = new COperation();
        $op_without_code->libelle = "Lorem Ipsum";
        $op_without_code->chir_id = $chir->_id;

        // Operation avec un libelle avec 1 code ccam
        $op_with_one_code             = new COperation();
        $op_with_one_code->libelle    = "Lorem Ipsum";
        $op_with_one_code->chir_id    = $chir->_id;
        $op_with_one_code->codes_ccam = "AAFA001";

        // Operation avec un libelle avec 2 code ccam
        $op_with_two_codes             = new COperation();
        $op_with_two_codes->libelle    = "Lorem Ipsum";
        $op_with_two_codes->chir_id    = $chir->_id;
        $op_with_two_codes->codes_ccam = "AAFA001|AAFA002";

        return [
            "label null"                => [$op_null, null],
            "empty string label"        => [$op_empty_string, ""],
            "operation label only"      => [$op_without_code, "Lorem Ipsum"],
            "operation label + 1 code"  => [$op_with_one_code, "Lorem Ipsum - AAFA001"],
            "operation label + 2 codes" => [$op_with_two_codes, "Lorem Ipsum - AAFA001 - AAFA002"],
        ];
    }
}
