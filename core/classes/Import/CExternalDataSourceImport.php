<?php

/**
 * @package Ox\Core\Import
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Core\Import;

use Exception;
use FilesystemIterator;
use Ox\Core\CMbPath;
use Ox\Core\CSQLDataSource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use const DIRECTORY_SEPARATOR;

/**
 * Description
 */
class CExternalDataSourceImport
{
    private const TEMP_DIR = 'tmp';

    /** @var array */
    protected $data_files = [];

    /** @var string */
    protected $source_name;

    /** @var string */
    protected $source_dir;

    /** @var string */
    protected $data_dir;

    /** @var string */
    protected $tmp_dir;

    /** @var CSQLDataSource */
    protected $ds;

    /** @var array */
    protected $messages = [];

    public function __construct(?string $name, ?string $path, ?array $data)
    {
        if (!empty($name)) {
            $this->setSourceName($name);
        }
        if (!empty($path)) {
            $this->setDataDirectory($path);
        }
        if (!empty($data)) {
            foreach ($data as $name => $item) {
                $this->addData($name, $item);
            }
        }
    }

    public function setSourceName(string $name): self
    {
        $this->source_name = $name;
        $this->tmp_dir     = self::TEMP_DIR . DIRECTORY_SEPARATOR . $this->source_name . DIRECTORY_SEPARATOR;

        return $this;
    }

    public function getSourceName(): string
    {
        return $this->source_name;
    }

    public function getSourceNameForSQL(): string
    {
        return strtolower(str_replace('-', '_', $this->getSourceName()));
    }

    public function getSource(): ?CSQLDatasource
    {
        return $this->ds;
    }

    public function setSource(): ?CSQLDataSource
    {
        $this->ds = CSQLDataSource::get($this->source_name);

        if ($this->ds) {
            $this->prepareLegacyDumpConnection();
        }

        return $this->ds;
    }

    /**
     * Relâche le mode strict le temps de l'import des nomenclatures.
     *
     * Ces sources sont d'anciens exports qui portent des dates zéro
     * (« 0000-00-00 ») : sous MariaDB moderne, STRICT_TRANS_TABLES fait échouer
     * le premier INSERT qui en contient, et l'exécution du dump
     * (CSQLDataSource::queryDump) s'arrête là, laissant l'import partiel.
     *
     * NB : on ne touche PAS au charset de la connexion. Les octets des dumps
     * sont ramenés en UTF-8 fichier par fichier juste après extraction (voir
     * transcodeExtractedFiles()), et la connexion est en utf8mb4. Déclarer ici
     * un « SET NAMES latin1 » — ce que faisait une version antérieure de cette
     * méthode — transcoderait une seconde fois des octets déjà en UTF-8 et
     * produirait du mojibake (« é » -> « Ã© »).
     *
     * Effet limité aux imports de référentiels (setSource n'est appelé que par
     * eux). La commande CLI d'import tourne dans un processus dédié ; côté web,
     * do_import_* est une requête dédiée à l'import.
     */
    private function prepareLegacyDumpConnection(): void
    {
        $this->ds->exec("SET SESSION sql_mode = ''");
    }

    public function setDataDirectory(string $path): self
    {
        $this->data_dir   = $path;
        $this->source_dir = $this->getDirectory() . DIRECTORY_SEPARATOR . $this->data_dir . DIRECTORY_SEPARATOR;

        return $this;
    }

