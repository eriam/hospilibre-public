<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Unit\Keys;

use Ox\Core\Security\Crypt\Alg;
use Ox\Core\Security\Crypt\Mode;
use Hospilibre\System\Keys\Exceptions\CouldNotPersistKey;
use Hospilibre\System\Keys\KeyBuilder;
use Ox\Tests\UnitTestHospilibre;

/**
 * Todo: Integration tests (FlySystem?) with FS failures.
 */
class KeyBuilderTest extends UnitTestHospilibre
{
    public function getInvalidNameProvider(): array
    {
        return [
            'less than required length' => ['aze'],
            'empty' => [''],
            'invalid char: @' => ['abcd@'],
            'invalid char: [' => ['abcd['],
            'invalid char: ]' => ['abcd]'],
            'invalid char: /' => ['abcd/'],
            'invalid char: \\' => ['abcd\\'],
            'invalid char: \"' => ['abcd\"'],
            'invalid char: \'' => ['abcd\''],
            'invalid char: .' => ['abcd.'],
            'invalid char: :' => ['abcd:'],
//            'invalid char: whitespace' => ['abcd '], FW is removing whitespace...
        ];
    }

    /**
     * @dataProvider getInvalidNameProvider
     *
     * @param string $name
     *
     * @throws CouldNotPersistKey
     */
    public function testPersistMetadataWithInvalidNameFails(string $name): void
    {
        $builder = new KeyBuilder();

        $this->expectExceptionObject(CouldNotPersistKey::unableToStoreMetadata());
        $builder->persistMetadata($name, Alg::AES(), Mode::CTR());
    }
}
