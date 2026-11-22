<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Core\CMbDT;

/**
 * Une liste de patients dont l'IDENTITÉ est imposée, lue d'un fichier CSV.
 *
 * POURQUOI LES ANCRÉS PASSENT PAR LE SEEDER. Un scénario pédagogique — ou un test de
 * reproductibilité — a besoin de citer des patients précis : « ouvrez le dossier de MARTIN
 * Sophie ». Rien n'empêcherait de les créer à côté, par un import d'identités. Mais un import
 * d'identités ne crée QUE l'identité : pas de séjour, pas d'admission, pas de service, pas d'acte.
 * Les vingt patients dont le scénario parle seraient alors les dossiers les plus VIDES de
 * l'établissement, au milieu de milliers de dossiers complets — et le contraste les désignerait
 * mieux qu'un surligneur. Ils entrent donc avec la masse, et reçoivent le même traitement
 * clinique : identité figée, parcours généré.
 *
 * DEUX NIVEAUX D'IMPOSITION. L'identité est obligatoire et ne sera jamais réinventée. La clinique
 * est facultative : quand le scénario l'exige, un service d'accueil. Sinon le seeder décide, et
 * c'est mieux — il sait faire un séjour plausible.
 *
 * AUCUNE DÉDUPLICATION PAR LE NOM. Deux homonymes dans la liste ne sont pas une faute de saisie à
 * corriger, c'est souvent l'épreuve elle-même : un exercice peut vouloir que le rapprochement par
 * nom échoue visiblement, pour que le lien se fasse par l'IPP. Seul l'IPP est donc contrôlé en
 * unicité — et il l'est strictement, parce que deux patients qui le partagent sont la panne que
 * l'IPP existe pour empêcher.
 */
final class AnchoredCohort
{
    /** Colonnes obligatoires. */
    public const REQUIRED = ['ipp', 'nom', 'prenom', 'naissance', 'sexe'];

    /** Colonnes facultatives, reconnues si présentes. */
    public const OPTIONAL = ['service', 'motif_sejour'];

    /** @var string */
    private $path;

    /** @var array<int, array<string, string>> */
    private $anchors;

    /**
     * @param array<int, array<string, string>> $anchors
     */
    private function __construct(string $path, array $anchors)
    {
        $this->path    = $path;
        $this->anchors = $anchors;
    }

    /** Cohorte vide : aucun ancré demandé. */
    public static function none(): self
    {
        return new self('', []);
    }

