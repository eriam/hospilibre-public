<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Le corpus de prose des comptes rendus : des FRAGMENTS, jamais des faits.
 *
 * LA RÈGLE QUI GOUVERNE TOUT CE FICHIER : les faits viennent du jeu de données, la prose vient du
 * corpus, et les deux ne se mélangent jamais. Un compte rendu qui narrerait « patient de 54 ans
 * opéré le 12 mars » sortirait un âge et une date de la prose, donc d'un texte écrit une fois pour
 * toutes — et ces valeurs contrediraient le dossier dès le deuxième emploi du fragment. Pire : le
 * lecteur qui les croirait y verrait un dossier qui se contredit, ce qui est plus nuisible qu'un
 * compte rendu absent.
 *
 * C'est pourquoi le chargeur REFUSE un fragment contenant un chiffre, et n'admet que les
 * substitutions d'une liste fermée. Ce n'est pas une précaution de style : c'est la seule chose qui
 * garantisse qu'un corpus produit plus tard par un modèle de langage — qui, lui, inventera des âges
 * et des dates si on le laisse faire — ne puisse pas entrer sans qu'on s'en aperçoive.
 *
 * COMPOSITION PLUTÔT QU'ÉNUMÉRATION. Vingt variantes par intervention ne demandent pas vingt textes
 * par acte : un compte rendu opératoire est assemblé d'une ouverture, d'un geste, d'un éventuel
 * paragraphe d'incident et d'une fermeture. Trois de chaque donnent déjà quatre-vingt-une
 * combinaisons par acte, et le volume à écrire reste humainement vérifiable — ce qui compte pour un
 * texte à vocation médicale.
 *
 * ⚠ CE QUE LE CORPUS LIVRÉ COUVRE, ET CE QU'IL NE COUVRE PAS. Les gestes sont écrits pour les huit
 * actes de chirurgie viscérale que le décor français emploie réellement ; les soixante-neuf autres
 * actes du catalogue retombent sur un geste GÉNÉRIQUE, qui est honnête mais peu spécifique. La
 * couverture est RAPPORTÉE par le semis, précisément pour que ce manque ne passe pas pour une
 * couverture complète. Combler les soixante-neuf est le travail du corpus produit hors ligne par un
 * modèle de langage, et c'est un ajout de DONNÉES : ce fichier-ci n'aura pas à changer.
 */
final class ReportCorpus
{
    /** Fichier du corpus, relatif à la racine du dépôt. */
    public const FILE = 'cli/resources/comptes-rendus/corpus.json';

    /** La clé des fragments qui valent pour tout acte non nommé. */
    public const GENERIC = 'generique';

    /** @var array<string, mixed>|null */
    private static $loaded;

    /** @var string|null Chemin de substitution, pour les tests. */
    private static $path;

    /**
     * Impose un autre corpus, ou rétablit celui du dépôt avec `null`.
     */
    public static function useFile(?string $path): void
    {
        self::$path   = $path;
        self::$loaded = null;
    }

    public static function isAvailable(): bool
    {
        try {
            return self::load() !== null;
        } catch (SeedException $e) {
            return false;
        }
    }

    /**
     * D'où vient la prose, et ce qu'elle couvre.
     *
     * @throws SeedException
     */
    public static function source(): string
    {
        $corpus = self::load();

        if ($corpus === null) {
            return 'AUCUN — les comptes rendus ne sont pas écrits';
        }

        $codes = array_keys($corpus['gestes']);
        $named = count(array_diff($codes, [self::GENERIC]));

        return 'corpus v' . (int)$corpus['version'] . ' (' . $named . ' acte(s) nommé(s), '
            . self::combinations() . ' combinaisons par acte)';
    }

    /**
     * Nombre de comptes rendus opératoires distincts qu'un même acte peut produire.
     *
     * RAPPORTÉ PARCE QUE C'EST LA QUESTION QU'ON SE POSE : « a-t-on assez de variété ? ». Le produit
     * des sections le dit directement, là où compter les fragments ne dirait rien.
     *
     * @throws SeedException
     */
    public static function combinations(): int
    {
        $corpus = self::load();

        if ($corpus === null) {
            return 0;
        }

        return count($corpus['ouvertures'][self::GENERIC])
            * count($corpus['gestes'][self::GENERIC])
            * count($corpus['fermetures']);
    }

