<?php

/**
 * @package Hospilibre\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Core\Tests\Unit\Import;

use Ox\Core\Import\CExternalDataSourceImport;
use PHPUnit\Framework\TestCase;

/**
 * UN IMPORT DE DICTIONNAIRE INTERROMPU DOIT SE VOIR.
 *
 * `CSQLDataSource::queryDump()` rend **false** dès qu'une requête du dump échoue, et le nombre de
 * requêtes exécutées sinon. `importFile()` comparait ce résultat à **null** — une valeur que
 * `queryDump()` ne rend jamais — donc un dump interrompu à mi-parcours était compté comme réussi.
 *
 * C'est le défaut le plus coûteux de cette chaîne, parce qu'il ment dans le sens rassurant : la base
 * du dictionnaire existe, elle porte des tables, `ox-install:externals` affiche « importée » avec
 * leur nombre, et rien ne se voit avant qu'un acte CCAM ne sorte sans libellé — ou qu'un semis ne
 * trouve aucun acte à coter. Constaté sur une installation réelle : `ccamv2` arrivée avec 80 tables
 * sur 85, et la commande satisfaite.
 *
 * Aucune base de données n'est nécessaire : la source est remplacée par une sonde.
 */
class CExternalDataSourceImportFailureTest extends TestCase
{
    /**
     * LE TEST QUI MANQUAIT : c'est exactement le retour que produit un dump coupé.
     */
    public function testAnInterruptedDumpIsReportedAsAFailure(): void
    {
        $import = new CImportProbe(new CDumpStub(false));

        $this->assertFalse(
            $import->import('base.sql'),
            'Un dump dont une requête a échoué ne doit pas être compté comme importé'
        );
    }

    /**
     * L'échec doit laisser une trace lisible : c'est la seule que l'exploitant aura.
     */
    public function testAnInterruptedDumpLeavesAMessage(): void
    {
        $import = new CImportProbe(new CDumpStub(false));
        $import->import('base.sql');

        $messages = array_map(
            static function (array $message): string {
                return (string)$message[0];
            },
            $import->getMessages()
        );

        $this->assertNotEmpty($messages, 'Un échec muet est indistinguable d\'un succès');
        $this->assertStringContainsString('an error occured in query', implode("\n", $messages));
    }

    /**
     * Le contre-test : un dump complet rend le nombre de requêtes, et doit rester un succès.
     */
    public function testACompletedDumpRemainsASuccess(): void
    {
        $import = new CImportProbe(new CDumpStub(85));

        $this->assertTrue($import->import('base.sql'));
    }

    /**
     * Un dump vide a bien exécuté zéro requête sans erreur. C'est un cas limite qu'il ne faut pas
     * confondre avec l'échec : `0 == false` en PHP, et une comparaison relâchée les mélangerait.
     */
    public function testAnEmptyDumpIsNotAnError(): void
    {
        $import = new CImportProbe(new CDumpStub(0));

        $this->assertTrue($import->import('base.sql'));
    }
}

/**
 * Rend publique la seule étape en cause — l'import d'un fichier — et permet d'injecter la source.
 */
class CImportProbe extends CExternalDataSourceImport
{
    /**
     * @param object $ds Source de données réduite à ce que consomme importFile()
     */
    public function __construct(object $ds)
    {
        parent::__construct('test_import_failure', '.', []);

        $this->ds = $ds;
    }

    public function import(string $file): bool
    {
        return $this->importFile($file);
    }
}

/**
 * Source de données réduite à `queryDump()` et `error()`, les deux seules méthodes qu'`importFile()`
 * appelle. Le comportement vérifié ne dépend d'aucun serveur SQL.
 */
class CDumpStub
{
    /** @var int|false Ce que rend queryDump() : le nombre de requêtes, ou false sur échec */
    private $result;

    /**
     * @param int|false $result
     */
    public function __construct($result)
    {
        $this->result = $result;
    }

    /**
     * @return int|false
     */
    public function queryDump(string $path, bool $utf_decode = false)
    {
        return $this->result;
    }

    public function error(): string
    {
        return "Table 'ccamv2.acte' doesn't exist";
    }
}
