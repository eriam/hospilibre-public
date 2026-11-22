<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Exception;
use Ox\Cli\CommandLinePDO;
use Ox\Cli\HospilibreCommand;
use Ox\Core\Cache;
use Ox\Core\CAppUI;
use Ox\Core\CClassMap;
use Ox\Core\CSetup;
use Ox\Core\CSQLDataSource;
use Ox\Core\Import\CExternalDataSourceImport;
use PDO;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;
use Throwable;

/**
 * Installe les dictionnaires externes : CCAM, CIM-10, NGAP, communes INSEE, SAE…
 *
 * LES ARCHIVES SONT DANS LE DÉPÔT, pas à télécharger : chaque importeur les cherche dans son propre
 * `DATA_DIR`, chemin relatif au RÉPERTOIRE DE SA CLASSE — `modules/dPccam/base/ccam.tar.gz`,
 * `modules/dPcim10/base/`, `modules/dPpatients/INSEE/`. Le seul travail est de créer les bases et d'y
 * injecter ces archives.
 *
 * CE QUI A ÉTÉ CORRIGÉ ICI, et pourquoi la commande n'était appelée nulle part :
 *
 * 1. ELLE ÉCHOUAIT EN BLOC. Les quinze importeurs étaient traités ensemble et la création des bases
 *    passait par `CAppUI::conf("db <source> dbname")` ; une seule source non déclarée rendait une
 *    chaîne vide, donc un `CREATE DATABASE ``` `` invalide, donc une exception AVANT tout import. Or
 *    la configuration livrée n'en déclare que sept sur treize. Une source non déclarée est désormais
 *    IGNORÉE et signalée, et `--declare` sait la déclarer.
 * 2. ELLE N'ÉTAIT PAS REJOUABLE. `importAllData()` n'importait que les bases créées AU MÊME PASSAGE
 *    (`in_array($name, $this->created)`) : après un échec partiel, relancer ne faisait plus rien, et
 *    réimporter exigeait `--delete`, qui détruit les quinze bases. Une base créée mais VIDE est
 *    maintenant importée, et `--force` réimporte par-dessus une base peuplée.
 * 3. ELLE ÉTAIT TOUT OU RIEN. `--only` restreint aux sources voulues : une instance n'a pas besoin de
 *    la CSARR pour coder en CCAM.
 *
 * Le rapport final dit, source par source, ce qui a été fait — sans quoi l'absence d'un dictionnaire
 * reste invisible jusqu'à ce qu'une page affiche un code sans libellé.
 */
class InstallExternals extends HospilibreCommand
{
    /**
     * État d'une source dont l'import a échoué. Écrit par importAllData(), lu par failedSources() :
     * une chaîne littérale aux deux endroits finirait par diverger, et le code de retour mentirait.
     */
    private const STATE_FAILED = 'ÉCHEC';

    /** Dictionnaire vérifié présent par les contrôles que son module déclare. */
    private const STATE_VERIFIED = 'peuplée';

    /** Import refusé : la base cible est celle de l'application (voir importAllData()). */
    private const STATE_REFUSED = 'REFUSÉ';

    /** Aucun contrôle déclaré ne passe : le dictionnaire est absent, il faut l'importer. */
    private const STATE_EMPTY = 'vide';

    /** Une partie des contrôles passe : import interrompu en chemin, à reprendre. */
    private const STATE_PARTIAL = 'incomplète';

    /**
     * Aucun contrôle n'est déclaré pour cette source : on ne peut rien affirmer.
     *
     * N'est PAS « peuplée ». La nuance est tout l'objet de ce correctif : un état qu'on ne sait pas
     * mesurer ne doit pas emprunter le nom d'un état vérifié.
     */
    private const STATE_UNKNOWN = 'indéterminée';

    /**
     * Contrôles de présence déclarés par les setups, par DSN. Mémoïsé : construire la table exige
     * d'instancier tous les setups du dépôt.
     *
     * @var array<string, string[]>|null
     */
    private ?array $dictionary_probes = null;

    /** Setups qui n'ont pas pu être instanciés pendant la construction de la table ci-dessus. */
    private array $unbuildable_setups = [];

    /** @var OutputInterface */
    protected $output;

    /** @var InputInterface */
    protected $input;

    /** @var SymfonyStyle */
    protected $io;

    /** @var Stopwatch */
    private $stopwatch;

    /**
     * @var string Racine de la copie de travail.
     *
     * Déclarée ici : HospilibreCommand ne la porte pas, et l'ancienne version créait donc une
     * propriété dynamique — dépréciée depuis PHP 8.2, alors que le projet vise 7.4 et 8.1.
     */
    protected $path;

    /** @var array */
    protected $params = [];

