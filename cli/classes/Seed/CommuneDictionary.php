<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Cli\Seed\Decor\GeographySource;
use Ox\Core\CSQLDataSource;

/**
 * Dictionnaire des communes françaises : 36 742 communes avec code postal, département, région,
 * population et coordonnées.
 *
 * POURQUOI UN VRAI DICTIONNAIRE. Les communes étaient inventées, quinze noms composés à la main.
 * C'était défendable pour ne pas exposer de lieu réel, mais cela rendait deux choses impossibles :
 * implanter l'établissement quelque part, et faire venir ses patients d'AUTOUR de lui. Or un hôpital
 * recrute dans son territoire — c'est même la première chose qu'un tableau de provenance montre.
 *
 * DEUX SOURCES, dans cet ordre :
 *
 * 1. la table `communes_france` de la datasource `INSEE`, celle du module `openData`. C'est le
 *    dictionnaire canonique de l'application, et c'est lui qu'il faut utiliser quand il est là ;
 * 2. le fichier `communes_france_2013.zip` livré dans les ressources de ce même module, qui contient
 *    exactement les mêmes données. Il permet au seed de fonctionner sur une installation fraîche,
 *    où la datasource n'est pas déclarée et où le module n'est pas monté.
 *
 * LA LECTURE PASSE PAR LA DATASOURCE ET NON PAR `CCommuneFrance`, et c'est un choix mesuré :
 * `loadList()` sur ce modèle rend **NULL** quand le module `openData` est inactif, sans erreur ni
 * avertissement — `_ref_module` est nul et la lecture est abandonnée en silence. Vérifié sur cette
 * instance : la requête brute rend 36 742 lignes là où le modèle n'en rend aucune. Un seed qui
 * dépendrait de l'activité d'un module optionnel produirait donc un jeu de données silencieusement
 * dégradé, ce que ModuleGuard existe précisément pour empêcher.
 */
final class CommuneDictionary implements GeographySource
{
    /** Datasource et table du dictionnaire canonique. */
    private const DSN = 'INSEE';

    private const TABLE = 'communes_france';

    /** Repli : les mêmes données, livrées dans les ressources du module. */
    private const ARCHIVE = 'modules/openData/resources/communes_france_2013.zip';

    private const ARCHIVE_ENTRY = 'communes_france_2013.csv';

    /** Colonnes du fichier de ressources qui nous intéressent. */
    private const ARCHIVE_COLUMNS = [
        'insee'            => 'insee_com',
        'commune'          => 'nom_comm',
        'code_postal'      => 'postal_code',
        'code_departement' => 'code_dept',
        'code_region'      => 'code_reg',
        'departement'      => 'nom_dept',
        'population'       => 'population',
    ];

    /**
     * Population minimale d'une commune pouvant accueillir l'établissement.
     *
     * Un centre hospitalier ne s'implante pas dans un hameau de trois cents habitants. Le seuil est
     * volontairement bas — une sous-préfecture de quinze mille habitants a son hôpital — mais il
     * écarte les 36 000 communes qui n'en ont pas.
     */
    private const MIN_HOSPITAL_POPULATION = 15000;

    /**
     * Les arrondissements ne portent pas l'adresse d'un établissement.
     *
     * Le jeu de données découpe Paris, Lyon et Marseille en arrondissements et ne contient AUCUNE
     * ligne pour la ville elle-même. « Centre Hospitalier — Marseille-8e-Arrondissement » ne
     * ressemble à rien ; ces quarante-cinq lignes sont donc écartées de l'implantation, mais restent
     * des lieux de résidence parfaitement valables pour les patients.
     */
    private const NOT_A_HOSPITAL_SEAT = 'ARRONDISSEMENT';

    /**
     * Part des patients venant du DÉPARTEMENT de l'établissement, en pourcentage. Le reste vient de
     * la région : on se fait hospitaliser près de chez soi, mais pas toujours dans son département.
     */
    private const LOCAL_SHARE = 85;

    /** @var array<int, array<string, string>>|null Communes chargées, une fois par processus */
    private static $communes;

    /** @var string|null D'où elles viennent */
    private static $source;

    /**
     * @var string|null Source imposée : 'database' ou 'archive'.
     *
     * Pour les tests, qui doivent pouvoir vérifier que les DEUX sources portent les mêmes données —
     * sans quoi l'une des deux pourrait dériver sans que rien ne le signale. Le seed ne s'en sert
     * jamais : il prend la meilleure source disponible.
     */
    private static $forced;

    /**
     * Vide le cache. Pour les tests, qui doivent pouvoir vérifier les deux sources.
     */
    public static function reset(): void
    {
        self::$communes = null;
        self::$source   = null;
        self::$forced   = null;
    }

    /**
     * Impose une source : 'database', 'archive', ou null pour la meilleure disponible.
     */
    public static function useSource(?string $source): void
    {
        self::reset();
        self::$forced = $source;
    }