    /**
     * Lit le fichier et VALIDE tout avant de rendre quoi que ce soit.
     *
     * Valider intégralement d'abord, plutôt qu'au fil du semis : une ligne fautive découverte au
     * dix-millième séjour laisserait une base à moitié peuplée, et l'opérateur devrait purger pour
     * réessayer. Le fichier est petit, le lire deux fois ne coûte rien.
     *
     * @throws SeedException
     */
    public static function fromCsv(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new SeedException("Fichier d'ancres introuvable ou illisible : {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new SeedException("Fichier d'ancres impossible à ouvrir : {$path}");
        }

        try {
            $columns = self::readHeader($handle, $path);
            $anchors = [];
            $seen    = [];
            $line_no = 1;

            while (($row = fgetcsv($handle, 0, self::delimiterOf($path))) !== false) {
                $line_no++;

                // Ligne vide en fin de fichier : fgetcsv rend [null] et non false.
                if ($row === [null] || $row === []) {
                    continue;
                }

                $anchor = self::readRow($row, $columns, $path, $line_no);

                if (isset($seen[$anchor['ipp']])) {
                    throw new SeedException(
                        "{$path} ligne {$line_no} : l'IPP '{$anchor['ipp']}' apparaît déjà ligne"
                        . " {$seen[$anchor['ipp']]}. Deux patients distincts porteraient le même"
                        . ' identifiant. (Deux HOMONYMES, en revanche, sont acceptés : seul l\'IPP'
                        . ' doit être unique.)'
                    );
                }

                $seen[$anchor['ipp']] = $line_no;
                $anchors[]            = $anchor;
            }
        } finally {
            fclose($handle);
        }

        if ($anchors === []) {
            throw new SeedException("{$path} ne contient aucune ligne de patient (en-tête seul).");
        }

        return new self($path, $anchors);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function anchors(): array
    {
        return $this->anchors;
    }

    public function count(): int
    {
        return count($this->anchors);
    }

    public function isEmpty(): bool
    {
        return $this->anchors === [];
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return string[] IPP imposés, dans l'ordre du fichier
     */
    public function ipps(): array
    {
        return array_column($this->anchors, 'ipp');
    }

    /**
     * Services d'accueil cités par le fichier, pour que l'appelant vérifie qu'ils existent.
     *
     * @return string[] slugs distincts, hors lignes sans service imposé
     */
    public function requestedServices(): array
    {
        $slugs = [];
        foreach ($this->anchors as $anchor) {
            if ($anchor['service'] !== '') {
                $slugs[$anchor['service']] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Le séparateur : virgule, sauf point-virgule si l'en-tête en contient plus.
     *
     * Un CSV exporté par un tableur français emploie le point-virgule, et il arrivera. Le deviner
     * une fois sur l'en-tête vaut mieux qu'une option de plus, et mieux qu'un fichier lu en une
     * seule colonne sans que rien ne le signale.
     */
    private static function delimiterOf(string $path): string
    {
        static $delimiters = [];

        if (!isset($delimiters[$path])) {
            $probe = fopen($path, 'r');
            $first = $probe === false ? '' : (string)fgets($probe);

            if ($probe !== false) {
                fclose($probe);
            }

            $delimiters[$path] = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        }

        return $delimiters[$path];
    }

    /**
     * @param resource $handle
     *
     * @return array<string, int> nom de colonne => indice
     * @throws SeedException
     */
    private static function readHeader($handle, string $path): array
    {
        $header = fgetcsv($handle, 0, self::delimiterOf($path));

        if ($header === false || $header === [null]) {
            throw new SeedException("{$path} est vide : la première ligne doit nommer les colonnes.");
        }

        $columns = [];
        foreach ($header as $index => $name) {
            // Le BOM d'un fichier écrit par un tableur Windows collerait au premier nom de colonne
            // et ferait manquer 'ipp' — pour une raison invisible à l'œil dans un éditeur.
            $name = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$name)));

            if ($name !== '') {
                $columns[$name] = $index;
            }
        }

        $missing = array_diff(self::REQUIRED, array_keys($columns));

        if ($missing !== []) {
            throw new SeedException(
                "{$path} : colonne(s) obligatoire(s) absente(s) : " . implode(', ', $missing)
                . '. En-tête attendu : ' . implode(',', self::REQUIRED)
                . ' (facultatif : ' . implode(',', self::OPTIONAL) . ')'
                . '. En-tête lu : ' . implode(',', array_keys($columns))
            );
        }

        return $columns;
    }

    /**
     * @param array<int, string|null> $row
     * @param array<string, int>      $columns
     *
     * @return array<string, string>
     * @throws SeedException
     */
    private static function readRow(array $row, array $columns, string $path, int $line_no): array
    {
        $value = static function (string $name) use ($row, $columns): string {
            $index = $columns[$name] ?? null;

            return $index === null ? '' : trim((string)($row[$index] ?? ''));
        };

        $anchor = [];
        foreach (self::REQUIRED as $name) {
            $anchor[$name] = $value($name);

            if ($anchor[$name] === '') {
                throw new SeedException("{$path} ligne {$line_no} : la colonne '{$name}' est vide.");
            }
        }

        foreach (self::OPTIONAL as $name) {
            $anchor[$name] = $value($name);
        }

        $anchor['naissance'] = self::readBirthDate($anchor['naissance'], $path, $line_no);
        $anchor['sexe']      = self::readSex($anchor['sexe'], $path, $line_no);

        return $anchor;
    }

    /**
     * Date de naissance, en ISO ou au format français.
     *
     * Les deux sont acceptés parce que les deux arrivent, et une date refusée sans explication est
     * la première chose qui décourage d'employer l'option.
     *
     * @throws SeedException
     */
    private static function readBirthDate(string $raw, string $path, int $line_no): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            [, $year, $month, $day] = $m;
        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m)) {
            [, $day, $month, $year] = $m;
        } else {
            throw new SeedException(
                "{$path} ligne {$line_no} : date de naissance '{$raw}' illisible"
                . ' (attendu AAAA-MM-JJ ou JJ/MM/AAAA).'
            );
        }

        if (!checkdate((int)$month, (int)$day, (int)$year)) {
            throw new SeedException("{$path} ligne {$line_no} : date de naissance '{$raw}' inexistante.");
        }

        $date = "{$year}-{$month}-{$day}";

        // Le socle refuse une naissance postérieure à l'année courante (CPatient::check) : autant
        // le dire ici, avec le numéro de ligne, plutôt que de laisser échouer l'écriture.
        if ($date > CMbDT::date()) {
            throw new SeedException("{$path} ligne {$line_no} : date de naissance '{$raw}' dans le futur.");
        }

        return $date;
    }

    /**
     * @throws SeedException
     */
    private static function readSex(string $raw, string $path, int $line_no): string
    {
        $sex = strtolower($raw);

        $known = [
            'm' => 'm', 'f' => 'f', 'i' => 'i',
            'h' => 'm', 'homme' => 'm', 'masculin' => 'm', 'male' => 'm',
            'femme' => 'f', 'feminin' => 'f', 'féminin' => 'f', 'female' => 'f',
            '1' => 'm', '2' => 'f',
        ];

        if (!isset($known[$sex])) {
            throw new SeedException(
                "{$path} ligne {$line_no} : sexe '{$raw}' non reconnu (attendu m|f|i, ou homme|femme)."
            );
        }

        return $known[$sex];
    }
}
