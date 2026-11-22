<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\InstallExternals;
use Ox\Core\CAppUI;
use Ox\Tests\UnitTestHospilibre;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Throwable;

/**
 * L'INSTALLATION AUTOMATIQUE DES DICTIONNAIRES, ET CE QUI L'EMPÊCHAIT.
 *
 * `ox-install:externals` existait depuis toujours et n'était appelée nulle part — ni par l'entrypoint
 * de production, ni par l'installation de la CI, ni par le Dockerfile. La raison tient en une ligne de
 * son code : la création des bases lisait `CAppUI::conf("db <source> dbname")` pour les quinze
 * importeurs d'un coup, et la configuration livrée n'en déclare que sept sur treize. Une source non
 * déclarée rendait une chaîne vide, donc un `CREATE DATABASE` invalide, donc une exception AVANT tout
 * import. La commande était inutilisable telle quelle, et personne ne pouvait donc la câbler.
 *
 * Ces tests tiennent les trois décisions qui rendent le câblage possible, et qui sont toutes les
 * endroits où l'on peut se tromper en silence :
 *
 * 1. une source non déclarée est ÉCARTÉE, pas fatale ;
 * 2. une déclaration manquante se déduit de `db std` — même serveur, nom de base normalisé — et n'est
 *    JAMAIS écrite par-dessus une déclaration existante, sans quoi la commande écraserait le choix
 *    d'un exploitant qui héberge ses dictionnaires ailleurs ;
 * 3. un `--only` fautif est refusé en nommant les sources disponibles, plutôt que de ne rien faire ;
 * 4. un import en échec sort en ÉCHEC — le rapport à l'écran est pour l'humain, le code de retour est
 *    la seule chose qu'une enveloppe automatisée lit.
 *
 * L'écriture dans `includes/config.php` n'est pas testée ici : c'est de l'ajout en fin de fichier, et
 * la partie qui DÉCIDE quoi écrire a été extraite pour être vérifiable sans toucher au disque.
 */
class InstallExternalsTest extends UnitTestHospilibre
{
    /** Sources qu'un établissement français utilise à l'exécution. */
    private const ESSENTIAL = ['ccamV2', 'cim10'];

    /** @var array|null Configuration des datasources, telle qu'elle était avant le test */
    private $datasources;

    /**
     * LA CONFIGURATION EST GLOBALE ET LE PROCESSUS PHPUnit EST PARTAGÉ : ces tests retirent et
     * falsifient des déclarations de datasources, et les laisser en l'état ferait tenter à une suite
     * suivante — celle qui vérifie les codes CCAM contre le dictionnaire, par exemple — une connexion
     * vers un serveur inventé. On restaure donc l'état exact.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->datasources = $GLOBALS['dPconfig']['db'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->datasources === null) {
            unset($GLOBALS['dPconfig']['db']);
        } else {
            $GLOBALS['dPconfig']['db'] = $this->datasources;
        }

        parent::tearDown();
    }

    /**
     * Une source non déclarée ne doit pas faire tomber la commande : elle sort du périmètre.
     *
     * @throws Throwable
     */
    public function testAnUndeclaredSourceIsSkippedRatherThanFatal(): void
    {
        $command = $this->commandFor(['ccamV2']);

        $this->forgetDeclaration('ccamV2');

        $declared = $this->invoke($command, 'declaredSources');

        $this->assertSame([], $declared, 'Une source sans datasource ne doit pas être traitée');

        $report = $this->property($command, 'report');

        $this->assertArrayHasKey('ccamV2', $report, 'Elle doit tout de même apparaître au rapport');
        $this->assertSame('non déclarée', $report['ccamV2']['state']);
    }

    /**
     * La déclaration déduite reprend la connexion standard, et le nom de base normalisé.
     *
     * @throws Throwable
     */
    public function testAMissingDeclarationIsDerivedFromTheStandardDatasource(): void
    {
        $command = $this->commandFor(['ccamV2']);

        $this->forgetDeclaration('ccamV2');

        $declarations = $this->invoke($command, 'missingDeclarations');

        $this->assertArrayHasKey('ccamV2', $declarations);

        $expected = [
            'dbtype' => 'mysql',
            'dbhost' => (string)CAppUI::conf('db std dbhost'),
            // getSourceNameForSQL() : minuscules, tirets en soulignés. « ccamV2 » devient « ccamv2 ».
            'dbname' => 'ccamv2',
            'dbuser' => (string)CAppUI::conf('db std dbuser'),
            'dbpass' => (string)CAppUI::conf('db std dbpass'),
        ];

        $this->assertSame($expected, $declarations['ccamV2']);
    }