    /**
     * D'où vient le dictionnaire, ou pourquoi il n'y en a pas.
     *
     * Rapporté par la commande : sans dictionnaire, les communes retombent sur une liste inventée, et
     * l'opérateur doit le savoir plutôt que de le découvrir en lisant les adresses.
     */
    public function describe(): string
    {
        $this->load();

        return (string)self::$source;
    }

    public function isAvailable(): bool
    {
        $this->load();

        return self::$communes !== [];
    }

    /**
     * Commune d'implantation de l'établissement : tirée parmi celles qui sont assez peuplées pour en
     * avoir un, au prorata de leur population.
     *
     * @return array<string, string>|null
     * @throws SeedException
     */
    public function pickHospitalTown(SeedRandom $random): ?array
    {
        $this->load();

        $candidates = [];
        foreach (self::$communes as $index => $commune) {
            if ((int)$commune['population'] < self::MIN_HOSPITAL_POPULATION) {
                continue;
            }

            if (strpos($commune['commune'], self::NOT_A_HOSPITAL_SEAT) !== false) {
                continue;
            }

            $candidates[$index] = (int)$commune['population'];
        }

        if ($candidates === []) {
            return null;
        }

        return self::$communes[$random->pickWeighted($candidates)];
    }

    /**
     * Commune de résidence d'un patient, cohérente avec l'implantation de l'établissement.
     *
     * Au prorata de la population : la plupart des patients viennent de la ville-centre, quelques-uns
     * des villages autour. Tirer uniformément parmi les communes d'un département donnerait l'inverse
     * — un département compte des centaines de villages pour une seule grande ville.
     *
     * @param array<string, string> $hospital
     *
     * @return array<string, string>|null
     * @throws SeedException
     */
    public function pickPatientTown(SeedRandom $random, array $hospital): ?array
    {
        $this->load();

        if (self::$communes === []) {
            return null;
        }

        $local = $random->chanceInThousand(self::LOCAL_SHARE * 10);
        $key   = $local ? 'code_departement' : 'code_region';

        $candidates = [];
        foreach ($this->scope($key, $hospital[$key]) as $index => $commune) {
            // Une commune sans population connue reste tirable, avec un poids d'un habitant : elle
            // existe, et l'écarter créerait des trous dans la carte.
            $candidates[$index] = max(1, (int)$commune['population']);
        }

        if ($candidates === []) {
            return null;
        }

        return self::$communes[$random->pickWeighted($candidates)];
    }

    /**
     * Répare ce que le jeu de données a d'irrégulier.
     *
     * Les noms de Marseille y sont écrits avec des tirets doublés — « MARSEILLE--8E--ARRONDISSEMENT »
     * — là où Paris n'en a qu'un. C'est une bizarrerie de la source, pas de la lecture, et elle se
     * verrait telle quelle dans l'adresse d'un patient.
     *
     * @param array<string, string> $commune
     *
     * @return array<string, string>
     */
    private static function normalise(array $commune): array
    {
        // Tout en chaînes : la base rend des entiers pour la population, le fichier des chaînes, et
        // les deux sources doivent être indiscernables — c'est ce que vérifie le test d'équivalence.
        foreach ($commune as $key => $value) {
            $commune[$key] = (string)$value;
        }

        $commune['commune'] = (string)preg_replace('/[-\s]{2,}/', '-', trim($commune['commune']));

        return $commune;
    }

    /**
     * Communes partageant la valeur demandée, indexées comme dans le dictionnaire.
     *
     * @return array<int, array<string, string>>
     */
    private function scope(string $key, string $value): array
    {
        $scope = [];

        foreach (self::$communes as $index => $commune) {
            if ($commune[$key] === $value) {
                $scope[$index] = $commune;
            }
        }

        return $scope;
    }

    /**
     * Charge le dictionnaire, une fois par processus.
     *
     * @throws SeedException
     */
    private function load(): void
    {
        if (self::$communes !== null) {
            return;
        }

        $communes = self::$forced === 'archive' ? null : $this->loadFromDatabase();

        if ($communes !== null) {
            self::$communes = $communes;
            self::$source   = 'datasource ' . self::DSN . ' (' . count($communes) . ' communes)';

            return;
        }

        $communes = self::$forced === 'database' ? null : $this->loadFromArchive();

        if ($communes !== null) {
            self::$communes = $communes;
            self::$source   = self::ARCHIVE . ' (' . count($communes) . ' communes)';

            return;
        }

        self::$communes = [];
        self::$source   = 'aucun dictionnaire : communes inventées';
    }

