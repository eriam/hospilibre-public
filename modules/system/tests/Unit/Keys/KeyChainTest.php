<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\System\Tests\Unit\Keys;

use Hospilibre\System\Keys\CKeyMetadata;
use Hospilibre\System\Keys\Exceptions\CouldNotUseKey;
use Hospilibre\System\Keys\KeyChain;
use Ox\Tests\UnitTestHospilibre;

class KeyChainTest extends UnitTestHospilibre
{
    public function getMetadata(string $name): CKeyMetadata
    {
        $metadata                = new CKeyMetadata();
        $metadata->name          = $name;
        $metadata->alg           = 'aes';
        $metadata->mode          = 'ctr';
        $metadata->creation_date = null;
        $metadata->updateFormFields();

        return $metadata;
    }

    public function testGetWithUnknownMetadataFails(): void
    {
        $name     = uniqid('test');
        $keychain = new KeyChain();

        $this->expectExceptionObject(CouldNotUseKey::metadataNotFound($name));
        $keychain->get($name);
    }

    /**
     * @config [CConfiguration] [static] system KeyChain directory_path /tmp
     */
    public function testGetWithMetadataButNotOnStorageFails(): void
    {
        $name     = uniqid('test');
        $metadata = $this->getMetadata($name);

        $keychain = $this->getMockBuilder(KeyChain::class)
            ->onlyMethods(['getMetadata'])
            ->getMock();

        $keychain->expects($this->once())->method('getMetadata')->willReturn(
            $metadata
        );

        $this->expectExceptionObject(CouldNotUseKey::doesNotExist($metadata));
        $keychain->get($name);
    }
}
