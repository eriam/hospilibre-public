<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\Simulation\CSimulationClock;
use Ox\Tests\UnitTestHospilibre;

class CSimulationClockTest extends UnitTestHospilibre
{
    public function testDecoupageJournalier(): void
    {
        // 3 jours pleins -> les 3 dates suivant le jour de départ.
        $steps = CSimulationClock::daySteps('2030-01-10 08:00:00', '2030-01-13 08:00:00');
        $this->assertSame(['2030-01-11', '2030-01-12', '2030-01-13'], $steps);
    }

    public function testDernierJourPartiel(): void
    {
        // Avance de 1j12h : on inclut le jour calendaire d'arrivée (partiel).
        $steps = CSimulationClock::daySteps('2030-01-10 08:00:00', '2030-01-11 20:00:00');
        $this->assertSame(['2030-01-11'], $steps);
    }

    public function testNoOpMemeJour(): void
    {
        // Avance intra-journée (< 1 jour calendaire) -> aucun pas.
        $this->assertSame([], CSimulationClock::daySteps('2030-01-10 08:00:00', '2030-01-10 20:00:00'));
    }

    public function testPlafondDeRattrapage(): void
    {
        $to = '2030-03-31 10:00:00';
        // Trou de 3 jours, plafond 14 : inchangé.
        $this->assertSame('2030-03-28 10:00:00', CSimulationClock::capFrom('2030-03-28 10:00:00', $to, 14));
        // Trou de 14 jours pile : inchangé.
        $this->assertSame('2030-03-17 10:00:00', CSimulationClock::capFrom('2030-03-17 10:00:00', $to, 14));
        // Trou de 60 jours, plafond 14 : from ramené à to - 14 j.
        $this->assertSame('2030-03-17 10:00:00', CSimulationClock::capFrom('2030-01-30 10:00:00', $to, 14));
        // Plafond 0 = illimité (semis J0 : 90 jours d'historique).
        $this->assertSame('2030-01-01 00:00:00', CSimulationClock::capFrom('2030-01-01 00:00:00', $to, 0));
    }

    public function testHorlogeJourParJour(): void
    {
        // Jour plein intermédiaire : l'horloge s'arrête en fin de jour -> le jour suivant reste à faire.
        $this->assertSame('2030-01-11 23:59:59', CSimulationClock::clockAfterDay('2030-01-11', '2030-01-13 08:00:00'));
        // Dernier jour (partiel) : l'horloge prend la cible exacte.
        $this->assertSame('2030-01-13 08:00:00', CSimulationClock::clockAfterDay('2030-01-13', '2030-01-13 08:00:00'));
        // Reprise après interruption au 11 : seuls 12 et 13 restent.
        $this->assertSame(['2030-01-12', '2030-01-13'], CSimulationClock::daySteps('2030-01-11 23:59:59', '2030-01-13 08:00:00'));
    }

    public function testTrancheBornee(): void
    {
        // Semis J0 en tranches (fuite mémoire ~100 Mo/jour, Task 0) : cible = min(cible, from + N j).
        $from = '2030-01-01 10:00:00';
        $this->assertSame('2030-01-04 10:00:00', CSimulationClock::limitTarget($from, '2030-04-01 10:00:00', 3));
        // La cible plus proche que la tranche est conservée : jamais au-delà de --until (maintenant).
        $this->assertSame('2030-01-02 09:00:00', CSimulationClock::limitTarget($from, '2030-01-02 09:00:00', 3));
        // 0 = pas de tranche.
        $this->assertSame('2030-04-01 10:00:00', CSimulationClock::limitTarget($from, '2030-04-01 10:00:00', 0));
    }

    public function testNoOpToAvantFrom(): void
    {
        // to <= from -> vide (idempotence : ré-avancer sur un intervalle couvert).
        $this->assertSame([], CSimulationClock::daySteps('2030-01-13 08:00:00', '2030-01-10 08:00:00'));
        $this->assertSame([], CSimulationClock::daySteps('2030-01-10 08:00:00', '2030-01-10 08:00:00'));
    }
}
