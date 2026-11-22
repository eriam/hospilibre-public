<?php

/**
 * @package Hospilibre\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Core\Tests\Unit\Import;

use Ox\Core\CMbPath;
use Ox\Core\Import\CExternalDataSourceImport;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * LES DUMPS DES DICTIONNAIRES OFFICIELS SONT EN WINDOWS-1252, LA CONNEXION SQL EST EN UTF8MB4.
 *
 * La connexion est câblée dans CPDODataSource::connect() — « SET NAMES 'utf8mb4' », sans paramètre.
 * MySQL reçoit donc les octets hauts d'un dump Windows-1252 comme de l'UTF-8 invalide et les remplace
 * par « ? ». Le défaut est SILENCIEUX : l'import s'annonce réussi, et la CCAM arrive en base sans un
 * seul accent (« Capsulotomie corn?enne »). C'est la raison d'être de la conversion vérifiée ici.
 *
 * Ce que ces tests protègent tient en trois points, dont deux sont des pièges :
 *
 * 1. convertir ce qui doit l'être — un dump Windows-1252 devient de l'UTF-8 lisible ;
 * 2. NE PAS TOUCHER au reste — une même archive mélange les deux encodages (dans ccam.tar.gz,
 *    tables.sql est déjà en UTF-8), et le convertir quand même le doublerait en mojibake ;
 * 3. rester idempotent — une archive est extraite dans le MÊME répertoire que la précédente, donc
 *    la conversion repasse sur des fichiers déjà convertis et ne doit rien leur faire.
 *
 * Aucune base de données n'est nécessaire.
 */
class CExternalDataSourceImportTest extends TestCase
{
    private const SOURCE = 'test_transcodage';

    private const ARCHIVE = 'dictionnaire.zip';

    /** Windows-1252, et pas ISO-8859-1 : 0x9C (œ) et 0x96 (–) y sont des caractères de contrôle. */
    private const WINDOWS_1252 = "Capsulotomie corn\xE9enne\nun \x9Cuf, un tiret \x96 ici\n";

    private const UTF8 = "-- déjà en UTF-8, ne pas y toucher : œuf, tiret – ici\n";

    /** @var string Répertoire contenant l'archive de test */
    private $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = sys_get_temp_dir() . '/' . self::SOURCE . '-' . getmypid();
        CMbPath::forceDir($this->fixtures);

        $zip = new ZipArchive();
        $zip->open($this->fixtures . '/' . self::ARCHIVE, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('base.sql', self::WINDOWS_1252);
        $zip->addFromString('tables.sql', self::UTF8);
        $zip->close();
    }

    protected function tearDown(): void
    {
        CMbPath::remove($this->fixtures, false);
        CMbPath::remove($this->tmpDir(), false);

        parent::tearDown();
    }

    public function testAWindows1252DumpBecomesReadableUtf8(): void
    {
        $this->probe()->extract(self::ARCHIVE);

        $content = file_get_contents($this->tmpDir() . '/base.sql');

        $this->assertTrue(mb_check_encoding($content, 'UTF-8'), 'Le dump converti doit être de l\'UTF-8 valide');
        $this->assertStringContainsString('cornéenne', $content);
        $this->assertStringContainsString('œuf', $content, 'Le 0x9C de Windows-1252 est un œ, pas un contrôle');
        $this->assertStringContainsString('tiret – ici', $content, 'Le 0x96 est un tiret demi-cadratin');
    }

    /**
     * LE PIÈGE : convertir un fichier déjà en UTF-8 le double en mojibake, silencieusement.
     */
    public function testAnAlreadyUtf8FileIsLeftUntouched(): void
    {
        $this->probe()->extract(self::ARCHIVE);

        $this->assertSame(
            self::UTF8,
            file_get_contents($this->tmpDir() . '/tables.sql'),
            'Un fichier déjà en UTF-8 doit ressortir octet pour octet identique'
        );
    }

    /**
     * L'autre piège : les archives d'une même source sont extraites l'une après l'autre dans le même
     * répertoire, donc la conversion repasse sur ce qu'elle a déjà converti.
     */
    public function testTheConversionIsIdempotent(): void
    {
        $probe = $this->probe();
        $probe->extract(self::ARCHIVE);

        $once = [
            'base.sql'   => file_get_contents($this->tmpDir() . '/base.sql'),
            'tables.sql' => file_get_contents($this->tmpDir() . '/tables.sql'),
        ];

        $probe->extract(self::ARCHIVE);

        foreach ($once as $file => $content) {
            $this->assertSame($content, file_get_contents($this->tmpDir() . '/' . $file), "{$file} a changé au second passage");
        }
    }

    /**
     * L'import doit DIRE ce qu'il a converti : c'est la seule trace qu'un exploitant aura.
     */
    public function testOnlyTheConvertedFileIsReported(): void
    {
        $probe = $this->probe();
        $probe->extract(self::ARCHIVE);

        $reported = [];
        foreach ($probe->getMessages() as $message) {
            if (strpos((string)$message[0], 'transcoded') !== false) {
                $reported[] = $message[2];
            }
        }

        $this->assertSame(['base.sql'], $reported);
    }

    private function tmpDir(): string
    {
        return 'tmp/' . self::SOURCE;
    }

    private function probe(): CTranscodingProbe
    {
        return new CTranscodingProbe(self::SOURCE, $this->fixtures);
    }
}

/**
 * Rend publique la seule étape en cause — l'extraction, qui déclenche la conversion — sans exiger de
 * datasource : le comportement vérifié n'a rien à voir avec SQL.
 */
class CTranscodingProbe extends CExternalDataSourceImport
{
    /** @var string */
    private $directory;

    public function __construct(string $name, string $directory)
    {
        $this->directory = $directory;

        parent::__construct($name, '.', []);
    }

    public function extract(string $archive): bool
    {
        return $this->extractData($archive);
    }

    protected function getDirectory(): string
    {
        return $this->directory;
    }
}