    /**
     * LE PIÈGE : une déclaration existante appartient à l'exploitant. Un hôpital peut héberger ses
     * dictionnaires sur un autre serveur, et la commande ne doit pas la remplacer par la sienne.
     *
     * @throws Throwable
     */
    public function testAnExistingDeclarationIsNeverOverwritten(): void
    {
        $command = $this->commandFor(['ccamV2']);

        $GLOBALS['dPconfig']['db']['ccamV2'] = [
            'dbtype' => 'mysql',
            'dbhost' => 'dictionnaires.interne',
            'dbname' => 'ccam_partage',
            'dbuser' => 'lecteur',
            'dbpass' => 'secret',
        ];

        $this->assertSame(
            [],
            $this->invoke($command, 'missingDeclarations'),
            "Rien à déclarer : la source l'est déjà"
        );

        $this->assertSame(
            'ccam_partage',
            $this->invoke($command, 'databaseFor', 'ccamV2'),
            'Le nom de base configuré prime sur le nom déduit'
        );
    }

    /**
     * Un --only fautif doit nommer ce qui existe, plutôt que de traiter zéro source en silence.
     *
     * @throws Throwable
     */
    public function testAnUnknownSourceIsRefusedWithTheAvailableOnes(): void
    {
        try {
            // « ccam » est l'ancienne clé, encore présente dans la configuration livrée, mais aucun
            // importeur ne la porte : c'est la faute de frappe la plus probable.
            $this->commandFor(['ccam']);
            $this->fail('Une source inconnue doit être refusée');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ccam', $e->getMessage());

            foreach (self::ESSENTIAL as $source) {
                $this->assertStringContainsString(
                    $source,
                    $e->getMessage(),
                    'Le message doit lister les sources disponibles'
                );
            }
        }
    }

    /**
     * Les trois importeurs de la CIM-10 peuplent la MÊME base : le cycle de vie se raisonne par
     * source, pas par importeur, sinon la base serait créée puis recréée trois fois.
     *
     * @throws Throwable
     */
    public function testTheThreeCim10ImportersShareASingleSource(): void
    {
        $command = $this->commandFor(['cim10']);

        $this->assertSame(['cim10'], $this->invoke($command, 'sourceNames'));
        $this->assertGreaterThanOrEqual(
            3,
            count($this->invoke($command, 'importsOf', 'cim10')),
            'OMS, ATIH et la modification allemande partagent la source cim10'
        );
    }

    /**
     * UN IMPORT EN ÉCHEC DOIT SORTIR EN ÉCHEC.
     *
     * La commande ne rendait que SUCCESS, quel que soit le rapport : elle affichait « Import en
     * échec : ccamV2 » puis sortait en 0. Tout ce qui l'enveloppe en tirait la conclusion inverse de
     * ce qu'elle affichait — l'entrypoint de production la suit d'un « || echo AVERTISSEMENT » qui ne
     * pouvait donc jamais se déclencher, et le provisionneur d'un range voyait un 0 franc sur une
     * instance sans un seul acte CCAM. Le rapport à l'écran ne sert qu'à l'humain qui le lit ; le code
     * de retour est la seule chose que lit une machine.
     *
     * @throws Throwable
     */
    public function testAFailedImportIsNamedAmongTheFailures(): void
    {
        $command = $this->commandFor(['ccamV2']);

        $this->set($command, 'report', [
            'ccamV2' => ['database' => 'ccamv2', 'state' => 'ÉCHEC', 'detail' => 'CCCAMImport a échoué'],
            'cim10'  => ['database' => 'cim10', 'state' => 'importée', 'detail' => '12 tables'],
        ]);

        $this->assertSame(['ccamV2'], $this->invoke($command, 'failedSources'));
    }

    /**
     * @throws Throwable
     */
    public function testAFailedImportMakesTheCommandExitInFailure(): void
    {
        $command = $this->commandFor(['ccamV2']);

        $this->set($command, 'report', [
            'ccamV2' => ['database' => 'ccamv2', 'state' => 'ÉCHEC', 'detail' => 'CCCAMImport a échoué'],
        ]);

        $this->assertSame(
            InstallExternals::FAILURE,
            $this->invoke($command, 'exitCode'),
            'Un dictionnaire manquant doit se voir au code de retour'
        );
    }

