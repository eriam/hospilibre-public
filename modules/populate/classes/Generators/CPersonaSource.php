<?php

/**
 * @package Hospilibre\Populate
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Populate\Generators;

use Ox\Core\CSQLDataSource;
use Throwable;

/**
 * Source de personas FR réalistes pour la simulation, à partir de fichiers bundlés
 * (prénoms+sexe, noms) HORS base et de la table communes_france (datasource INSEE).
 * Tirages via mt_rand (respecte --seed). Repli synthétique si données absentes :
 * la génération de simulation ne doit jamais échouer faute de personas.
 */
class CPersonaSource
{
    /** @var array<array{prenom:string,sexe:string}>|null */
    private static ?array $prenoms = null;

    /** @var int Somme des poids des prénoms, pour le tirage proportionnel. */
    private static int $prenoms_total = 0;
    /** @var string[]|null */
    private static ?array $noms = null;

    /** @var int Somme des poids des patronymes, pour le tirage proportionnel. */
    private static int $noms_total = 0;
    /** @var array<array{commune:string,cp:string,departement:string}>|null */
    private static ?array $communes = null;
    private static ?string $resourceDir = null;

    public static function setResourceDir(string $dir): void
    {
        self::$resourceDir = $dir;
    }

    public static function resetCache(): void
    {
        self::$prenoms_total = 0;
        self::$noms_total    = 0;
        self::$prenoms = self::$noms = self::$communes = null;
    }

    private static function dir(): string
    {
        // classes/Generators/CPersonaSource.php -> racine module -> resources/personas
        return self::$resourceDir ?? dirname(__DIR__, 2) . '/resources/personas';
    }

    /** @return array{prenom:string,sexe:string} */
    /**
     * Un prénom tiré PROPORTIONNELLEMENT à sa fréquence réelle en France.
     *
     * ⚠ LE TIRAGE ÉTAIT UNIFORME, et c'était le défaut. La troisième colonne de `prenoms.csv` porte
     * le nombre de naissances cumulées depuis 1900 : « Marie » en compte 2 232 235, « Randy »
     * 1 840. Tirer uniformément sur les 5 022 prénoms leur donnait la même chance, soit mille deux
     * cents fois trop pour le second — et remplissait la cohorte de prénoms rares, souvent d'origine
     * étrangère, que l'INSEE enregistre à juste titre puisque des Français les portent, mais qui ne
     * composent pas une population française vraisemblable.
     *
     * LA SOURCE N'ÉTAIT PAS EN CAUSE, la façon d'y puiser l'était. Il a fallu le voir deux fois à
     * l'écran pour le comprendre.
     *
     * Un fichier sans troisième colonne redonne un tirage uniforme : poids 1 pour tous.
     */
    public static function randomPrenom(): array
    {
        if (self::$prenoms === null) {
            self::$prenoms = [];
            self::$prenoms_total = 0;
            foreach (self::readLines('prenoms.csv') as $line) {
                $parts = explode(';', $line);
                if (count($parts) >= 2 && $parts[0] !== '') {
                    $sexe   = ($parts[1] === 'f' || $parts[1] === 'm') ? $parts[1] : 'u';
                    $poids  = max(1, (int)trim($parts[2] ?? '1'));
                    self::$prenoms_total += $poids;
                    self::$prenoms[] = ['prenom' => $parts[0], 'sexe' => $sexe, 'poids' => $poids];
                }
            }
        }
        if (self::$prenoms === []) {
            return ['prenom' => 'Test' . mt_rand(1, 99999), 'sexe' => 'u'];
        }

        // Parcours cumulatif : la liste tient en mémoire et le semis tire quelques milliers de fois,
        // ce qui ne justifie pas l'index préconstruit qu'exigerait une recherche dichotomique.
        $tirage = mt_rand(1, max(1, self::$prenoms_total));
        $cumul  = 0;

        foreach (self::$prenoms as $persona) {
            $cumul += $persona['poids'];

            if ($tirage <= $cumul) {
                return $persona;
            }
        }

        return self::$prenoms[count(self::$prenoms) - 1];
    }

    /**
     * Un patronyme tiré PROPORTIONNELLEMENT à sa fréquence réelle.
     *
     * ⚠ LA DEUXIÈME COLONNE PORTE LE NOMBRE DE PORTEURS, et la lire comme une partie du nom
     * donnerait « Martin;31781 » dans le dossier du patient. Le tirage était par ailleurs uniforme,
     * ce qui donnait à un patronyme de la médiane (648 porteurs) la même chance qu'à « Martin »
     * (31 781) — un facteur cinquante. Moins spectaculaire que sur les prénoms, où le rapport
     * atteint mille deux cents, et faux de la même façon.
     *
     * Un fichier à une seule colonne reste lu : poids 1 pour tous, donc tirage uniforme.
     */
    public static function randomNom(): string
    {
        if (self::$noms === null) {
            self::$noms = [];
            self::$noms_total = 0;
            foreach (self::readLines('noms.csv') as $line) {
                $parts = explode(';', $line);
                $nom   = trim($parts[0] ?? '');

                if ($nom !== '') {
                    $poids = max(1, (int)trim($parts[1] ?? '1'));
                    self::$noms_total += $poids;
                    self::$noms[] = ['nom' => $nom, 'poids' => $poids];
                }
            }
        }
        if (self::$noms === []) {
            return 'Nom' . mt_rand(1, 99999);
        }

        $tirage = mt_rand(1, max(1, self::$noms_total));
        $cumul  = 0;

        foreach (self::$noms as $entree) {
            $cumul += $entree['poids'];

            if ($tirage <= $cumul) {
                return $entree['nom'];
            }
        }

        return self::$noms[count(self::$noms) - 1]['nom'];
    }

    /** @return array{commune:string,cp:string,departement:string}|null */
    public static function randomCommune(): ?array
    {
        if (self::$communes === null) {
            self::$communes = [];
            try {
                $ds = CSQLDataSource::get('INSEE', true);
                if ($ds instanceof CSQLDataSource) {
                    $rows = $ds->loadList(
                        'SELECT commune, code_postal, departement FROM communes_france '
                        . "WHERE commune != '' AND code_postal != '' ORDER BY commune_france_id LIMIT 40000"
                    );
                    foreach ($rows ?: [] as $r) {
                        self::$communes[] = [
                            'commune'     => (string) $r['commune'],
                            'cp'          => (string) $r['code_postal'],
                            'departement' => (string) $r['departement'],
                        ];
                    }
                }
            } catch (Throwable $e) {
                self::$communes = [];
            }
        }
        if (self::$communes === []) {
            return null;
        }

        return self::$communes[mt_rand(0, count(self::$communes) - 1)];
    }

    /** @return string[] lignes non vides, trim ; [] si fichier absent */
    private static function readLines(string $file): array
    {
        $path = self::dir() . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $raw === false ? [] : array_map('trim', $raw);
    }
}
