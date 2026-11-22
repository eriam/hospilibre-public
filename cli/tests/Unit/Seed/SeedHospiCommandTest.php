<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Console\SeedHospi;
use Ox\Tests\UnitTestHospilibre;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La surface de commande : ce que l'opérateur voit et ce qu'elle refuse.
 *
 * Tout passe par `--dry-run`, donc rien n'est écrit : ce qui est testé ici est l'enveloppe —
 * validation des options, rapport, refus explicites — et non les builders, couverts ailleurs.
 *
 * Les refus sont testés autant que les succès. Une commande d'opérateur qui accepte
 * silencieusement une option qu'elle n'honore pas est pire qu'une commande qui échoue : elle fait
 * attendre une demi-heure pour un résultat qui n'arrivera pas.
 */
class SeedHospiCommandTest extends UnitTestHospilibre
{
    private function tester(): CommandTester
    {
        $application = new Application();
        $application->add(new SeedHospi());

        return new CommandTester($application->find('ox-seed:hospi'));
    }

    public function testDryRunReportsAPlausibleDataset(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(
            [
                '--profile' => 'small',
                '--seed'    => '20260925',
                '--now'     => '2026-06-15 09:00:00',
                '--dry-run' => true,
            ]
        );

        $this->assertSame(Command::SUCCESS, $status);

        $output = $tester->getDisplay();

        $this->assertStringContainsString('small', $output);
        $this->assertStringContainsString('1556', $output, 'Le profil small déduit 1556 séjours');
        $this->assertStringContainsString('dans la bande', $output, "L'occupation doit être plausible");
        $this->assertStringContainsString('AUCUNE règle métier', $output, 'La limite du dry-run doit être dite');
    }

    /**
     * Le dry-run doit produire les comptes d'un VRAI run, sinon il ne renseigne sur rien.
     */
    public function testDryRunCountsTheWholeGraph(): void
    {
        $tester = $this->tester();

        // La verbosité se passe par les OPTIONS DU TESTEUR, pas par un argument de commande :
        // « -v » dans le tableau d'entrée serait pris pour une option inconnue.
        $tester->execute(
            [
                '--profile' => 'small',
                '--now'     => '2026-06-15 09:00:00',
                '--dry-run' => true,
            ],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]
        );

        $output = $tester->getDisplay();

        foreach (['CGroups', 'CService', 'CLit', 'CPatient', 'CSejour', 'CAffectation', 'COperation'] as $class) {
            $this->assertStringContainsString($class, $output, "Le dry-run doit compter les {$class}");
        }
    }

    /**
     * Les surcharges d'échelle doivent se voir dans le rapport : c'est le seul moyen pour
     * l'opérateur de vérifier qu'il a été entendu.
     */
    public function testOverridesAreHonoured(): void
    {
        $tester = $this->tester();

        $tester->execute(
            [
                '--profile'  => 'small',
                '--sejours'  => '120',
                '--patients' => '90',
                // Les lits suivent les séjours : réduire l'activité sans réduire la capacité rend
                // l'occupation visée inatteignable, et la commande le refuse — c'est le garde-fou
                // vérifié par testAnImpossibleCapacityIsRefused.
                '--lits'     => '24',
                '--now'      => '2026-06-15 09:00:00',
                '--dry-run'  => true,
            ]
        );

        $output = preg_replace('/\s+/', ' ', $tester->getDisplay());

        $this->assertStringContainsString('120', $output);
        $this->assertStringContainsString('90', $output);
    }

    public function testUnknownProfileIsRefused(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--profile' => 'gigantesque', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Profil inconnu', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testNonNumericOverrideIsRefused(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--profile' => 'small', '--sejours' => 'beaucoup', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('entier positif', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testUnknownWriterIsRefused(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--profile' => 'small', '--writer' => 'magique', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('auto|orm|bulk', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    /**
     * Le chemin par lots est accepté depuis qu'il existe. Ce test remplace celui qui vérifiait son
     * refus : le laisser en place aurait figé une limitation levée.
     */
    public function testBulkWriterIsAccepted(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(
            [
                '--profile' => 'small',
                '--writer'  => 'bulk',
                '--now'     => '2026-06-15 09:00:00',
                '--dry-run' => true,
            ]
        );

        $this->assertSame(Command::SUCCESS, $status);
    }

    /**
     * Les gros profils ne sont plus barrés : `auto` les mène au chemin par lots, qui existe.
     *
     * La TOPOLOGIE de `large` est prise telle quelle — vingt services, dont sept chirurgicaux et
     * deux de soins continus — mais son volume est réduit par surcharge. Depuis que le nombre de
     * séjours est déduit de la capacité, un `large` complet représente 72 000 séjours et sa seule
     * simulation demande douze minutes : inacceptable dans une suite unitaire. Ce qui est vérifié
     * ici est donc le CHEMIN sur une grande topologie ; le choix du mode d'écriture par volume est
     * vérifié à part, dans SeedProfileTest.
     *
     * Les lits sont réduits AVEC les séjours, et c'est obligatoire : 600 lits exigent 471 séjours en
     * cours pour tenir l'occupation visée, que 400 séjours ne peuvent pas fournir — le garde-fou de
     * cohérence refuserait la commande, à juste titre.
     */
    public function testALargeProfileRunsThrough(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(
            [
                '--profile'  => 'large',
                '--lits'     => '40',
                '--sejours'  => '400',
                '--patients' => '200',
                '--now'      => '2026-06-15 09:00:00',
                '--dry-run'  => true,
            ]
        );

        $this->assertSame(Command::SUCCESS, $status);

        $output = preg_replace('/\s+/', ' ', $tester->getDisplay());

        $this->assertStringContainsString('large', $output);
        $this->assertStringContainsString('400', $output, 'La surcharge de séjours doit être tenue');
    }

    public function testAnInvalidReferenceInstantIsRefused(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['--profile' => 'small', '--now' => 'hier', '--dry-run' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(
            'Instant de référence invalide',
            preg_replace('/\s+/', ' ', $tester->getDisplay())
        );
    }

    /**
     * Sans graine ni instant affichés, un jeu de données de range n'est pas rejouable — et une
     * consigne d'exercice qui cite un lit précis devient fausse à la première reconstruction.
     */
    public function testTheReplayCommandIsNotPrintedForADryRun(): void
    {
        $tester = $this->tester();

        $tester->execute(['--profile' => 'small', '--now' => '2026-06-15 09:00:00', '--dry-run' => true]);

        $output = preg_replace('/\s+/', ' ', $tester->getDisplay());

        $this->assertStringNotContainsString('Jeu de données écrit', $output);
        $this->assertStringContainsString('graine', $output);
    }
}