    /**
     * Le contre-test : une source ÉCARTÉE faute de déclaration n'est pas un échec d'import. Sans quoi
     * la commande sortirait en échec sur toute configuration qui ne déclare pas les treize sources —
     * c'est-à-dire sur celle que le fork livre.
     *
     * @throws Throwable
     */
    public function testAnUndeclaredSourceIsNotAFailure(): void
    {
        $command = $this->commandFor(['ccamV2']);

        $this->set($command, 'report', [
            'ccamV2' => ['database' => '—', 'state' => 'non déclarée', 'detail' => 'voir --declare'],
            'cim10'  => ['database' => 'cim10', 'state' => 'peuplée', 'detail' => '12 tables'],
        ]);

        $this->assertSame([], $this->invoke($command, 'failedSources'));
        $this->assertSame(InstallExternals::SUCCESS, $this->invoke($command, 'exitCode'));
    }

    /**
     * UN IMPORT EN ÉCHEC LAISSE UNE BASE PEUPLÉE, ET LE PASSAGE SUIVANT LA SAUTE.
     *
     * C'est le défaut qui a caché les deux autres pendant une heure. L'import de la CIM-10 meurt
     * APRÈS avoir peuplé ses 21 tables ; `importAllData()` saute ensuite toute base non vide
     * (« peuplée, conservée »), donc la seconde exécution ne réimporte rien et sort en 0. Un import
     * PARTIEL devient indistinguable d'un import COMPLET, et c'est ce qui nous a fait conclure à tort
     * que la source passait quand elle était seule.
     *
     * On ne peut pas deviner si une base non vide est complète — rien ne l'enregistre. Mais on peut
     * refuser de laisser l'exploitant le deviner : quand un import échoue, il faut DIRE que la base
     * restera en l'état et que le prochain passage la sautera.
     *
     * @throws Throwable
     */
    public function testAFailedImportWarnsThatTheDatabaseIsLeftPartiallyPopulated(): void
    {
        $command = $this->commandFor(['ccamV2']);

        $this->set($command, 'report', [
            'cim10' => ['database' => 'cim10', 'state' => 'ÉCHEC', 'detail' => 'CCIM10OmsImport a échoué'],
        ]);

        $warning = $this->invoke($command, 'partialImportWarning');

        $this->assertNotNull($warning, 'Un échec doit avertir que la base reste à moitié peuplée');
        $this->assertStringContainsString('cim10', $warning, 'La source concernée doit être nommée');
        $this->assertStringContainsString('--force', $warning, 'Le remède doit être donné');
    }

    /**
     * Le contre-test : pas d'échec, pas d'avertissement. Un avertissement systématique deviendrait un
     * bruit qu'on apprend à ignorer, et c'est ainsi qu'on rate le vrai.
     *
     * @throws Throwable
     */
    public function testASuccessfulImportWarnsAboutNothing(): void
    {
        $command = $this->commandFor(['ccamV2']);

        $this->set($command, 'report', [
            'ccamV2' => ['database' => 'ccamv2', 'state' => 'importée', 'detail' => '85 tables'],
        ]);

        $this->assertNull($this->invoke($command, 'partialImportWarning'));
    }

    /**
     * Instancie la commande avec un périmètre donné, importeurs chargés.
     *
     * @param string[] $only
     *
     * @throws ReflectionException|Throwable
     */
    private function commandFor(array $only): InstallExternals
    {
        $command = new InstallExternals();

        $this->set($command, 'only', $only);
        $this->set($command, 'report', []);
        $this->set($command, 'imports', []);
        $this->set($command, 'params', [
            'host'     => CAppUI::conf('db std dbhost'),
            'database' => CAppUI::conf('db std dbname'),
            'user'     => CAppUI::conf('db std dbuser'),
            'password' => CAppUI::conf('db std dbpass'),
        ]);

        $this->invoke($command, 'loadImports');

        return $command;
    }

    /** Retire une déclaration de la configuration en mémoire, le temps d'un test. */
    private function forgetDeclaration(string $source): void
    {
        unset($GLOBALS['dPconfig']['db'][$source]);
    }

    /**
     * @return mixed
     * @throws ReflectionException
     */
    private function invoke(InstallExternals $command, string $method, ...$arguments)
    {
        $reflection = new ReflectionClass($command);
        $target     = $reflection->getMethod($method);
        $target->setAccessible(true);

        return $target->invokeArgs($command, $arguments);
    }

    /**
     * @return mixed
     * @throws ReflectionException
     */
    private function property(InstallExternals $command, string $name)
    {
        $reflection = new ReflectionClass($command);
        $target     = $reflection->getProperty($name);
        $target->setAccessible(true);

        return $target->getValue($command);
    }

    /**
     * @param mixed $value
     *
     * @throws ReflectionException
     */
    private function set(InstallExternals $command, string $name, $value): void
    {
        $reflection = new ReflectionClass($command);
        $target     = $reflection->getProperty($name);
        $target->setAccessible(true);
        $target->setValue($command, $value);
    }
}