    /** @var bool */
    protected $import;

    /** @var bool */
    protected $delete;

    /** @var bool */
    protected $declare;

    /** @var bool */
    protected $force;

    /** @var string[]|null Sources demandées par --only, null si toutes */
    protected $only;

    /** @var CommandLinePDO */
    protected $pdo;

    /** @var CExternalDataSourceImport[] */
    protected $imports = [];

    /** @var array<string, array{database: string, state: string, detail: string}> */
    protected $report = [];

    /** @var array */
    protected $durations = [];

    /**
     * @see parent::configure()
     */
    protected function configure(): void
    {
        $this
            ->setName('ox-install:externals')
            ->setDescription('Installe les dictionnaires externes (CCAM, CIM-10, INSEE…) et leurs données')
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Racine de la copie de travail',
                dirname(__DIR__, 3) . '/'
            )->addOption(
                'import',
                'i',
                InputOption::VALUE_NONE,
                'Injecte les données, et pas seulement les bases',
            )->addOption(
                'delete',
                'd',
                InputOption::VALUE_NONE,
                'Supprime les bases existantes avant de les recréer',
            )->addOption(
                'only',
                'o',
                InputOption::VALUE_REQUIRED,
                "Sources à traiter, séparées par des virgules (ex. ccamV2,cim10). Toutes par défaut",
            )->addOption(
                'declare',
                null,
                InputOption::VALUE_NONE,
                "Déclare dans includes/config.php les datasources manquantes, d'après « db std »",
            )->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Réimporte même dans une base qui contient déjà des tables',
            );
    }

    /**
     * @see parent::showHeader()
     */
    protected function showHeader(): void
    {
        $this->io->title('Dictionnaires externes');
    }

    /**
     * @throws Exception|InvalidArgumentException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output    = $output;
        $this->input     = $input;
        $this->io        = new SymfonyStyle($this->input, $this->output);
        $this->stopwatch = new Stopwatch(true);
        $this->path      = $input->getOption('path');
        $this->import    = (bool)$input->getOption('import');
        $this->delete    = (bool)$input->getOption('delete');
        $this->declare   = (bool)$input->getOption('declare');
        $this->force     = (bool)$input->getOption('force');

        $only       = $input->getOption('only');
        $this->only = $only === null ? null : array_filter(array_map('trim', explode(',', $only)));

        $this->showHeader();

        if (!is_dir($this->path)) {
            throw new InvalidArgumentException("'$this->path' n'est pas un répertoire valide.");
        }

        // includes configs (legacy)
        require $this->path . '/includes/config_all.php';

        Cache::init($this->path);
        CAppUI::init();

        $this->params['host']     = CAppUI::conf('db std dbhost');
        $this->params['database'] = CAppUI::conf('db std dbname');
        $this->params['user']     = CAppUI::conf('db std dbuser');
        $this->params['password'] = CAppUI::conf('db std dbpass');

        $this->loadImports();
        $this->checkConnexion();

        if ($this->declare) {
            $this->declareDatasources();
        }

        if ($this->delete) {
            $this->dropDatabases();
        }

        $this->prepareDatabases();

        if ($this->import) {
            $this->importAllData();

            if ($this->io->isVerbose()) {
                $this->displayImportDurations();
            }
        }

        $this->displayReport();

        return $this->exitCode();
    }

    /**
     * Sources dont l'import a échoué.
     *
     * Une source « non déclarée » n'en fait PAS partie : elle est hors périmètre, et la configuration
     * livrée n'en déclare que sept sur treize — en faire un échec rendrait la commande rouge sur
     * l'installation nominale.
     *
     * @return string[]
     */
    private function failedSources(): array
    {
        $failed = [];

        foreach ($this->report as $source => $entry) {
            // STATE_REFUSED compte comme un echec : une destruction evitee reste un travail non
            // fait, et le dictionnaire n'est pas installe. Sans ca, la commande sortait en 0 apres
            // avoir imprime « import REFUSE » — mesure faite, et c'est exactement le defaut que ce
            // fichier corrige par ailleurs.
            if (in_array($entry['state'], [self::STATE_FAILED, self::STATE_REFUSED], true)) {
                $failed[] = (string)$source;
            }
        }

        return $failed;
    }

    /**
     * UN IMPORT EN ÉCHEC LAISSE UNE BASE PEUPLÉE, ET LE PASSAGE SUIVANT LA SAUTE.
     *
     * C'est le défaut qui cachait les autres. L'import de la CIM-10 meurt APRÈS avoir peuplé ses 21
     * tables ; `importAllData()` saute ensuite toute base non vide — « peuplée, conservée » — donc la
     * seconde exécution ne réimporte rien et sort en 0. Un import PARTIEL devient indistinguable d'un
     * import COMPLET, et c'est ce qui a fait conclure à tort que la source passait quand elle était
     * seule alors qu'elle était simplement sautée.
     *
     * On ne peut pas deviner si une base non vide est complète : rien ne l'enregistre, et poser un
     * marqueur dans une base de dictionnaire livrée par un tiers serait pire que le mal. Mais on peut
     * refuser de laisser l'exploitant le deviner — quand un import échoue, on DIT que la base reste en
     * l'état et que le prochain passage la sautera.
     */
    private function partialImportWarning(): ?string
    {
        $failed = $this->failedSources();

        if ($failed === []) {
            return null;
        }

        return 'La ou les bases de ' . implode(', ', $failed) . ' sont restées PARTIELLEMENT peuplées :'
            . " l'import s'est arrêté après avoir déjà écrit des tables."
            . "
Un prochain passage les SAUTERA en les déclarant « peuplée, conservée » et sortira en 0"
            . ' — ce qui ferait passer cet import incomplet pour un succès.'
            . "
Pour réessayer pour de vrai : relancer avec --force.";
    }

    /**
     * LE RAPPORT EST POUR L'HUMAIN, LE CODE DE RETOUR EST POUR LA MACHINE.
     *
     * La commande rendait SUCCESS quoi qu'il arrive : elle affichait « Import en échec : ccamV2 » et
     * sortait en 0. L'entrypoint de production la suit d'un « || echo AVERTISSEMENT » qui ne pouvait
     * donc jamais se déclencher, et un provisionneur automatisé voyait un 0 franc sur une instance
     * sans un seul acte CCAM.
     */
    private function exitCode(): int
    {
        return $this->failedSources() === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Importeurs retenus, dans l'ordre de leur classe.
     *
     * @throws Exception
     */
    private function loadImports(): void
    {
        $classes = CClassMap::getInstance()->getClassChildren(CExternalDataSourceImport::class);

        foreach ($classes as $class) {
            /** @var CExternalDataSourceImport $import */
            $import = new $class();

            if ($this->only !== null && !in_array($import->getSourceName(), $this->only, true)) {
                continue;
            }

            $this->imports[] = $import;
        }

        if ($this->only !== null) {
            $unknown = array_diff($this->only, $this->sourceNames());

            if ($unknown !== []) {
                throw new InvalidArgumentException(
                    'Source inconnue : ' . implode(', ', $unknown) . '. Sources disponibles : '
                    . implode(', ', $this->allSourceNames())
                );
            }
        }
    }

    /**
     * Noms des sources retenues, dédoublonnés.
     *
     * TROIS IMPORTEURS PARTAGENT LA SOURCE `cim10` — OMS, ATIH et la modification allemande — et
     * peuplent des tables différentes de la MÊME base. Le cycle de vie de la base se raisonne donc par
     * SOURCE, l'injection des données par IMPORTEUR.
     *
     * @return string[]
     */
    private function sourceNames(): array
    {
        $names = [];

        foreach ($this->imports as $import) {
            $names[$import->getSourceName()] = true;
        }

        return array_keys($names);
    }

    /**
     * @return string[]
     * @throws Exception
     */
    private function allSourceNames(): array
    {
        $names = [];

        foreach (CClassMap::getInstance()->getClassChildren(CExternalDataSourceImport::class) as $class) {
            /** @var CExternalDataSourceImport $import */
            $import                       = new $class();
            $names[$import->getSourceName()] = true;
        }

        return array_keys($names);
    }

    /**
     * Déclare les datasources manquantes, d'après la connexion standard.
     *
     * LES DICTIONNAIRES VIVENT SUR LE MÊME SERVEUR que la base applicative : hôte, utilisateur et mot
     * de passe sont donc ceux de `db std`, et seul le nom de base change — celui de la source, mis en
     * minuscules par `getSourceNameForSQL()`. Un exploitant qui veut les héberger ailleurs garde la
     * main : la déclaration n'est écrite que si elle MANQUE, et `includes/config_overload.php` la
     * surcharge de toute façon puisqu'il est chargé après.
     *
     * L'écriture est un AJOUT EN FIN DE FICHIER et non une réécriture. `CMbConfig::update()` aurait
     * été plus propre en apparence, mais il fusionne avec le `$dPconfig` global — c'est-à-dire qu'il
     * recopierait tout `config_dist.php` dans le fichier d'instance, y figeant des valeurs par défaut
     * qui doivent rester dans la distribution.
     *
     * @throws Exception
     */
    private function declareDatasources(): void
    {
        if (!$this->params['host'] || !$this->params['database']) {
            throw new LogicException(
                "La datasource « std » n'est pas configurée : impossible d'en déduire les dictionnaires."
                . ' Lancer ox-install:config avant.'
            );
        }

        $file = $this->path . '/includes/config.php';

        if (!is_file($file) || !is_writable($file)) {
            throw new LogicException("Le fichier de configuration {$file} est absent ou non inscriptible.");
        }

        $declarations = $this->missingDeclarations();
        $blocks       = [];
        $missing      = array_keys($declarations);

        foreach ($declarations as $name => $values) {
            $lines = [];
            foreach ($values as $key => $value) {
                $lines[] = sprintf("\$dPconfig['db']['%s']['%s'] = %s;", $name, $key, var_export((string)$value, true));
            }

            $blocks[] = implode("\n", $lines);

            // Posé aussi en mémoire : la suite de CETTE exécution lit la configuration par
            // CAppUI::conf(), qui consulte le global et non le fichier.
            $GLOBALS['dPconfig']['db'][$name] = $values;
        }

        if ($missing === []) {
            $this->io->text('Datasources : toutes déjà déclarées.');

            return;
        }

        $header  = "\n\n// Dictionnaires externes déclarés par ox-install:externals --declare le "
            . date('Y-m-d H:i') . ".\n"
            . "// Hôte et identifiants repris de « db std » : les dictionnaires vivent sur le même serveur.\n";
        $content = $header . implode("\n\n", $blocks) . "\n";

        if (file_put_contents($file, $content, FILE_APPEND) === false) {
            throw new LogicException("Écriture impossible dans {$file}.");
        }

        $this->io->success(count($missing) . ' datasource(s) déclarée(s) : ' . implode(', ', $missing));
    }

    /**
     * Déclarations à écrire : une par source retenue qui n'en a pas déjà une.
     *
     * Séparé de l'écriture pour être vérifiable sans toucher au disque — c'est la partie qui décide,
     * et donc celle qui peut se tromper.
     *
     * @return array<string, array<string, string>>
     * @throws Exception
     */
    private function missingDeclarations(): array
    {
        $declarations = [];

        foreach ($this->sourceNames() as $name) {
            if (CAppUI::conf("db {$name} dbtype")) {
                continue;
            }

            $declarations[$name] = [
                'dbtype' => 'mysql',
                'dbhost' => (string)$this->params['host'],
                'dbname' => $this->databaseFor($name),
                'dbuser' => (string)$this->params['user'],
                'dbpass' => (string)$this->params['password'],
            ];
        }

        return $declarations;
    }

    /**
     * Nom de base d'une source : celui de sa configuration, sinon le nom normalisé de la source.
     *
     * @throws Exception
     */
    private function databaseFor(string $source): string
    {
        $configured = CAppUI::conf("db {$source} dbname");

        if ($configured) {
            return (string)$configured;
        }

        foreach ($this->imports as $import) {
            if ($import->getSourceName() === $source) {
                return $import->getSourceNameForSQL();
            }
        }

        return strtolower($source);
    }

    /**
     * @throws Exception
     */
    private function checkConnexion(): void
    {
        try {
            $this->pdo = new CommandLinePDO($this->params['host'], $this->params['user'], $this->params['password']);
        } catch (Exception $e) {
            if ($this->output->getVerbosity() === OutputInterface::VERBOSITY_VERBOSE) {
                throw $e;
            }

            throw new LogicException("Connexion impossible à mysql:host={$this->params['host']}.");
        }
    }

    /**
     * @throws Exception|LogicException
     */
    private function dropDatabases(): void
    {
        foreach ($this->declaredSources() as $source) {
            $name = $this->databaseFor($source);

            if (!$this->pdo->isDatabaseExists($name)) {
                continue;
            }

            if (!$this->pdo->dropDatabase($name)) {
                throw new LogicException("Suppression impossible de la base externe {$name}.");
            }

            $this->io->text("Base {$name} supprimée.");
        }
    }

    /**
     * Sources effectivement déclarées en configuration.
     *
     * Une source non déclarée n'est PAS une erreur : la configuration livrée n'en déclare qu'une
     * partie, et une instance n'a pas besoin de toutes. Elle est écartée et le rapport le dit.
     *
     * @return string[]
     * @throws Exception
     */
    private function declaredSources(): array
    {
        $declared = [];

        foreach ($this->sourceNames() as $source) {
            if (CAppUI::conf("db {$source} dbtype")) {
                $declared[] = $source;
                continue;
            }

            $this->report[$source] = [
                'database' => '—',
                'state'    => 'non déclarée',
                'detail'   => 'aucune datasource « db ' . $source . ' » en configuration (voir --declare)',
            ];
        }

        return $declared;
    }

    /**
     * Crée les bases manquantes et note, pour chacune, ce qu'il faudra en faire.
     *
     * @throws Exception|LogicException
     */
    private function prepareDatabases(): void
    {
        foreach ($this->declaredSources() as $source) {
            $name = $this->databaseFor($source);

            if (!$this->pdo->isDatabaseExists($name)) {
                if (!$this->pdo->createDatabase($name)) {
                    throw new LogicException("Création impossible de la base externe {$name}.");
                }

                $this->report[$source] = ['database' => $name, 'state' => 'créée', 'detail' => ''];
                continue;
            }

            $this->report[$source] = ['database' => $name] + $this->stateOf($source, $name);
        }

        if ($this->unbuildable_setups !== []) {
            // NE PAS TAIRE CE CAS. Les contrôles de présence viennent des setups ; un setup qu'on
            // n'a pas pu instancier retire silencieusement ses contrôles de la table, et une source
            // dont tous les contrôles ont disparu retombe dans « indéterminée » — donc conservée.
            // C'est exactement par là qu'un faux « à jour » reviendrait.
            $this->io->warning(
                sprintf(
                    '%d setup(s) non instanciable(s), leurs contrôles de dictionnaire sont absents : %s',
                    count($this->unbuildable_setups),
                    implode(' · ', $this->unbuildable_setups)
                )
            );
        }
    }

    /**
     * L'état d'une source, établi par les contrôles que son module DÉCLARE.
     *
     * CE QUI ÉTAIT FAUX, et mesuré sur une installation neuve le 2026-10-03 : l'état se décidait sur
     * `tableCount($database) > 0`. Or les treize sources de dictionnaire de la configuration livrée
     * pointent toutes sur la base de l'APPLICATION. Le compte y trouvait donc les 500 tables du
     * schéma applicatif — que `ox-modules:setup` venait de créer — et la commande annonçait
     * « [OK] Dictionnaires externes à jour » en une seconde, sans avoir importé un seul dictionnaire.
     *
     * Coût mesuré sur deux semis identiques : sans dictionnaires, AUCUN acte CCAM détaillé et AUCUN
     * diagnostic CIM-10 ; avec, 694 actes et 1 556 diagnostics. Le semis le disait dans son tableau
     * et sortait tout de même en 0.
     *
     * LE BON CRITÈRE EXISTAIT DÉJÀ : chaque setup déclare par `addDatasource($dsn, $query)` les
     * requêtes qui attestent que son dictionnaire est en place — `SELECT … FROM master WHERE code =
     * 'U83'` pour la CIM-10, par exemple. Elles sont maintenues par module, au plus près des tables
     * qu'elles interrogent. Mesure de couverture : 12 sources sur 13 en déclarent, 24 contrôles au
     * total ; seule `rpps_import` n'en a aucun.
     *
     * Vérifié sur une base où trois dictionnaires seulement avaient été importés : le nouveau critère
     * rend « peuplée » pour ces trois (ccamV2 2/2, cim10 4/4, INSEE 5/5) et « vide » pour les huit
     * autres, là où l'ancien déclarait les douze « peuplée, 610 tables ».
     *
     * @return array{state: string, detail: string}
     */
    private function stateOf(string $source, string $database): array
    {
        $probes = $this->dictionaryProbes()[$source] ?? [];

        if ($probes === []) {
            // Sans contrôle déclaré, on CONSERVE la base — réimporter un dump de 174 Mo à chaque
            // démarrage coûterait plus que le doute — mais l'état ne prétend pas être vérifié.
            return [
                'state'  => self::STATE_UNKNOWN,
                'detail' => sprintf(
                    'aucun contrôle déclaré — %d table(s) dans la base cible, présence non vérifiable',
                    $this->tableCount($database)
                ),
            ];
        }

        return self::stateFromProbes($this->countPassingProbes($source, $probes), count($probes));
    }

    /**
     * La décision seule, séparée de la base pour être vérifiable.
     *
     * @return array{state: string, detail: string}
     */
    protected static function stateFromProbes(int $passed, int $total): array
    {
        if ($total === 0) {
            return ['state' => self::STATE_UNKNOWN, 'detail' => 'aucun contrôle déclaré'];
        }

        if ($passed >= $total) {
            return ['state' => self::STATE_VERIFIED, 'detail' => "{$passed}/{$total} contrôle(s) du dictionnaire"];
        }

        if ($passed === 0) {
            return ['state' => self::STATE_EMPTY, 'detail' => "0/{$total} contrôle(s) du dictionnaire"];
        }

        // ÉTAT QUE L'ANCIEN CRITÈRE NE POUVAIT PAS VOIR : un import interrompu en chemin laissait des
        // tables derrière lui, donc « peuplée », donc jamais repris. Il est maintenant réimporté.
        return ['state' => self::STATE_PARTIAL, 'detail' => "{$passed}/{$total} contrôle(s) seulement"];
    }

    /**
     * Combien des contrôles déclarés d'une source passent.
     *
     * Les erreurs SQL sont ATTENDUES ici : une table de dictionnaire absente est précisément la
     * réponse que la sonde cherche, pas une anomalie à signaler. Sans ce silence, un démarrage sur
     * base neuve noierait le journal sous treize « Table doesn't exist » qui sont tous des succès de
     * mesure. Le gestionnaire est restauré dans un `finally` : une sonde ne doit pas laisser
     * l'application sourde derrière elle.
     *
     * @param string[] $probes
     */
    private function countPassingProbes(string $source, array $probes): int
    {
        $datasource = CSQLDataSource::get($source, true);

        if (!$datasource) {
            return 0;
        }

        $passed = 0;

        set_error_handler(static function (): bool {
            return true;
        });

        try {
            foreach ($probes as $probe) {
                try {
                    if ($datasource->loadResult($probe)) {
                        $passed++;
                    }
                } catch (Throwable $e) {
                    // Contrôle en échec = dictionnaire absent. Rien à rapporter de plus.
                }
            }
        } finally {
            restore_error_handler();
        }

        return $passed;
    }

    /**
     * Contrôles de présence déclarés par l'ensemble des setups, regroupés par DSN.
     *
     * @return array<string, string[]>
     */
    private function dictionaryProbes(): array
    {
        if ($this->dictionary_probes !== null) {
            return $this->dictionary_probes;
        }

        $probes = [];

        foreach (CClassMap::getInstance()->getClassChildren(CSetup::class) as $class) {
            try {
                /** @var CSetup $setup */
                $setup = new $class();
            } catch (Throwable $e) {
                // AVEC SA CAUSE. Un avertissement qui nomme le symptôme sans la raison force le
                // diagnostic suivant à repartir de zéro — c'est le défaut que ce correctif combat
                // par ailleurs, il n'a pas sa place dans le correctif lui-même.
                $this->unbuildable_setups[] = CClassMap::getSN($class) . ' (' . get_class($e) . ' : '
                    . trim(explode("\n", $e->getMessage())[0]) . ')';

                continue;
            }

            foreach (($setup->datasources ?? []) as $dsn => $queries) {
                $probes[$dsn] = array_merge($probes[$dsn] ?? [], $queries);
            }
        }

        return $this->dictionary_probes = $probes;
    }

    /**
     * La base cible de cette source est-elle celle de l'application ?
     *
     * Comparaison sur le NOM de base, pas sur l'objet de connexion : c'est le nom qui décide où le
     * dump écrit, et c'est lui que config_all.php réaligne. L'hôte entre dans la comparaison parce
     * qu'un même nom de base sur deux serveurs distincts désigne deux bases différentes.
     */
    private function targetsApplicationDatabase(string $source): bool
    {
        $application = (string)$this->params['database'];

        if ($application === '') {
            // Sans base applicative connue, on ne peut pas affirmer la collision. On n'invente pas
            // un refus : l'absence de cette information est déjà signalée ailleurs.
            return false;
        }

        $target = (string)($this->report[$source]['database'] ?? '');

        if ($target === '' || $target !== $application) {
            return false;
        }

        return self::collidesWithApplication(
            $target,
            $application,
            (string)CAppUI::conf("db {$source} dbhost"),
            (string)$this->params['host']
        );
    }

    /**
     * La règle seule, séparée de l'état de la commande pour être vérifiable.
     *
     * Un hôte cible vide vaut collision : la datasource hérite alors de l'hôte par défaut, qui est
     * celui de l'application. Refuser dans le doute est le bon sens ici — le coût d'un refus à tort
     * est un import à relancer, celui d'un import à tort est la table des notes détruite.
     */
    protected static function collidesWithApplication(
        string $target_database,
        string $application_database,
        string $target_host,
        string $application_host
    ): bool {
        if ($application_database === '' || $target_database === '') {
            return false;
        }

        if ($target_database !== $application_database) {
            return false;
        }

        return $target_host === '' || $target_host === $application_host;
    }

    private function tableCount(string $database): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :database'
        );
        $statement->bindValue(':database', $database, PDO::PARAM_STR);
        $statement->execute();

        return (int)$statement->fetchColumn();
    }

    /**
     * @throws Exception
     */
    private function importAllData(): void
    {
        foreach ($this->declaredSources() as $source) {
            $entry = $this->report[$source];

            // Une base déjà peuplée n'est pas réimportée : les dumps pèsent jusqu'à 174 Mo et
            // l'import d'une CCAM prend une minute. --force passe outre.
            // « vérifiée » ET « indéterminée » sont conservées ; « vide » et « incomplète » sont
            // importées. L'ancien test ne connaissait que « peuplée », qui recouvrait les quatre.
            if (in_array($entry['state'], [self::STATE_VERIFIED, self::STATE_UNKNOWN], true) && !$this->force) {
                $this->report[$source]['detail'] = $entry['detail'] . ' — conservée (--force pour réimporter)';
                continue;
            }

            // ★ REFUS D'IMPORTER DANS LA BASE DE L'APPLICATION. Placé ICI, sur l'ACTION d'importer
            // et non sur l'état : une instance dont les dictionnaires sont déjà en place reste
            // « conservée » plus haut, ne tente aucun import, et ne voit donc jamais ce refus. La
            // garde ne mord que là où un import PARTIRAIT.
            //
            // POURQUOI ELLE EXISTE. Le dump CIM-10 livré dans le dépôt (modules/dPcim10/base/
            // cim10.tar.gz) contient 15 DROP TABLE et 15 CREATE TABLE, dont une table nommée
            // `note` — et sur ces quinze noms, un seul collisionne avec une table de
            // l'application : précisément `note`, celle de CNote, qui porte object_id. Importer ce
            // dump dans la base applicative exécute donc « DROP TABLE note » et détruit la table
            // des notes : notes mortes, et suppression d'objets cassée sur les chemins qui
            // remontent les références arrière.
            //
            // ET CE N'EST PAS THÉORIQUE : mesuré sur la production le 2026-10-03, sa table `note`
            // ne porte plus que les colonnes SID et MID — celles du dictionnaire — et huit tables
            // du dump CIM-10 vivent dans sa base applicative depuis le 2026-07-25. La destruction
            // a eu lieu, et personne ne l'a vue pendant plus de deux mois.
            //
            // LA CHAÎNE EST ARMÉE SUR TOUTE INSTANCE DÉPLOYÉE, et elle est courte : l'entrypoint
            // appelle « --declare --import » à chaque démarrage ; --declare écrit la datasource ;
            // includes/config_all.php RÉALIGNE ensuite toute datasource secondaire sur la base
            // principale dès que DB_NAME est posé (vérifié : « db cim10 dbname » vaut la base de
            // l'application) ; puis l'import s'exécute. Le nom de base séparé que --declare avait
            // écrit est donc écrasé au chargement, et l'isolement supposé n'existe pas.
            //
            // Aucune raison légitime ne justifie de laisser un dump de dictionnaire exécuter ses
            // DROP TABLE sur la base de l'application. On refuse, et on le DIT.
            if ($this->targetsApplicationDatabase($source)) {
                $this->report[$source]['state']  = self::STATE_REFUSED;
                $this->report[$source]['detail'] = sprintf(
                    'base cible « %s » = base de l\'application : import REFUSÉ (le dump ferait'
                    . ' DROP TABLE note). Donner à cette source sa propre base.',
                    (string)$this->report[$source]['database']
                );

                continue;
            }

            foreach ($this->importsOf($source) as $import) {
                $label = $this->shortName($import);

                $this->stopwatch->start($label);
                $result = $import->importDatabase();
                $this->durations[$label] = $this->stopwatch->stop($label)->getDuration();

                // L'importeur a fini, post-traitements de sous-classe compris : ses fichiers
                // d'extraction ne servent plus. On les efface ICI et pas dans la classe d'import,
                // qui ne peut pas savoir si un enfant compte encore les relire. Sans cette purge,
                // tmp/ enfle d'un import à l'autre — la seule CCAM décompresse un base.sql
                // d'environ 174 Mo. Voir CExternalDataSourceImport::purgeTmpDir().
                $import->purgeTmpDir();

                if ($this->io->isVerbose()) {
                    $this->io->createTable()->setHeaderTitle('Messages de ' . $label)
                        ->setRows($import->getMessages())
                        ->render();
                }

                if (!$result) {
                    $this->report[$source]['state']  = self::STATE_FAILED;
                    $this->report[$source]['detail'] = $label . ' a échoué (relancer avec -v)';

                    continue 2;
                }
            }

            $this->report[$source]['state']  = 'importée';
            $this->report[$source]['detail'] = $this->tableCount($entry['database']) . ' tables';
        }
    }

    /** Nom court de l'importeur : `getClassName()` du socle est protégé. */
    private function shortName(CExternalDataSourceImport $import): string
    {
        $class = get_class($import);
        $cut   = strrchr($class, '\\');

        return $cut === false ? $class : substr($cut, 1);
    }

    /**
     * @return CExternalDataSourceImport[]
     */
    private function importsOf(string $source): array
    {
        $imports = [];

        foreach ($this->imports as $import) {
            if ($import->getSourceName() === $source) {
                $imports[] = $import;
            }
        }

        return $imports;
    }

    /**
     * Le rapport est le point de la commande : sans lui, un dictionnaire absent ne se remarque qu'au
     * moment où une page affiche un code sans libellé — ou, pour la CIM-10, quand l'écriture d'un
     * diagnostic interrompt le processus.
     */
    private function displayReport(): void
    {
        if ($this->report === []) {
            $this->io->warning('Aucune source à traiter.');

            return;
        }

        $rows = [];
        foreach ($this->report as $source => $entry) {
            $rows[] = [$source, $entry['database'], $entry['state'], $entry['detail']];
        }

        $this->io->createTable()
            ->setHeaders(['source', 'base', 'état', 'détail'])
            ->setRows($rows)
            ->render();

        $failed = $this->failedSources();

        if ($failed !== []) {
            $this->io->error('Import en échec : ' . implode(', ', $failed));

            // Sans cet avertissement, l'exploitant relance la commande, la voit sortir en 0, et croit
            // que le problème s'est résolu — alors qu'elle a seulement sauté une base à moitié écrite.
            $warning = $this->partialImportWarning();

            if ($warning !== null) {
                $this->io->warning($warning);
            }

            return;
        }

        // LE VERDICT NE DIT « À JOUR » QUE SI C'EST VRAI. Sans --import, la commande se contente
        // de constater, et constater qu'il manque des dictionnaires n'est pas les avoir installés.
        // La version précédente annonçait le succès dans les deux cas — c'est la même faute que le
        // critère corrigé plus haut, un cran plus loin dans la même commande.
        $missing = [];

        foreach ($this->report as $source => $entry) {
            if (in_array($entry['state'], [self::STATE_EMPTY, self::STATE_PARTIAL], true)) {
                $missing[] = $source;
            }
        }

        $refused = [];

        foreach ($this->report as $source => $entry) {
            if ($entry['state'] === self::STATE_REFUSED) {
                $refused[] = $source;
            }
        }

        if ($refused !== []) {
            // ERREUR, pas avertissement : le dictionnaire n'est pas installé et ne le sera pas tant
            // que sa base n'aura pas été séparée. Une destruction évitée reste un travail non fait.
            $this->io->error(
                sprintf(
                    "%d import(s) REFUSÉ(S) : %s\nLeur base cible est celle de l'application, et le"
                    . ' dump exécuterait DROP TABLE note — la table des notes de l\'application.'
                    . " Donner à ces sources leur propre base, puis relancer.\nNB : includes/"
                    . 'config_all.php réaligne toute datasource secondaire sur DB_NAME ; un nom de'
                    . ' base séparé écrit par --declare y est écrasé.',
                    count($refused),
                    implode(', ', $refused)
                )
            );

            // On SORT. Le verdict de succes qui suivait s'imprimait juste apres cette erreur.
            return;
        }

        if ($missing !== []) {
            $this->io->warning(
                sprintf(
                    "%d dictionnaire(s) ABSENT(S) ou incomplet(s) : %s\nRelancer avec --import pour"
                    . ' les installer. Sans eux, un jeu de données est semé sans aucun acte CCAM'
                    . ' détaillé ni aucun diagnostic CIM-10 — et le semis sort tout de même en 0.',
                    count($missing),
                    implode(', ', $missing)
                )
            );

            return;
        }

        $unknown = [];

        foreach ($this->report as $source => $entry) {
            if ($entry['state'] === self::STATE_UNKNOWN) {
                $unknown[] = $source;
            }
        }

        if ($unknown !== []) {
            // On le dit AUTREMENT plutôt que de l'inclure dans « à jour » : ces sources ne déclarent
            // aucun contrôle, donc personne ne sait si elles sont en place.
            $this->io->success(
                sprintf(
                    'Dictionnaires externes à jour, sauf %d sans contrôle déclaré (%s) : présence non vérifiable.',
                    count($unknown),
                    implode(', ', $unknown)
                )
            );

            return;
        }

        $this->io->success('Dictionnaires externes à jour.');
    }

    private function displayImportDurations(): void
    {
        if ($this->durations === []) {
            return;
        }

        $durations = $this->durations;
        arsort($durations);

        $table = $this->io->createTable()->setHeaderTitle('Durées')->setHeaders(['importeur', 'durée (ms)']);

        foreach ($durations as $name => $duration) {
            $table->addRow([$name, $duration]);
        }

        $table->render();
    }
}
