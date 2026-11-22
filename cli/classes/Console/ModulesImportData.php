<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Ox\Core\Composer\CComposerScript;
use Ox\Core\CSQLDataSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Charge les nomenclatures de référence bundlées (CIM10 OMS, CCAM, LPP, CSARR,
 * CDARR, HL7 v2, SAE) au bootstrap, après ox-modules:setup. Non bloquant.
 *
 * Idempotence par **sentinelle de complétion** : une nomenclature n'est marquée
 * « chargée » (table `nomenclature_import_state`) qu'après un import RÉUSSI. Un
 * import échoué à mi-chemin (queryDump n'est pas transactionnel : il s'arrête à
 * la 1re erreur en laissant la table-sonde partiellement remplie) n'écrit pas de
 * sentinelle → il est **retenté au boot suivant** au lieu d'être masqué à jamais
 * par un simple « table non vide ». Des données déjà présentes sans sentinelle
 * (import manuel do_import_* antérieur) sont adoptées sans réimport.
 */
class ModulesImportData extends HospilibreCommand
{
    private const STATE_TABLE = 'nomenclature_import_state';

    /**
     * Registre curé des nomenclatures de référence bundlées du socle.
     *
     * Chaque entrée déclare :
     *  - module : nom du module (doit être actif pour que l'import ait lieu) ;
     *  - label  : libellé lisible pour les logs ;
     *  - class  : classe d'import (héritant de CExternalDataSourceImport) ;
     *  - source : SOURCE_NAME de l'import = datasource cible où sont écrites les
     *             données ET où la sonde d'idempotence doit compter les lignes ;
     *  - table  : table créée+peuplée par cet import, unique à lui, servant de
     *             sonde d'idempotence (vide => import) ;
     *  - action : 2e argument de importDatabase() pour la signature variante
     *             (CIM10) ; null => importDatabase([]) standard.
     */
    private const IMPORTS = [
        [
            'module' => 'dPcim10',
            'label'  => 'CIM10 OMS',
            'class'  => \Hospilibre\Cim10\Oms\CCIM10OmsImport::class,
            'source' => 'cim10',
            'table'  => 'master',
            // 'all' = base OMS (cim10.sql) + mises à jour CSV bundlées
            // (cim10_modifs.csv) : c'est l'import OMS COMPLET. Sans le CSV
            // ('import' seul), des codes récents manquent (ex. U83) et le
            // contrôle de datasource CSetupCim10 (SELECT … WHERE code='U83')
            // reste « à mettre à jour ». Débloqué par le fix charset (master
            // est désormais créé avant que CImportCim10 ne l'interroge).
            'action' => 'all',
        ],
        [
            'module' => 'dPcim10',
            'label'  => 'CIM10 GM',
            'class'  => \Hospilibre\Cim10\Gm\CCIM10GmImport::class,
            'source' => 'cim10',
            'table'  => 'codes_gm',
            'action' => null,
        ],
        [
            'module' => 'dPcim10',
            'label'  => 'CIM10 ATIH',
            'class'  => \Hospilibre\Cim10\Atih\CCIM10AtihImport::class,
            'source' => 'cim10',
            'table'  => 'codes_atih',
            'action' => null,
        ],
        [
            'module' => 'dPcim10',
            'label'  => 'CIM10 CISP',
            'class'  => \Hospilibre\Cim10\Cisp\CCIM10CispImport::class,
            'source' => 'cisp',
            'table'  => 'chapitre',
            'action' => null,
        ],
        [
            'module' => 'dPcim10',
            'label'  => 'CIM10 DRC',
            'class'  => \Hospilibre\Cim10\Drc\CCIM10DrcImport::class,
            'source' => 'drc',
            'table'  => 'consultation_results',
            'action' => null,
        ],
        [
            'module' => 'dPccam',
            'label'  => 'CCAM',
            'class'  => \Hospilibre\Ccam\CCCAMImport::class,
            'source' => 'ccamV2',
            'table'  => 'p_acte',
            'action' => null,
        ],
        [
            'module' => 'lpp',
            'label'  => 'LPP',
            'class'  => \Hospilibre\Lpp\CLPPImport::class,
            'source' => 'lpp',
            'table'  => 'fiche',
            'action' => null,
        ],
        // CDARR AVANT CSARR : les deux partagent la table `activite` (chacun la
        // DROP+CREATE). CDARR en premier => c'est la donnée CSARR (catalogue 2021
        // complet, avec ALQ+402) qui subsiste dans `activite`, tandis que
        // `intervenant` (propre à CDARR) reste intact -> les deux contrôles de
        // datasource SSR (CSetupSsr) passent. Ordre inverse = CDARR écrase CSARR.
        [
            'module' => 'ssr',
            'label'  => 'CDARR',
            'class'  => \Hospilibre\Ssr\CCdarrImport::class,
            'source' => 'cdarr',
            'table'  => 'intervenant',
            'action' => null,
        ],
        [
            'module' => 'ssr',
            'label'  => 'CSARR',
            'class'  => \Hospilibre\Ssr\CCsarrImport::class,
            'source' => 'csarr',
            'table'  => 'note_activite',
            'action' => null,
        ],
        [
            'module' => 'hl7',
            'label'  => 'HL7 v2',
            'class'  => \Ox\Interop\Hl7\CHL7v2Import::class,
            'source' => 'hl7v2',
            'table'  => 'table_entry',
            'action' => null,
        ],
        [
            'module' => 'dPetablissement',
            'label'  => 'SAE',
            'class'  => \Hospilibre\Etablissement\CSaeImport::class,
            'source' => 'sae',
            'table'  => 'legal_status',
            'action' => null,
        ],
        [
            // Dossier patient : référentiel INSEE (communes de France + pays).
            // Contrôlé par CSetupPatients::checkCommunes (SELECT … FROM
            // `communes_france` WHERE code_postal/commune) => sonde communes_france.
            'module' => 'dPpatients',
            'label'  => 'INSEE',
            'class'  => \Hospilibre\Patients\CInseeImport::class,
            'source' => 'INSEE',
            'table'  => 'communes_france',
            'action' => null,
        ],
    ];

