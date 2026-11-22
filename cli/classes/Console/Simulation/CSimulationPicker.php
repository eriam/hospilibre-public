<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console\Simulation;

/** Tirage pondéré pur (sans aléa interne : le tirage $u est fourni, donc testable). */
class CSimulationPicker
{
    /**
     * @param array<int|string,float> $weights clé => poids (les poids <= 0 sont ignorés)
     * @param float                   $u       tirage uniforme dans [0, 1[
     *
     * @return int|string|null clé retenue, null si aucun poids positif
     */
    public static function weighted(array $weights, float $u)
    {
        $weights = array_filter($weights, static fn ($w) => $w > 0);
        $total   = array_sum($weights);
        if ($total <= 0) {
            return null;
        }
        $target = $u * $total;
        $acc    = 0.0;
        foreach ($weights as $key => $w) {
            $acc += $w;
            if ($target < $acc) {
                return $key;
            }
        }

        return array_key_last($weights); // garde-fou d'arrondi (u très proche de 1)
    }
}
