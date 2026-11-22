<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Ox\Cli\Console\Simulation\CCrisisControl;
use Ox\Cli\Console\Simulation\CCrisisScenario;
use Ox\Cli\Console\Simulation\CSimulationState;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Ox\Core\CMbDT;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Déclenche, arrête ou décrit la crise de l'hôpital fictif. */
class SimulateCrisis extends HospilibreCommand
{
    protected function configure(): void
    {
        $this
            ->setName('simulate:crisis')
            ->setDescription("Crise de l'hôpital fictif : start <scénario> | stop | status")
            ->addArgument('action', InputArgument::REQUIRED, 'start | stop | status')
            ->addArgument('scenario', InputArgument::OPTIONAL, 'Scénario (' . implode(', ', CCrisisScenario::NAMES) . ')')
            ->addOption('at', null, InputOption::VALUE_OPTIONAL, "Début de la crise (dateTime) ; défaut : horloge de simulation")
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Racine du projet', dirname(__DIR__, 3) . '/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->bootstrap(rtrim($input->getOption('path'), '/'));

        $state = new CSimulationState();
        $state->ensureTable();
        if (!$state->isBootstrapped()) {
            $io->error('Aucun hôpital fictif semé : pas de crise possible.');
            return self::FAILURE;
        }
        $clock = $state->getClock() ?? CMbDT::dateTime();

        switch ($input->getArgument('action')) {
            case 'start':
                $name = (string) $input->getArgument('scenario');
                $at   = $input->getOption('at') ?: $clock;
                try {
                    CCrisisControl::start($state, $name, $at);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    $io->error($e->getMessage());
                    return self::FAILURE;
                }
                $io->success("Crise « $name » démarrée le $at.");
                return self::SUCCESS;

            case 'stop':
                $state->clearCrisis();
                $io->success("Crise arrêtée : retour aux débits nominaux (la structure reste en l'état).");
                return self::SUCCESS;

            case 'status':
                $c = $state->getCrisis();
                if ($c === null) {
                    $io->writeln('Aucune crise.');
                    return self::SUCCESS;
                }
                $rel = CCrisisControl::relativeDay($c['start'], $clock);
                try {
                    $active = CCrisisScenario::fromName($c['name'])->isActiveAt($rel);
                } catch (\InvalidArgumentException $e) {
                    $active = false;
                }
                $io->writeln(sprintf('Crise %s, J%+d, %s.', $c['name'], $rel, $active ? 'active' : 'terminée'));
                return self::SUCCESS;
        }

        $io->error('Action inconnue (start | stop | status).');
        return self::FAILURE;
    }

    private function bootstrap(string $root): void
    {
        // Identique à SimulateBootstrap/SimulateAdvance : enrichir l'autoloader des
        // namespaces de modules puis startForCli(). NE PAS poser
        // CComposerScript::$is_running = true (fige les back-refs des CMediusers
        // instanciés par startForCli() -> TypeError plus loin ; cf. note Plan 1/Task 6).
        $autoloader = require "$root/vendor/autoload.php";
        foreach (glob("$root/modules/*/composer.json") as $composerFile) {
            $json = json_decode(file_get_contents($composerFile), true);
            $dir  = dirname($composerFile);
            foreach (($json['autoload']['psr-4'] ?? []) as $namespace => $path) {
                $autoloader->addPsr4($namespace, "$dir/$path");
            }
        }
        CApp::getInstance()->startForCli('simulate:crisis');
    }
}
