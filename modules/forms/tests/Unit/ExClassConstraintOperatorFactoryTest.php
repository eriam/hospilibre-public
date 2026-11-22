<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Forms\Tests\Unit;

use Ox\Core\CMbException;
use Hospilibre\Forms\Constraints\ExClassConstraintAndOperator;
use Hospilibre\Forms\Constraints\ExClassConstraintOperatorFactory;
use Hospilibre\Forms\Constraints\ExClassConstraintOrOperator;
use Ox\Tests\UnitTestHospilibre;
use Throwable;

class ExClassConstraintOperatorFactoryTest extends UnitTestHospilibre
{
    public function getOperators(): array
    {
        return [
            'and'          => ['and', ExClassConstraintAndOperator::class],
            'or'           => ['or', ExClassConstraintOrOperator::class],
            'OR'           => ['OR', CMbException::class],
            'AND'          => ['AND', CMbException::class],
            'empty string' => ['', CMbException::class],
            'integer'      => [123, CMbException::class],
            'null'         => [null, Throwable::class],
        ];
    }

    /**
     * @dataProvider getOperators
     *
     * @param mixed  $operator
     * @param string $class_type
     *
     * @throws CMbException
     */
    public function testCreate($operator, string $class_type): void
    {
        if (is_a($class_type, Throwable::class, true)) {
            $this->expectException($class_type);
        }

        $operator = ExClassConstraintOperatorFactory::create($operator);
        $this->assertInstanceOf($class_type, $operator);
    }
}
