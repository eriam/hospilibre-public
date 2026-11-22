<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Cim10\CCodeCIM10;
use Ox\Core\CSQLDataSource;
use Throwable;

/**
 * Le dictionnaire CIM-10 est-il exploitable sur cette instance ?
 *
 * POURQUOI CETTE SONDE EXISTE. Les deux nomenclatures ne se comportent pas du tout pareil :
 *
 * - un code CCAM va dans `CCodable::codes_ccam`, un champ `str` que `checkCodeCcam()` ne contrôle que
 *   sur la FORME. Aucun dictionnaire n'est requis, et le seeder en écrit toujours ;
 * - un code CIM-10 va dans `CSejour::DP`, et `CSejour::check()` appelle `CCodeCIM10::get()` pour
 *   vérifier son EXISTENCE. Sans la datasource `cim10` déclarée, cet appel descend jusqu'à
 *   `CSQLDataSource::get('cim10')` qui déclenche un `E_USER_ERROR` — c'est-à-dire qu'il TUE le
 *   processus, et ni `@` ni `try/catch` n'y changent quoi que ce soit.
 *
 * Écrire un DP sans savoir revient donc à faire échouer le seed entier sur une instance sans
 * dictionnaire. La sonde répond avant, en passant par le seul chemin silencieux que le socle offre :
 * `CSQLDataSource::get($dsn, $quiet = true)`, qui rend null au lieu d'interrompre.
 *
 * CE QU'ELLE NE PEUT PAS COUVRIR, et c'est assumé : une datasource DÉCLARÉE mais injoignable. La
 * connexion se fait dans `init()`, qui ne reçoit pas `$quiet` et dont l'échec est un `E_USER_ERROR`. Une
 * base déclarée et cassée reste donc fatale — ce qui est le comportement souhaitable pour une
 * configuration fausse. Ce que la sonde traite est le cas courant : la base n'est pas là du tout.
 */
final class Cim10Dictionary
{
    private const SOURCE = 'cim10';

    /**
     * Code témoin, choisi parce qu'il est dans les trois versions du dictionnaire et qu'il est
     * incontestable : la pneumopathie sans précision.
     */
    private const PROBE = 'J189';

    /**
     * Table à trouver pour chaque version. Une base créée mais non importée répondrait à la connexion
     * et échouerait à la première requête — sur `fetchArray(false)`, donc fatalement.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'oms'  => 'master',
        'atih' => 'codes_atih',
        'gm'   => 'codes_gm',
    ];

    /** @var bool|null Mémoïsé : la sonde ouvre une connexion, et le seed la poserait par séjour. */
    private static $available;

    public static function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        return self::$available = self::probe();
    }

    /** Les tests montent et démontent des instances ; la mémoïsation ne doit pas leur survivre. */
    public static function forget(): void
    {
        self::$available = null;
    }

    private static function probe(): bool
    {
        try {
            $ds = CSQLDataSource::get(self::SOURCE, true);

            if ($ds === null) {
                return false;
            }

            $version = (string)CCodeCIM10::getVersion();
            $table   = self::TABLES[$version] ?? null;

            if ($table === null || !$ds->hasTable($table)) {
                return false;
            }

            return (bool)CCodeCIM10::get(self::PROBE)->exist;
        } catch (Throwable $e) {
            return false;
        }
    }
}
