<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console\Simulation;

use Ox\Core\CMbDT;

/** Règles de déclenchement d'une crise (une seule active à la fois). */
class CCrisisControl
{
    public static function relativeDay(string $start, string $at): int
    {
        return (int) CMbDT::daysRelative(CMbDT::date(null, $start), CMbDT::date(null, $at));
    }

    /**
     * Démarre une crise à `$at`. Refuse si une crise est encore active à cette date : un conteneur
     * redémarré ne doit jamais faire repartir sa crise de zéro.
     *
     * @throws \InvalidArgumentException scénario inconnu (l'état n'est pas touché)
     * @throws \RuntimeException         crise déjà active
     */
    public static function start(CSimulationState $state, string $name, string $at): void
    {
        CCrisisScenario::fromName($name); // valide AVANT toute écriture

        $current = $state->getCrisis();
        if ($current !== null) {
            try {
                $active = CCrisisScenario::fromName($current['name'])
                    ->isActiveAt(self::relativeDay($current['start'], $at));
            } catch (\InvalidArgumentException $e) {
                $active = false; // scénario disparu : on peut le remplacer
            }
            if ($active) {
                throw new \RuntimeException(
                    "Crise « {$current['name']} » déjà active (depuis {$current['start']}) : simulate:crisis stop d'abord."
                );
            }
        }
        $state->setCrisis($name, $at);
    }
}
