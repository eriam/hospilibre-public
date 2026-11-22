<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Ccam\CCodeCCAM;
use Ox\Core\CSQLDataSource;
use Throwable;

/**
 * Le dictionnaire CCAM est-il exploitable sur cette instance ?
 *
 * PENDANT DE `Cim10Dictionary`, ET LA DIFFÉRENCE COMPTE. Jusqu'ici la CCAM n'était pas nécessaire :
 * `CCodable::codes_ccam` est un champ `str` dont `checkCodeCcam()` ne contrôle que la forme, et le
 * seeder y écrivait ses codes sur n'importe quelle instance. Coder un ACTE change cela —
 * `CActeCCAM::check()` charge le code depuis `ccamV2` (`loadRefCodeCCAM()`), vérifie les
 * incompatibilités, et `store()` va chercher les modificateurs applicables. Sans dictionnaire, la
 * chaîne descend jusqu'à `CSQLDataSource::get('ccamV2')`, dont l'échec est un `E_USER_ERROR` — fatal,
 * et qu'aucun `try/catch` n'intercepte.
 *
 * D'où cette sonde, qui emprunte le seul chemin silencieux du socle : `get($dsn, $quiet = true)` rend
 * null au lieu d'interrompre. Sans dictionnaire, les interventions gardent leur cotation en clair dans
 * `codes_ccam` — ce qui ne demande rien — et n'ont simplement pas d'acte détaillé.
 *
 * Elle ne couvre pas, volontairement, le cas d'une source DÉCLARÉE MAIS INJOIGNABLE : la connexion se
 * fait dans `init()`, qui ne reçoit pas `$quiet`. Une configuration fausse doit rester bruyante.
 */
final class CcamDictionary
{
    private const SOURCE = 'ccamV2';

    /** Table des actes : une base créée mais non importée répondrait à la connexion et pas aux requêtes. */
    private const TABLE = 'p_acte';

    /** Code témoin : l'appendicectomie par cœlioscopie, l'un des actes du décor hospitalier. */
    private const PROBE = 'HHFA016';

    /** @var bool|null Mémoïsé : la sonde ouvre une connexion, et le seed la poserait par intervention. */
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

            if ($ds === null || !$ds->hasTable(self::TABLE)) {
                return false;
            }

            // load() remplace le code par « - » quand il est introuvable : l'égalité est le test.
            return CCodeCCAM::get(self::PROBE)->code === self::PROBE;
        } catch (Throwable $e) {
            return false;
        }
    }
}
