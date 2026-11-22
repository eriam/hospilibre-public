<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\PlanningOp\Tests\Unit;

use Hospilibre\PlanningOp\CProtocole;
use Ox\Tests\UnitTestHospilibre;

class CProtocoleCodageTest extends UnitTestHospilibre
{
    /**
     * @dataProvider formatCodageNGAPProvider
     */
    public function testFormatCodageNGAP(string $codageNGAP, string $expected): void
    {
        $protocole                     = new CProtocole();
        $protocole->codage_ngap_sejour = $codageNGAP;
        $protocole->formatCodageNGAP();

        $this->assertEquals($expected, $protocole->_codage_ngap_formatted);
    }

    public function formatCodageNGAPProvider(): array
    {
        return [
            'one'   => [
                '1-TSA-1-60--0--0',
                'TSA',
            ],
            'three' => [
                '1-TSA-1-60--0--0|1-AMP-2-0.63--0--0|1-SGN-1-0--0--0',
                'TSA AMP2 SGN',
            ],
            'empty'   => [
                '',
                '',
            ],
        ];
    }
}
