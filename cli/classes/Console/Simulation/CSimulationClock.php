<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console\Simulation;

use Ox\Core\CMbDT;

/**
 * Découpage temporel pur (sans DB) de l'avance de simulation. Un intervalle
 * (from, to] est réduit à la liste ordonnée des jours calendaires D tels que
 * date(from) < D <= date(to). Le jour de `from` est exclu (déjà simulé au run
 * précédent) ; un intervalle non progressif ou intra-journée donne une liste
 * vide (no-op idempotent).
 */
class CSimulationClock
{
    /** Ramène `from` à `to - maxDays` si le trou dépasse le plafond (maxDays <= 0 : illimité). */
    public static function capFrom(string $from, string $to, int $maxDays): string
    {
        if ($maxDays <= 0) {
            return $from;
        }
        $floor = CMbDT::dateTime("-$maxDays days", $to);

        return $from < $floor ? $floor : $from;
    }

    /** Cible d'une tranche : `to`, sans dépasser `from + maxDays` (maxDays <= 0 : pas de tranche).
     *  Un processus par tranche contourne la croissance mémoire d'advance (~100 Mo par jour simulé). */
    public static function limitTarget(string $from, string $to, int $maxDays): string
    {
        if ($maxDays <= 0) {
            return $to;
        }
        $limit = CMbDT::dateTime("+$maxDays days", $from);

        return $to < $limit ? $to : $limit;
    }

    /** Valeur d'horloge après avoir simulé `day` : fin de ce jour, ou la cible si elle tombe
     *  dans ce jour. Avancer l'horloge jour par jour borne la perte d'un run interrompu
     *  à un seul jour partiel (rejoué), au lieu de tout l'intervalle. */
    public static function clockAfterDay(string $day, string $to): string
    {
        $end = "$day 23:59:59";

        return $to < $end ? $to : $end;
    }

    /**
     * @return string[] dates 'Y-m-d', ordre chronologique
     */
    public static function daySteps(string $from, string $to): array
    {
        if ($to <= $from) {
            return [];
        }

        $fromDay = CMbDT::date(null, $from); // 'Y-m-d' du jour de départ
        $toDay   = CMbDT::date(null, $to);

        $nb = CMbDT::daysRelative($fromDay, $toDay); // entier de jours calendaires
        if ($nb <= 0) {
            return [];
        }

        $steps = [];
        for ($i = 1; $i <= $nb; $i++) {
            $steps[] = CMbDT::date("+$i days", $fromDay);
        }

        return $steps;
    }
}