    public function addData(string $name, array $items): self
    {
        $this->data_files[$name] = $items;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function importDatabase(?array $types = []): bool
    {
        $return = true;

        $this->setSource();

        if (empty($types) && !empty($this->data_files)) {
            $types = array_keys($this->data_files);
        }

        foreach ($this->data_files as $type => $files) {
            if (!in_array($type, $types)) {
                continue;
            }

            $zip_name = reset($files);

            if (!$this->extractData($zip_name)) {
                $return = false;
                continue;
            }

            array_shift($files);

            foreach ($files as $file) {
                $return = $return && $this->importFile($file);
            }
        }

        return $return;
    }

    /**
     * Efface les fichiers d'extraction temporaires (tmp/<source>/).
     *
     * Ils ne servent que le temps de l'import (extraction -> transcodage ->
     * queryDump) et peuvent peser lourd : la CCAM décompresse un `base.sql`
     * d'environ 174 Mo. Sans purge ils s'accumulent à chaque import et saturent
     * le disque des petites instances.
     *
     * C'EST À L'ORCHESTRATEUR DE L'APPELER, jamais à cette classe elle-même.
     * Deux placements ont été essayés et sont faux, chacun pour sa raison :
     *
     * - dans __destruct() : extractData() est aussi utilisée SEULE, sans import
     *   derrière (tests de transcodage, ox-diagnose). Un appelant en style
     *   fluide « (new self(...))->extract(...) » voyait alors son répertoire
     *   effacé dès le retour, avant d'avoir pu lire un fichier.
     *
     * - à la fin de importDatabase() : SIX sous-classes l'étendent en appelant
     *   « parent::importDatabase() » PUIS en relisant les fichiers extraits
     *   (CCIM10AtihImport et CCIM10OmsImport lisent un CSV, de même LPP, RPPS,
     *   CDARR, CSARR). Purger à la fin de la méthode parente coupe donc l'herbe
     *   sous le pied de l'enfant : « fopen(tmp/cim10/cim10_atih.csv): No such
     *   file or directory ».
     *
     * Seul l'appelant qui a lancé l'import sait qu'il est VRAIMENT terminé,
     * post-traitements des sous-classes compris. Voir InstallExternals et
     * ModulesImportData.
     */
    public function purgeTmpDir(): void
    {
        // Garde-fou : ne jamais viser tmp/ lui-même (source_name vide).
        if (!empty($this->source_name) && !empty($this->tmp_dir) && is_dir($this->tmp_dir)) {
            CMbPath::remove($this->tmp_dir, false);
        }
    }

    protected function extractData(string $zip_name): bool
    {
        try {
            $count = CMbPath::extract($this->source_dir . $zip_name, $this->tmp_dir);
        } catch (Throwable $e) {
            $this->addMessage([$e->getMessage(), UI_MSG_WARNING, $zip_name]);
            return false;
        }

        $this->addMessage([$this->getClassName() . '-Info-Files extracted from', UI_MSG_OK, $count, $zip_name]);

        $this->transcodeExtractedFiles();

        return true;
    }

    /**
     * Remet en UTF-8 les fichiers extraits qui n'y sont pas.
     *
     * POURQUOI C'EST INDISPENSABLE : les dumps des dictionnaires officiels (CCAM, CIM-10 OMS, CIM-10
     * ATIH, CIM-10 GM) sont encodés en Windows-1252, alors que la connexion SQL est câblée en utf8mb4
     * — voir CPDODataSource::connect(), qui émet « SET NAMES 'utf8mb4' » sans que rien ne puisse le
     * paramétrer. MySQL reçoit donc des octets hauts isolés, les juge invalides en UTF-8 et les
     * remplace par « ? » : sans cette conversion, la CCAM s'importe intégralement SANS UN SEUL ACCENT
     * (« Capsulotomie corn?enne »), et la CIM-10 avec elle. Le défaut est silencieux — l'import
     * s'annonce réussi.
     *
     * L'encodage source est bien Windows-1252 et non ISO-8859-1 : les dumps contiennent 0x92 (’),
     * 0x96 (–) et 0x9C (œ), qui sont des caractères de contrôle en ISO-8859-1.
     *
     * DEUX PRÉCAUTIONS :
     * - la détection est faite FICHIER PAR FICHIER, parce qu'une même archive en mélange les deux
     *   (dans ccam.tar.gz, tables.sql est déjà en UTF-8 et le convertir l'abîmerait) ;
     * - la lecture est faite LIGNE PAR LIGNE, parce qu'un dump peut peser plusieurs centaines de
     *   mégaoctets et qu'aucune séquence UTF-8 ne contient de saut de ligne — découper ailleurs
     *   risquerait de scinder un caractère.
     */
    protected function transcodeExtractedFiles(): void
    {
        foreach ($this->listExtractedFiles() as $path) {
            if ($this->isUtf8($path)) {
                continue;
            }

            if ($this->transcodeFromWindows1252($path)) {
                $this->addMessage(
                    [$this->getClassName() . '-Info-File transcoded to UTF-8', UI_MSG_OK, basename($path)]
                );
            } else {
                $this->addMessage(
                    [$this->getClassName() . '-Error-File cannot be transcoded', UI_MSG_WARNING, basename($path)]
                );
            }
        }
    }

    /**
     * @return string[] Chemins des fichiers extraits, sous-répertoires compris
     */
    protected function listExtractedFiles(): array
    {
        if (!is_dir($this->tmp_dir)) {
            return [];
        }

        $paths    = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp_dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * Un fichier sans octet haut est valide en UTF-8 : il n'est pas touché.
     */
    protected function isUtf8(string $path): bool
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            return true;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (!mb_check_encoding($line, 'UTF-8')) {
                    return false;
                }
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    protected function transcodeFromWindows1252(string $path): bool
    {
        $source = fopen($path, 'r');
        $target = $path . '.utf8';
        $sink   = $source ? fopen($target, 'w') : false;

        if (!$source || !$sink) {
            if ($source) {
                fclose($source);
            }

            return false;
        }

        try {
            while (($line = fgets($source)) !== false) {
                if (fwrite($sink, mb_convert_encoding($line, 'UTF-8', 'Windows-1252')) === false) {
                    return false;
                }
            }
        } finally {
            fclose($source);
            fclose($sink);
        }

        return rename($target, $path);
    }

    /**
     * @throws Exception
     */
    protected function importFile(string $file_name): bool
    {
        try {
            if (!$this->ds) {
                return true;
            }

            // === false, ET NON === null : queryDump() rend false dès qu'une requête du dump
            // échoue, et le nombre de requêtes exécutées sinon — jamais null. La comparaison à null
            // ne pouvait donc jamais être vraie, et un dump interrompu à mi-parcours était compté
            // comme importé. Le mensonge allait dans le sens rassurant : la base du dictionnaire
            // existe, elle porte des tables, la commande affiche « importée » avec leur nombre, et
            // rien ne se voit avant qu'un acte CCAM ne sorte sans libellé ou qu'un semis ne trouve
            // aucun acte à coter (constaté : ccamv2 à 80 tables sur 85).
            // La comparaison reste STRICTE : un dump vide rend 0, qui est un succès, et « == false »
            // le confondrait avec l'échec.
            $path = $this->tmp_dir . $file_name;
            $this->normalizeDumpCharsetToUtf8mb4($path);

            if (($line_count = $this->ds->queryDump($path)) === false) {
                throw new Exception($this->getClassName() . '-Error-File, an error occured in query');
            }

            $this->addMessage([$this->getClassName() . '-Info-File imported, query executed', UI_MSG_OK, $file_name, $line_count]);
        } catch (Exception $e) {
            $this->addMessage([$e->getMessage(), UI_MSG_WARNING, $this->ds->error()]);
            return false;
        }

        return true;
    }

    /**
     * Réécrit les déclarations de charset latin1 du DDL d'un dump en utf8mb4,
     * pour que les tables de nomenclatures soient créées en **utf8mb4** (et non
     * latin1 comme le déclarent les vieux dumps CCAM/CSARR/SAE).
     *
     * Complémentaire de transcodeExtractedFiles(), qui règle les *octets* : ici
     * on règle le *contenant*. Une colonne déclarée latin1 ne peut pas stocker
     * « ’ » (U+2019), « – » (U+2013) ni « œ » (U+0153), qui sont précisément les
     * caractères que les dumps portent en 0x92/0x96/0x9C — sans cette
     * réécriture, MySQL les remplacerait par « ? » à l'insertion, annulant le
     * bénéfice du transcodage.
     *
     * Ne touche que le DDL : les lignes INSERT (données) sont laissées telles
     * quelles. Optimisé : les fichiers sans déclaration latin1 (ex. les gros
     * fichiers de données `base.sql`, ~174 Mo, uniquement des INSERT) ne sont
     * pas réécrits — pas de copie temporaire inutile.
     */
    private function normalizeDumpCharsetToUtf8mb4(string $path): void
    {
        if (!is_file($path) || !is_readable($path) || !is_writable($path)) {
            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        // Passe 1 : le fichier déclare-t-il un charset/collation latin1 ? (DDL only)
        $needs_rewrite = false;
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/(CHARSET\s*=\s*latin1|CHARACTER SET\s*=?\s*latin1|latin1_[a-z0-9_]+)/i', $line)) {
                $needs_rewrite = true;
                break;
            }
        }

        if (!$needs_rewrite) {
            fclose($handle);

            return;
        }

        // Passe 2 : réécriture vers un fichier temporaire puis remplacement.
        rewind($handle);
        $tmp_path = $path . '.utf8mb4';
        $out      = fopen($tmp_path, 'w');
        if ($out === false) {
            fclose($handle);

            return;
        }

        while (($line = fgets($handle)) !== false) {
            // Ne réécrire que le DDL, jamais une ligne de données : « latin1 »
            // n'apparaît que dans les clauses CREATE TABLE, jamais comme valeur
            // de nomenclature. Limite connue (sans risque sur ces dumps curés) :
            // seules les lignes DÉBUTANT par INSERT sont exclues ; une éventuelle
            // ligne de continuation d'un INSERT multi-lignes ne l'est pas — mais
            // les fichiers porteurs de DDL latin1 ont ici un INSERT par ligne.
            // Les collations _bin sont préservées (utf8mb4_bin) pour ne pas
            // transformer une comparaison sensible à la casse en insensible.
            if (stripos(ltrim($line), 'INSERT') !== 0) {
                $line = preg_replace(
                    [
                        '/CHARSET\s*=\s*latin1\b/i',
                        '/CHARACTER SET\s*=?\s*latin1\b/i',
                        '/\blatin1_bin\b/i',
                        '/\blatin1_[a-z0-9_]+/i',
                    ],
                    [
                        'CHARSET=utf8mb4',
                        'CHARACTER SET utf8mb4',
                        'utf8mb4_bin',
                        'utf8mb4_general_ci',
                    ],
                    $line
                );
            }

            fwrite($out, $line);
        }

        fclose($handle);
        fclose($out);

        rename($tmp_path, $path);
    }

    public function addMessage(array $message): array
    {
        return $this->messages[] = $message;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getTmpDir(): string
    {
        return $this->tmp_dir;
    }

    public function getDataDir(): string
    {
        return $this->getDirectory() . DIRECTORY_SEPARATOR . $this->data_dir;
    }

    protected function getDirectory(): string
    {
        $rc = new \ReflectionClass(get_class($this));
        return dirname($rc->getFileName());
    }

    protected function getClassName(): string
    {
        $rc = new \ReflectionClass(get_class($this));
        return $rc->getShortName();
    }
}