    /**
     * Les gestes disponibles pour cet acte, les génériques à défaut.
     *
     * @return string[]
     * @throws SeedException
     */
    public static function gesturesFor(?string $code): array
    {
        $corpus = self::load();

        if ($corpus === null) {
            return [];
        }

        if ($code !== null && isset($corpus['gestes'][$code])) {
            return $corpus['gestes'][$code];
        }

        return $corpus['gestes'][self::GENERIC];
    }

    /**
     * Cet acte a-t-il des gestes qui lui sont propres ?
     *
     * Sert au décompte de couverture : sans lui, un semis entièrement générique serait
     * indiscernable d'un semis entièrement spécifique.
     *
     * @throws SeedException
     */
    public static function hasOwnGesture(?string $code): bool
    {
        $corpus = self::load();

        return $corpus !== null && $code !== null && isset($corpus['gestes'][$code]);
    }

    /**
     * @return string[]
     * @throws SeedException
     */
    public static function section(string $name): array
    {
        $corpus = self::load();

        if ($corpus === null) {
            return [];
        }

        if ($name === 'ouvertures') {
            return $corpus['ouvertures'][self::GENERIC];
        }

        if (!isset($corpus[$name]) || !is_array($corpus[$name])) {
            throw new SeedException("Section '{$name}' absente du corpus de comptes rendus");
        }

        return $corpus[$name];
    }

    /**
     * Une BANDE d'une section à deux niveaux — « sain », « altere », « anticoagule ».
     *
     * POURQUOI DES BANDES. Les antécédents et les traitements d'une consultation pré-anesthésique
     * dépendent du terrain : « aucun antécédent notable » et « cardiopathie ischémique stabilisée »
     * ne se tirent pas dans le même sac, sinon le dossier se contredit avec sa propre classe ASA.
     * La section porte donc un niveau de plus, et c'est l'appelant — qui connaît l'ASA — qui choisit.
     *
     * Rend la bande demandée, à défaut « sain », à défaut un tableau vide. Un repli silencieux est
     * ici le bon comportement : une bande absente doit priver d'une phrase, pas interrompre un semis.
     *
     * @return string[]
     * @throws SeedException
     */
    public static function band(string $section, string $band): array
    {
        $corpus = self::load();

        if ($corpus === null || !isset($corpus[$section]) || !is_array($corpus[$section])) {
            return [];
        }

        return $corpus[$section][$band] ?? $corpus[$section]['sain'] ?? [];
    }

