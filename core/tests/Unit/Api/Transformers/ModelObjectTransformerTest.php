<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Tests\Unit\Api\Transformers;

use Ox\Core\Api\Resources\Item;
use Ox\Core\Api\Transformers\ModelObjectTransformer;
use Ox\Core\CMbFieldSpec;
use Ox\Tests\UnitTestHospilibre;

/**
 * Test the conversion of types.
 */
class ModelObjectTransformerTest extends UnitTestHospilibre
{
    /**
     * @dataProvider convertTypeProvider
     */
    public function testConvertType($value, string $spec, $expected_value): void
    {
        $transformer = new ModelObjectTransformer(new Item([]));
        // Use assertTrue to allow type comparison
        $this->assertTrue(
            $expected_value === $this->invokePrivateMethod($transformer, 'convertType', $value, $spec)
        );
    }

    public function convertTypeProvider(): array
    {
        return [
            'convert_type_string'      => ['toto', CMbFieldSpec::PHP_TYPE_STRING, 'toto'],
            'convert_type_int'         => ['1234titi', CMbFieldSpec::PHP_TYPE_INT, 1234],
            'convert_type_float'       => ['1234.155', CMbFieldSpec::PHP_TYPE_FLOAT, 1234.155],
            'convert_type_bool_true'   => [1, CMbFieldSpec::PHP_TYPE_BOOL, true],
            'convert_type_bool_false'  => [0, CMbFieldSpec::PHP_TYPE_BOOL, false],
            'convert_type_bool_string' => ['false', CMbFieldSpec::PHP_TYPE_BOOL, true],
            'convert_null_value'       => [null, CMbFieldSpec::PHP_TYPE_BOOL, null],
        ];
    }
}