    /**
     * Le dictionnaire canonique, s'il est joignable.
     *
     * L'ORDRE EST IMPOSÉ par le code INSEE : sans `ORDER BY`, le moteur reste libre de rendre les
     * lignes dans l'ordre qui l'arrange, et le jeu de données cesserait d'être reproductible d'une
     * base à l'autre à graine égale.
     *
     * @return array<int, array<string, string>>|null
     */
    private function loadFromDatabase(): ?array
    {
        $ds = CSQLDataSource::get(self::DSN, true);

        if (!$ds) {
            return null;
        }

        if (!$ds->hasTable(self::TABLE)) {
            return null;
        }

        // LA TABLE EXISTE EN DEUX VERSIONS, ET L'UNE NE SUFFIT PAS.
        //
        // L'archive INSEE livrée (`modules/dPpatients/INSEE/communes_france.tar.gz`) crée une table à
        // huit colonnes, sans `population`. C'est le module `openData` qui en crée une plus riche —
        // treize colonnes, dont `population`, `statut` et la géométrie (`CSetupOpenData`). Or `openData`
        // ne figure dans AUCUN profil d'`ox-modules:setup` : une instance fraîchement installée n'a donc
        // que la version pauvre.
        //
        // Sans `population`, `pickHospitalTown()` ne peut plus écarter les villages — elle choisit une
        // ville assez grande pour porter un hôpital, au prorata des habitants. Plutôt que de dégrader ce
        // choix en silence, on se rabat sur l'ARCHIVE du dépôt, qui porte la population : le
        // dictionnaire reste complet et le rapport dit d'où il vient.
        //
        // Trouvé en installant de zéro sur une machine vierge : le seeder s'arrêtait sur
        // « Unknown column 'population' in 'SELECT' ».
        if (!$this->hasPopulationColumn($ds)) {
            return null;
        }

        $rows = $ds->loadList(
            'SELECT INSEE AS insee, commune, code_postal, code_departement, code_region, departement,'
            . ' population FROM ' . self::TABLE . ' ORDER BY INSEE'
        );

        if ($rows === null || $rows === []) {
            return null;
        }

        return array_values(array_map([self::class, 'normalise'], $rows));
    }

    /**
     * La table porte-t-elle la colonne `population` ?
     *
     * Interrogé par `information_schema` plutôt qu'en tentant la requête : un `SELECT` sur une colonne
     * absente lève un avertissement que le gestionnaire d'erreurs de Symfony promeut en exception, ce
     * qui interromprait le seed au lieu de le faire basculer sur l'archive.
     */
    private function hasPopulationColumn(CSQLDataSource $ds): bool
    {
        $found = $ds->loadList(
            "SELECT COLUMN_NAME FROM information_schema.columns"
            . " WHERE table_schema = DATABASE() AND table_name = '" . self::TABLE . "'"
            . " AND COLUMN_NAME = 'population'"
        );

        return is_array($found) && $found !== [];
    }

    /**
     * Le fichier de ressources, lu directement dans son archive.
     *
     * @return array<int, array<string, string>>|null
     */
    private function loadFromArchive(): ?array
    {
        $path = dirname(__DIR__, 3) . '/' . self::ARCHIVE;

        if (!is_file($path)) {
            return null;
        }

        $handle = @fopen('zip://' . $path . '#' . self::ARCHIVE_ENTRY, 'rb');

        if ($handle === false) {
            return null;
        }

        $header = fgetcsv($handle, 0, ';');

        if ($header === false || $header === null) {
            fclose($handle);

            return null;
        }

        $indexes = [];
        foreach (self::ARCHIVE_COLUMNS as $name => $column) {
            $index = array_search($column, $header, true);

            if ($index === false) {
                fclose($handle);

                return null;
            }

            $indexes[$name] = (int)$index;
        }

        $communes = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $commune = [];
            foreach ($indexes as $name => $index) {
                $commune[$name] = trim((string)($row[$index] ?? ''));
            }

            if ($commune['commune'] === '' || $commune['code_departement'] === '') {
                continue;
            }

            // Une commune peut porter plusieurs codes postaux, séparés par des barres obliques : on
            // garde le premier, comme le fait le dictionnaire en base — les autres vivent dans une
            // table dédiée qui ne nous sert à rien ici.
            $commune['code_postal'] = explode('/', $commune['code_postal'])[0];

            // La population du fichier est exprimée en MILLIERS d'habitants, avec une décimale.
            $commune['population'] = (string)(int)round((float)$commune['population'] * 1000);

            $communes[] = self::normalise($commune);
        }

        fclose($handle);

        if ($communes === []) {
            return null;
        }

        // TRI PAR CODE INSEE, comme la requête en base. Sans lui les deux sources rendraient les mêmes
        // communes dans un ordre différent, et le tirage — qui porte sur un rang — désignerait une
        // autre ville selon l'installation, à graine égale. Le test d'équivalence l'a montré.
        usort(
            $communes,
            static function (array $left, array $right): int {
                return strcmp($left['insee'], $right['insee']);
            }
        );

        return $communes;
    }
}
