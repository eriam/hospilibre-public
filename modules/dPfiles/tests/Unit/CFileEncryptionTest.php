<?php

/**
 * @package Hospilibre\Files
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Files\Tests\Unit;

use Ox\Core\CAppUI;
use Hospilibre\Files\CFile;
use Hospilibre\Patients\CSourceIdentite;
use Hospilibre\Patients\Tests\Fixtures\SimplePatientFixtures;
use Hospilibre\System\CObjectEncryption;
use Hospilibre\System\Keys\CKeyMetadata;
use Hospilibre\System\Keys\Exceptions\CouldNotUseKey;
use Hospilibre\System\Keys\KeyBuilder;
use Hospilibre\System\Keys\KeyChain;
use Ox\Tests\UnitTestHospilibre;

/**
 * Test the encryption and decryption of files
 */
class CFileEncryptionTest extends UnitTestHospilibre
{

    /**
     * @config [CConfiguration] [static] system KeyChain directory_path /tmp
     */
    public function testEncrypt(): array
    {
        // If key is already on the filesystem don't genere it
        try {
            KeyChain::load(CSourceIdentite::ENCRYPT_KEY_NAME);
        } catch (CouldNotUseKey $e) {
            $key_metadata = CKeyMetadata::loadFromName(CSourceIdentite::ENCRYPT_KEY_NAME);

            $builder = new KeyBuilder();
            $builder->generateKey($key_metadata);
        }

        /** @var CSourceIdentite $source */
        $source = $this->getObjectFromFixturesReference(CSourceIdentite::class, SimplePatientFixtures::SAMPLE_PATIENT);

        $file               = new CFile();
        $file->file_name    = uniqid();
        $file->object_class = $source->_class;
        $file->object_id    = $source->_id;
        $file->file_type    = 'text/plain';
        $file->setContent('Test encrypt');

        $file->fillFields();
        $file->updateFormFields();

        $this->assertNull($file->store());

        /** @var CObjectEncryption $iv */
        $iv = $file->loadUniqueBackRef('encryption');
        $this->assertEquals($source->getKeyName(), $iv->getKeyName());

        return [$file, file_get_contents($file->_file_path), 'Test encrypt'];
    }

    /**
     * @depends testEncrypt
     *
     * @config [CConfiguration] [static] system KeyChain directory_path /tmp
     */
    public function testDecrypt(array $data): void
    {
        [$file, $crypted_text, $expected_text] = $data;

        $this->assertEquals($expected_text, $file->decrypt($crypted_text));
    }

    public function testDecryptEmpty(): void
    {
        $this->assertNull((new CFile())->decrypt(null));
    }

    public function testDecryptNoIv(): void
    {
        $string = uniqid();
        $this->assertEquals($string, (new CFile())->decrypt($string));
    }
}