    private SymfonyStyle $io;

    /** La table de sentinelles est-elle utilisable ? (dégradation propre sinon) */
    private bool $stateAvailable = false;

    protected function configure(): void
    {
        $this
            ->setName('ox-modules:import-data')
            ->setDescription(
                'Importe les données de référence bundlées (nomenclatures) des modules actifs si absentes'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_OPTIONAL,
                'Racine du projet',
                dirname(__DIR__, 3) . '/'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        // Les dumps de nomenclatures sont volumineux : CCAM `base.sql` ≈ 174 Mo,
        // chargé en mémoire d'un bloc par CSQLDataSource::queryDump() (`file()`).
        // La limite CLI par défaut (128 Mo) provoque un OutOfMemoryError fatal
        // qui tue toute la commande. On la relève pour ce seeding ponctuel au
        // boot (l'hôte a l'espace ; op unique au 1er install). Le temps
        // d'exécution CLI est déjà illimité par défaut.
        ini_set('memory_limit', '1024M');

        $root = rtrim($input->getOption('path'), '/');

        $this->bootstrap($root);
        $this->ensureStateTable();

        $this->io->title(sprintf('Import des données de référence — %d nomenclature(s)', count(self::IMPORTS)));

        foreach (self::IMPORTS as $entry) {
            $this->processEntry($entry);
        }

        return self::SUCCESS;
    }

    /**
     * @param array{module:string,label:string,class:class-string,source:string,table:string,action:?string} $entry
     */
    private function processEntry(array $entry): void
    {
        $label = $entry['label'];

        try {
            if (!$this->isModuleActive($entry['module'])) {
                $this->io->writeln("$label : module inactif, ignoré");

                return;
            }

            // Sonde le datasource où l'import écrit (SOURCE_NAME), pas std.
            // $available=false si le datasource est absent OU injoignable (voir probe()).
            [$exists, $rowCount, $available] = $this->probe($entry['source'], $entry['table']);
            if (!$available) {
                $this->io->writeln("$label : datasource '{$entry['source']}' indisponible, ignoré");

                return;
            }

            // Sentinelle : import déjà mené à bien lors d'un boot précédent.
            // Clé = label (unique par entrée) et NON source : plusieurs imports
            // partagent un datasource (CIM10 OMS/GM/ATIH => 'cim10') ; keyer par
            // source ferait sauter les frères une fois le premier marqué.
            if ($this->isImported($label)) {
                $this->io->writeln("$label : déjà importé, ignoré");

                return;
            }

            // Données déjà présentes sans sentinelle (import manuel antérieur) :
            // on adopte l'état existant sans réimporter ni écraser.
            if (!self::needsImport(true, $exists, $rowCount)) {
                $this->markImported($label, $rowCount);
                $this->io->writeln("$label : déjà chargé ($rowCount lignes), adopté");

                return;
            }

            // Table absente ou vide, pas de sentinelle → import, puis marquage
            // UNIQUEMENT si l'import a réussi (sinon retenté au prochain boot).
            $this->io->section("$label : import en cours…");
            if ($this->runImport($entry)) {
                $rows = $this->probe($entry['source'], $entry['table'])[1];
                $this->markImported($label, $rows);
                $this->io->success("$label : import terminé ($rows lignes)");
            } else {
                $this->io->error("$label : import en échec — pas de sentinelle, sera retenté au prochain boot");
            }
        } catch (Throwable $e) {
            // Un échec d'une nomenclature ne doit pas interrompre les suivantes
            // ni faire échouer le boot (l'entrypoint appelle avec || true).
            $this->io->error(sprintf('%s : %s', $label, $e->getMessage()));
        }
    }

    /**
     * Sonde le datasource de l'import : la table cible existe-t-elle, et
     * combien de lignes ?
     *
     * Robustesse : `CSQLDataSource::get()` peut pointer vers une base séparée
     * (cim10/ccamV2/hl7v2/…) injoignable sur l'instance. Dans ce cas la
     * connexion échoue via un **E_USER_ERROR** (type 256, ex. « SQLSTATE[HY000]
     * [2002] No such file or directory ») qui, sans handler, est **fatal et non
     * catchable** par un simple try/catch et tuerait la commande en plein
     * milieu de boucle. On installe donc, le temps de la sonde, un handler qui
     * convertit toute erreur en exception : un datasource injoignable est alors
     * traité comme « indisponible » (skip) au lieu de casser le boot.
     *
     * @return array{0:bool,1:int,2:bool} [tableExists, rowCount, datasourceAvailable]
     */
    private function probe(string $source, string $table): array
    {
        // CSQLDataSource::get() fait un error_reporting(0) puis le restaure ;
        // mais quand il lève un E_USER_ERROR (datasource injoignable), notre
        // handler transforme l'erreur en exception AVANT qu'il n'atteigne sa
        // restauration → error_reporting resterait à 0 pour le reste du
        // process. On mémorise donc le niveau et on le restaure dans finally.
        $reporting = error_reporting();

        set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
            throw new \ErrorException($str, 0, $no, $file, $line);
        });

