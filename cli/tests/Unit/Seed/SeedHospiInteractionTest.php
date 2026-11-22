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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LA COMMANDE NE DOIT JAMAIS POSER DE QUESTION QU'AUCUN HUMAIN NE PEUT ENTENDRE.
 *
 * Signalé par un environnement de cours (déploiement multi-conteneur) : le provisionneur lance le semis par
 * « docker exec -d », donc SANS stdin, et `ox-seed:hospi` affichait quand même
 * « Continuer ? (yes/no) [yes]: ».
 *
 * Ça n'a pas bloqué, et c'est précisément ce qui rend le défaut dangereux. Le mécanisme, vérifié
 * dans symfony/console v5.4.12 :
 *
 * - `Application::configureIO()` ne rend l'entrée non interactive que pour `--no-interaction`/`-n`
 *   ou `-q` (lignes 931 et 959). La détection de terminal présente dans les versions 3.x a été
 *   RETIRÉE : sans `-n`, l'entrée reste donc « interactive » même quand stdin est /dev/null.
 * - `QuestionHelper::ask()` lit alors un flux mort, attrape la `MissingInputException` et se rabat
 *   sur le DÉFAUT de la question (lignes 76-84) — mais il la RELANCE si la question n'a pas de
 *   défaut.
 *
 * Le semis du range n'est donc passé que parce que `confirm('Continuer ?', true)` avait un défaut
 * à `true`. Changer ce défaut, ou ajouter une question qui n'en a pas, suffirait à faire échouer un
 * semis automatisé — sur une exception « Aborted. » sans rapport apparent avec la cause.
 *
 * On ne veut pas dépendre de ce rattrapage. La règle testée ici est donc explicite : on ne demande
 * que si l'entrée est interactive ET que stdin est un terminal.
 */
class SeedHospiInteractionTest extends UnitTestHospilibre
{
    /**
     * Expose la règle de décision et permet de simuler le terminal, qu'un test ne peut pas créer.
     */
    private function command(bool $stdin_is_terminal): SeedHospi
    {
        return new class($stdin_is_terminal) extends SeedHospi {
            /** @var bool */
            private $fake_terminal;

            public function __construct(bool $fake_terminal)
            {
                $this->fake_terminal = $fake_terminal;

                parent::__construct();
            }

            protected function stdinIsTerminal(): bool
            {
                return $this->fake_terminal;
            }

            public function exposeShouldAsk(InputInterface $input): bool
            {
                return $this->shouldAskForConfirmation($input);
            }
        };
    }

    private function interactiveInput(): ArrayInput
    {
        $input = new ArrayInput([]);
        $input->setInteractive(true);

        return $input;
    }

    public function testItAsksWhenSomeoneIsThereToAnswer(): void
    {
        $command = $this->command(true);

        $this->assertTrue(
            $command->exposeShouldAsk($this->interactiveInput()),
            "Sur un vrai terminal, la confirmation reste due : c'est le garde-fou contre un semis"
            . ' lancé sur une instance en service.'
        );
    }

    public function testItDoesNotAskWhenStdinIsNotATerminal(): void
    {
        $command = $this->command(false);

        $this->assertFalse(
            $command->exposeShouldAsk($this->interactiveInput()),
            'Sans terminal, personne ne peut répondre : poser la question revient à dépendre du'
            . " défaut de l'invite, ce que ce correctif supprime."
        );
    }

    public function testNoInteractionIsHonouredEvenOnATerminal(): void
    {
        $command = $this->command(true);

        $input = new ArrayInput([]);
        $input->setInteractive(false);

        $this->assertFalse(
            $command->exposeShouldAsk($input),
            '--no-interaction doit être respecté quoi qu il arrive, terminal ou pas.'
        );
    }

    /**
     * Le CÂBLAGE, et pas seulement la règle : la commande doit rendre l'entrée non interactive dès
     * son entrée dans execute(), avant toute branche. Sans ça, une question ajoutée plus tard
     * ailleurs dans la commande retomberait dans le piège — c'est le scénario annoncé par cet utilisateur.
     *
     * Vérifié sous phpunit, où stdin n'est pas un terminal, et avec --dry-run pour ne rien écrire.
     */
    public function testExecuteMarksTheInputNonInteractiveWhenThereIsNoTerminal(): void
    {
        $application = new Application();
        $application->add(new SeedHospi());
        $tester = new CommandTester($application->find('ox-seed:hospi'));

        $tester->execute(['--profile' => 'small', '--dry-run' => true]);

        $this->assertFalse(
            $tester->getInput()->isInteractive(),
            'execute() doit avoir basculé l entrée en non interactive, stdin n étant pas un terminal.'
        );
    }
}
