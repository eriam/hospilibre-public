<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Ox\Cli\Console\Simulation\CCrisisControl;
use Ox\Cli\Console\Simulation\CSimulationState;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** État de la simulation, lisible par un étudiant (et par le script de cycle de vie avec --phase). */
class SimulateStatus extends HospilibreCommand
{
    protected function configure(): void
    {
        $this
            ->setName('simulate:status')
            ->setDescription("État de l'hôpital fictif : phase, horloge, crise, occupation")
            ->addOption('phase', null, InputOption::VALUE_NONE, "N'imprimer que la phase (none|bootstrapping|catching-up|live)")
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Racine du projet', dirname(__DIR__, 3) . '/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrap(rtrim($input->getOption('path'), '/'));
        $state = new CSimulationState();
        $state->ensureTable();

        $clock = $state->getClock();
        $phase = $state->getPhase() ?? 'none';
        if ($phase === 'seeded') {
            // En retard d'au moins un jour calendaire sur l'horloge réelle = rattrapage en cours/à faire.
            $phase = $clock && CMbDT::daysRelative(CMbDT::date(null, $clock), CMbDT::date()) >= 1 ? 'catching-up' : 'live';
        }
        if ($input->getOption('phase')) {
            $output->writeln($phase);
            return self::SUCCESS;
        }

        $gid     = (int) $state->getGroupId();
        $ds      = CSQLDataSource::get('std');
        $lits    = $gid ? (int) $ds->loadResult(
            "SELECT COUNT(*) FROM lit l JOIN chambre c ON c.chambre_id = l.chambre_id JOIN service s ON s.service_id = c.service_id WHERE s.group_id = $gid AND l.annule = '0'"
        ) : 0;
        $enCours = $gid ? (int) $ds->loadResult(
            "SELECT COUNT(*) FROM sejour WHERE group_id = $gid AND annule = '0' AND entree_reelle IS NOT NULL AND sortie_reelle IS NULL"
        ) : 0;
        $crisis  = $state->getCrisis();

        $output->writeln(sprintf(
            'Phase : %s | profil : %s | horloge : %s | lits ouverts : %d | séjours en cours : %d | crise : %s',
            $phase, $state->getConfigName() ?? '-', $clock ?? '-', $lits, $enCours,
            $crisis ? sprintf('%s J%+d', $crisis['name'], CCrisisControl::relativeDay($crisis['start'], $clock ?? CMbDT::dateTime())) : 'aucune'
        ));

        return self::SUCCESS;
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
        CApp::getInstance()->startForCli('simulate:status');
    }
}