        try {
            $ds = CSQLDataSource::get($source, true);
            if ($ds === null) {
                return [false, 0, false];
            }

            // hasTable sans cache : au 1er boot la table peut venir d'être créée.
            $exists   = $ds->hasTable($table, false);
            $rowCount = $exists ? (int) $ds->loadResult("SELECT COUNT(*) FROM `$table`") : 0;

            return [$exists, $rowCount, true];
        } catch (Throwable $e) {
            // Datasource absent ou injoignable → on saute cette nomenclature.
            return [false, 0, false];
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
    }

    /**
     * Exécute l'import et renvoie s'il a RÉUSSI.
     *
     * L'échec est détecté par (a) la valeur de retour booléenne de
     * importDatabase() (false = au moins un fichier/ligne en erreur) et, en
     * défense supplémentaire, (b) tout message de niveau UI_MSG_ERROR/
     * UI_MSG_WARNING dans getMessages(). Sur le chemin nominal les imports
     * n'émettent que du UI_MSG_OK, donc (b) ne produit pas de faux échec. Un
     * import en échec ne doit pas être marqué « chargé » — d'où l'importance de
     * ne pas gober ce résultat.
     *
     * @param array{module:string,label:string,class:class-string,source:string,table:string,action:?string} $entry
     */
    private function runImport(array $entry): bool
    {
        $class  = $entry['class'];
        $action = $entry['action'];

        // Comme ox-modules:setup : pendant l'import lourd, un warning PHP non
        // fatal en contexte web (promu en exception par le handler global CLI)
        // ne doit pas casser un import par ailleurs réussi. Les vraies erreurs
        // (Error, exceptions SQL) restent propagées au catch appelant.
        set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
            if (in_array($no, [E_WARNING, E_NOTICE, E_DEPRECATED, E_STRICT, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED], true)) {
                return true;
            }

            throw new \ErrorException($str, 0, $no, $file, $line);
        });

        try {
            /** @var \Ox\Core\Import\CExternalDataSourceImport $import */
            $import = new $class();

            $result = ($action !== null)
                ? $import->importDatabase([], $action)
                : $import->importDatabase([]);

            // L'importeur a fini, post-traitements de sous-classe compris : ses fichiers
            // d'extraction ne servent plus. Purgé ICI et pas dans la classe d'import, qui ne
            // peut pas savoir si un enfant compte encore les relire. Sans ça, tmp/ enfle à
            // chaque boot qui importe — la seule CCAM décompresse un base.sql d'environ
            // 174 Mo. Voir CExternalDataSourceImport::purgeTmpDir().
            $import->purgeTmpDir();

            $failed = ($result === false);
            foreach ($import->getMessages() as $message) {
                // getMessages() renvoie des tableaux [texte, niveau, args…].
                $text  = is_array($message) ? (string) ($message[0] ?? '') : (string) $message;
                $level = is_array($message) ? ($message[1] ?? null) : null;
                $this->io->writeln('  ' . $text);

                if (
                    (defined('UI_MSG_ERROR') && $level === UI_MSG_ERROR)
                    || (defined('UI_MSG_WARNING') && $level === UI_MSG_WARNING)
                ) {
                    $failed = true;
                }
            }

            return !$failed;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Faut-il (re)charger cette nomenclature ?
     *
     * Seeding : on importe si le module est actif ET que la table cible est
     * soit absente (à créer), soit présente mais vide. Une table déjà peuplée
     * est laissée intacte — la mise à jour est un acte manuel séparé.
     */
    public static function needsImport(bool $moduleActive, bool $tableExists, int $rowCount): bool
    {
        if (!$moduleActive) {
            return false;
        }

        if (!$tableExists) {
            return true;
        }

        return $rowCount === 0;
    }

    /**
     * Enregistre les PSR-4 des modules (non fusionnés par `composer install
     * --no-scripts` du build Docker) puis boote l'app en CLI via startForCli,
     * exactement comme ox-modules:setup — sinon les classes d'import des
     * modules ne sont pas autoloadables et CSQLDataSource n'est pas initialisé.
     */
    private function bootstrap(string $root): void
    {
        $autoloader = require "$root/vendor/autoload.php";

        foreach (glob("$root/modules/*/composer.json") as $composerFile) {
            $json = json_decode(file_get_contents($composerFile), true);
            $dir  = dirname($composerFile);

            foreach (($json['autoload']['psr-4'] ?? []) as $namespace => $path) {
                $autoloader->addPsr4($namespace, "$dir/$path");
            }
        }

        CComposerScript::$is_running = true;

        CApp::getInstance()->startForCli('ox-modules:import-data');
    }

    private function isModuleActive(string $modName): bool
    {
        // $modName provient exclusivement du registre en dur (self::IMPORTS),
        // jamais d'une entrée utilisateur : pas d'injection possible.
        $ds    = CSQLDataSource::get('std');
        $count = $ds->loadResult("SELECT COUNT(*) FROM `modules` WHERE `mod_name` = '$modName' AND `mod_active` = 1");

        return (int) $count > 0;
    }

    /**
     * Table de sentinelles de complétion (dans std) : trace les nomenclatures
     * importées avec succès pour ne pas les rejouer. Créée à la volée (op unique
     * au 1er boot).
     */
    private function ensureStateTable(): void
    {
        // Non bloquant : si la création échoue (droits DDL, etc.), on dégrade
        // proprement vers l'idempotence « table cible vide » sans faire échouer
        // la commande ni chaque nomenclature.
        try {
            CSQLDataSource::get('std')->exec(
                'CREATE TABLE IF NOT EXISTS `' . self::STATE_TABLE . '` ('
                . '`nomenclature` VARCHAR(96) NOT NULL PRIMARY KEY,'
                . '`imported_at` DATETIME NOT NULL,'
                . '`rows_count` INT NOT NULL DEFAULT 0'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $this->stateAvailable = true;
        } catch (Throwable $e) {
            $this->stateAvailable = false;
            $this->io->warning(
                "Table de sentinelles indisponible ({$e->getMessage()}) — repli sur l'idempotence par table vide"
            );
        }
    }

    /**
     * Cette nomenclature a-t-elle déjà été importée avec succès ?
     * ($key = label unique de l'entrée du registre — pas d'injection.)
     */
    private function isImported(string $key): bool
    {
        if (!$this->stateAvailable) {
            return false;
        }

        $ds = CSQLDataSource::get('std');

        return (int) $ds->loadResult(
            'SELECT COUNT(*) FROM `' . self::STATE_TABLE . "` WHERE `nomenclature` = '$key'"
        ) > 0;
    }

    /**
     * Pose/actualise la sentinelle après un import réussi (ou l'adoption de
     * données préexistantes). ($key label registre ; $rows entier — pas d'injection.)
     */
    private function markImported(string $key, int $rows): void
    {
        if (!$this->stateAvailable) {
            return;
        }

        CSQLDataSource::get('std')->exec(
            'REPLACE INTO `' . self::STATE_TABLE . '` (`nomenclature`,`imported_at`,`rows_count`) '
            . "VALUES ('$key', NOW(), " . (int) $rows . ')'
        );
    }
}