    /**
     * Le paragraphe d'un incident, ou un tableau vide s'il n'y en a pas.
     *
     * ⚠ UN INCIDENT SANS PARAGRAPHE EST UN DÉFAUT, PAS UN SILENCE : le dossier porterait alors un
     * incident dans ses données et un compte rendu qui n'en dit rien. C'est pour cela que le
     * chargeur vérifie à l'ouverture que CHAQUE incident du catalogue a ses fragments, au lieu de
     * laisser la découverte au hasard d'un tirage.
     *
     * @return string[]
     * @throws SeedException
     */
    public static function incident(string $incident): array
    {
        $corpus = self::load();

        if ($corpus === null) {
            return [];
        }

        return $corpus['incidents'][$incident] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     * @throws SeedException
     */
    private static function load(): ?array
    {
        if (self::$loaded !== null) {
            return self::$loaded === [] ? null : self::$loaded;
        }

        $path = self::$path ?? dirname(__DIR__, 3) . '/' . self::FILE;

        if (!is_file($path)) {
            self::$loaded = [];

            return null;
        }

        $decoded = json_decode((string)file_get_contents($path), true);

        if (!is_array($decoded)) {
            throw new SeedException("Corpus de comptes rendus illisible : {$path}");
        }

        self::validate($decoded, $path);

        return self::$loaded = $decoded;
    }

    /**
     * Refuse un corpus qui ne peut pas produire des comptes rendus cohérents.
     *
     * VALIDÉ À L'OUVERTURE ET NON AU TIRAGE. Un corpus incomplet découvert au millième séjour aurait
     * déjà produit neuf cent quatre-vingt-dix-neuf dossiers, et il faudrait tout purger. Ici le
     * semis refuse de commencer.
     *
     * @param array<string, mixed> $corpus
     *
     * @throws SeedException
     */
    private static function validate(array $corpus, string $path): void
    {
        foreach (['version', 'placeholders', 'ouvertures', 'gestes', 'fermetures', 'incidents'] as $key) {
            if (!isset($corpus[$key])) {
                throw new SeedException("Corpus {$path} : clé '{$key}' absente");
            }
        }

        foreach (['ouvertures', 'gestes'] as $key) {
            if (!isset($corpus[$key][self::GENERIC]) || $corpus[$key][self::GENERIC] === []) {
                throw new SeedException(
                    "Corpus {$path} : la section '{$key}' doit porter des fragments '"
                    . self::GENERIC . "'. Sans eux, un acte non nommé ne produit AUCUN compte rendu,"
                    . ' et le manque serait silencieux.'
                );
            }
        }

        // CHAQUE INCIDENT DU CATALOGUE DOIT AVOIR SA PROSE. C'est la cohérence fait/récit, et c'est
        // le seul contrôle qui l'impose : un incident ajouté à SurgicalIncidents sans paragraphe
        // produirait des dossiers où les données disent une hémorragie et où le compte rendu n'en
        // parle pas.
        foreach (SurgicalIncidents::names() as $incident) {
            if (empty($corpus['incidents'][$incident])) {
                throw new SeedException(
                    "Corpus {$path} : aucun paragraphe pour l'incident '{$incident}'. Le jeu de"
                    . ' données porterait un incident dont le compte rendu ne dirait rien.'
                );
            }
        }

        $allowed = array_map('strval', (array)$corpus['placeholders']);

        foreach (self::allFragments($corpus) as $where => $fragment) {
            self::assertFactFree($fragment, $allowed, $where, $path);
        }
    }

    /**
     * @param array<string, mixed> $corpus
     *
     * @return array<string, string>
     */
    private static function allFragments(array $corpus): array
    {
        $fragments = [];

        foreach ($corpus as $section => $content) {
            if (!is_array($content)) {
                continue;
            }

            if ($section === 'placeholders') {
                continue;
            }

            foreach ($content as $key => $value) {
                if (is_string($value)) {
                    $fragments["{$section}[{$key}]"] = $value;
                    continue;
                }

                if (!is_array($value)) {
                    continue;
                }

                foreach ($value as $index => $text) {
                    if (is_string($text)) {
                        $fragments["{$section}.{$key}[{$index}]"] = $text;
                    }
                }
            }
        }

        return $fragments;
    }

    /**
     * Un fragment ne porte AUCUN fait : ni chiffre, ni substitution inconnue.
     *
     * POURQUOI LE REFUS PORTE SUR LES CHIFFRES. C'est grossier et c'est volontaire : un âge, une
     * date, une durée, un nombre de paquets, une taille de prothèse sont tous des chiffres, et il
     * n'existe pas de règle fine qui distingue le chiffre inoffensif du chiffre qui contredit le
     * dossier. Interdire la classe entière coûte quelques tournures — « en deux temps » plutôt que
     * « en 2 temps » — et rend la garantie vérifiable d'un coup d'œil.
     *
     * @param string[] $allowed
     *
     * @throws SeedException
     */
    private static function assertFactFree(string $fragment, array $allowed, string $where, string $path): void
    {
        if (preg_match('/\d/', $fragment)) {
            throw new SeedException(
                "Corpus {$path}, {$where} : le fragment contient un CHIFFRE. Les faits viennent du"
                . " jeu de données, jamais de la prose — un nombre écrit ici contredirait le dossier"
                . ' dès le deuxième emploi du fragment.'
            );
        }

        if (!preg_match_all('/\{([a-z_]+)\}/', $fragment, $matches)) {
            return;
        }

        foreach ($matches[1] as $placeholder) {
            if (!in_array($placeholder, $allowed, true)) {
                throw new SeedException(
                    "Corpus {$path}, {$where} : substitution '{{$placeholder}}' inconnue. Les"
                    . ' substitutions admises sont déclarées dans « placeholders » : '
                    . implode(', ', $allowed) . '. Une substitution non remplie apparaîtrait TELLE'
                    . ' QUELLE dans le dossier du patient.'
                );
            }
        }
    }
}
