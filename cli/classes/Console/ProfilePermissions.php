<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Console;

use Exception;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Ox\Core\CSQLDataSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Les droits d'accès aux modules, par profil métier.
 *
 * POURQUOI CETTE GRILLE N'EXISTAIT PAS, et pourquoi la poser ne casse rien. Mesuré sur une
 * installation neuve : CINQ permissions de module pour 42 modules et 12 profils, dont deux
 * seulement sur un profil-modèle — `Direction` et `SI`, chacune en accès total. Et la cascade de
 * CPermModule::getInfoModule() est « module spécifique → all → DENY » : l'ABSENCE de permission
 * vaut REFUS. Les dix autres profils ne voient donc rien aujourd'hui, et accorder des droits ne
 * peut retirer aucun accès existant.
 *
 * L'HÉRITAGE EST LE MÉCANISME, et il est vérifié : CPermModule::buildUser() charge d'abord les
 * permissions du `profile_id`, puis celles propres à l'utilisateur, et fusionne — les secondes
 * surchargeant les premières. Poser la grille sur les DOUZE modèles suffit donc à doter les
 * centaines d'agents que le semis rattache à ces profils, et un droit particulier reste possible
 * agent par agent sans défaire la grille.
 *
 * CE QUE CETTE GRILLE N'EST PAS. Elle ne décide pas QUELS PATIENTS un agent voit : cet axe-là est
 * porté par l'unité d'exercice et ne figure dans aucune colonne de `perm_module`. Une infirmière de
 * réanimation et une infirmière de cardiologie ont les MÊMES modules et des patients différents ;
 * confondre les deux axes obligerait à redéclarer la grille pour chaque unité, et toute création de
 * service rouvrirait le chantier.
 *
 * ⚠ C'EST UNE PROPOSITION DE DÉPART, PAS UNE VÉRITÉ MÉTIER. Les besoins d'un cadre de santé ne se
 * déduisent pas du code ; cette grille est pré-remplie par famille pour être CORRIGÉE à l'écran,
 * profil par profil, et c'est pour ça que la commande est rejouable et qu'elle sait simuler.
 */
class ProfilePermissions extends HospilibreCommand
{
    /** Refus : l'absence de ligne vaut déjà cela, on ne l'écrit donc jamais. */
    private const DENY = '0';

    /** Lecture. */
    private const READ = '1';

    /** Écriture. */
    private const WRITE = '2';

    /**
     * La grille, par profil-modèle.
     *
     * `write` donne l'écriture, `read` la lecture seule, et tout module absent des deux listes
     * reste refusé par défaut — ce qui rend la grille lisible : on y voit ce qui est accordé, pas
     * l'immense majorité qui ne l'est pas.
     *
     * `Direction` et `SI` NE FIGURENT PAS ICI DÉLIBÉRÉMENT : ils portent déjà une permission « tous
     * modules » posée par le setup, et cette commande ne touche que les profils qu'elle déclare.
     * Les écraser par une liste explicite reviendrait à décider à la place de l'existant.
     */
    private const GRID = [
        // --- Les admissions ----------------------------------------------------------------
        // « Les admissions ne voient que le module admission. » Pris au mot, cela les empêcherait
        // de créer une identité patient, qui est précisément leur métier : le bureau des entrées
        // ouvre le dossier administratif. On accorde donc l'admission ET l'identité, et la lecture
        // de l'hospitalisation pour voir où placer le patient. Restreindre davantage tient à une
        // ligne, et c'est un arbitrage d'exploitation.
        'Accueil' => [
            'write' => ['dPadmissions', 'dPpatients'],
            'read'  => ['dPhospi', 'dPetablissement', 'dPplanningOp', 'messagerie'],
        ],

        // --- Le soin ----------------------------------------------------------------------
        // Le dossier de soins, les constantes, le mouvement du patient. La lecture s'étend à ce
        // qu'une infirmière doit CONSULTER pour exercer — prescriptions, résultats, comptes rendus,
        // programme opératoire — sans pouvoir le modifier.
        'Hospitalisation' => [
            'write' => ['soins', 'dPhospi', 'monitoringPatient'],
            'read'  => [
                'dPpatients', 'dPcabinet', 'dPcompteRendu', 'dPlabo', 'dPplanningOp', 'dPfiles',
                'dPurgences', 'messagerie', 'dPrepas',
            ],
        ],

        // --- L'encadrement soignant --------------------------------------------------------
        // Le cadre ajoute au soin l'organisation : planning du bloc, personnel, astreintes,
        // ressources. C'est ce qui le distingue d'un infirmier, et c'est organisationnel et non
        // clinique.
        'Survbloc' => [
            'write' => ['soins', 'dPhospi', 'dPbloc', 'dPsalleOp', 'dPplanningOp', 'dPpersonnel', 'astreintes'],
            'read'  => ['dPpatients', 'dPcabinet', 'dPcompteRendu', 'dPlabo', 'dPressources', 'messagerie'],
        ],
        'Bloc' => [
            'write' => ['dPbloc', 'dPsalleOp', 'dPplanningOp', 'dPpersonnel', 'astreintes'],
            'read'  => ['dPpatients', 'soins', 'dPhospi', 'dPcompteRendu', 'dPressources', 'messagerie'],
        ],

        // --- Le médical --------------------------------------------------------------------
        // Prescrire, opérer, coter, rédiger. Les deux profils praticiens partagent l'essentiel ;
        // l'anesthésie se distingue par la consultation pré-anesthésique et la salle de réveil,
        // que `dPcabinet` et `dPsalleOp` portent.
        'Chirurgie' => [
            'write' => [
                'dPplanningOp', 'dPbloc', 'dPsalleOp', 'dPcabinet', 'dPcompteRendu', 'soins',
                'dPhospi', 'dPpatients', 'dPccam', 'dPcim10',
            ],
            'read'  => ['dPlabo', 'dicom', 'dPfiles', 'dPpmsi', 'dPurgences', 'messagerie', 'monitoringPatient'],
        ],
        'Anesthesie' => [
            'write' => [
                'dPcabinet', 'dPsalleOp', 'dPbloc', 'dPplanningOp', 'dPcompteRendu', 'soins',
                'dPhospi', 'dPccam', 'monitoringPatient',
            ],
            'read'  => ['dPpatients', 'dPlabo', 'dicom', 'dPfiles', 'dPurgences', 'messagerie'],
        ],

        // --- Le secrétariat médical --------------------------------------------------------
        // Rendez-vous, courriers, pièces du dossier. Pas de prescription, pas de cotation.
        'Secretariat' => [
            'write' => ['dPcabinet', 'dPcompteRendu', 'dPpatients', 'dPfiles', 'messagerie'],
            'read'  => ['dPplanningOp', 'dPhospi', 'dPadmissions', 'dPlabo'],
        ],

        // --- La facturation ----------------------------------------------------------------
        // Facturer suppose de LIRE l'activité — séjours, actes, groupage — et de n'en modifier que
        // la facture. D'où une seule écriture et de larges lectures.
        'Facturation' => [
            'write' => ['dPfacturation'],
            'read'  => ['dPpatients', 'dPhospi', 'dPpmsi', 'dPplanningOp', 'dPccam', 'dPcabinet', 'dPadmissions'],
        ],

        // --- L'information médicale --------------------------------------------------------
        // Le PMSI code et groupe : il écrit dans son module et dans les nomenclatures, et lit tout
        // le dossier pour le faire.
        'PMSI' => [
            'write' => ['dPpmsi', 'dPcim10', 'dPccam'],
            'read'  => ['dPpatients', 'dPhospi', 'dPcompteRendu', 'dPurgences', 'soins', 'dPplanningOp'],
        ],

        // --- La qualité et la gestion des risques ------------------------------------------
        // Écrit dans son module, LIT le reste : une cellule qualité analyse des dossiers sans les
        // modifier, et cette asymétrie est le cœur de son indépendance.
        'Qualite' => [
            'write' => ['dPqualite'],
            'read'  => [
                'dPpatients', 'dPhospi', 'soins', 'dPcompteRendu', 'dPpmsi', 'dPurgences',
                'dPplanningOp', 'dPfacturation',
            ],
        ],
    ];

    /** @var SymfonyStyle */
    private $io;

    /** @var string */
    private $root;

    protected function configure(): void
    {
        $this
            ->setName('ox-perms:profiles')
            ->setDescription("Pose les droits d'accès aux modules sur les profils métiers")
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Racine de la copie de travail',
                dirname(__DIR__, 3)
            )
            ->addOption(
                'profile',
                null,
                InputOption::VALUE_OPTIONAL,
                'Ne traiter que ce profil (son nom de modèle)'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre la grille sans rien écrire');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io   = new SymfonyStyle($input, $output);
        $this->root = rtrim((string)$input->getOption('path'), '/');
        $dry_run    = (bool)$input->getOption('dry-run');
        $only       = $input->getOption('profile');

        $this->bootstrap();

        $this->io->title('Droits de module par profil métier');

        $datasource = CSQLDataSource::get('std');

        if (!$datasource) {
            $this->io->error("La datasource « std » n'est pas joignable.");

            return self::FAILURE;
        }

        $modules  = $this->moduleIds($datasource);
        $profiles = $this->profileIds($datasource);

        if ($profiles === []) {
            $this->io->warning('Aucun profil-modèle dans cette base (users.template = \'1\').');

            return self::FAILURE;
        }

        $rows    = [];
        $unknown = [];
        $applied = 0;

        foreach (self::GRID as $profile => $grants) {
            if ($only !== null && $profile !== $only) {
                continue;
            }

            if (!isset($profiles[$profile])) {
                $rows[] = [$profile, '—', '—', 'profil-modèle ABSENT de cette base'];

                continue;
            }

            $granted = [];

            foreach ([self::WRITE => $grants['write'] ?? [], self::READ => $grants['read'] ?? []] as $level => $names) {
                foreach ($names as $name) {
                    if (!isset($modules[$name])) {
                        // DIT, et non ignoré : un module absent de la grille appliquée est un droit
                        // qu'on croit avoir accordé et qui n'existe pas.
                        $unknown[$name] = true;

                        continue;
                    }

                    $granted[$modules[$name]] = $level;
                }
            }

            $rows[] = [
                $profile,
                count($grants['write'] ?? []) . ' en écriture',
                count($grants['read'] ?? []) . ' en lecture',
                $dry_run ? '(simulation)' : $this->apply($datasource, (int)$profiles[$profile], $granted),
            ];

            if (!$dry_run) {
                $applied++;
            }
        }

        $this->io->table(['profil', 'écriture', 'lecture', 'état'], $rows);

        if ($unknown !== []) {
            $this->io->warning(
                'Modules nommés dans la grille et INTROUVABLES dans cette base : '
                . implode(', ', array_keys($unknown))
                . ". Les droits correspondants n'ont pas été posés."
            );
        }

        $this->io->writeln(
            sprintf(
                'Tout module absent de la grille reste REFUSÉ : la cascade de CPermModule est'
                . ' « module → all → DENY ». %d module(s) actif(s) dans cette base.',
                count($modules)
            )
        );

        // Ce que la grille ne fait pas est dit ICI, parce que c'est la question qui vient ensuite.
        $this->io->writeln(
            "Cette grille décide QUELS MODULES, pas QUELS PATIENTS : le périmètre par unité"
            . " d'exercice est un autre axe, et il n'est pas encore posé."
        );

        if ($dry_run) {
            $this->io->note('Simulation : rien n\'a été écrit.');

            return self::SUCCESS;
        }

        $this->io->success(sprintf('%d profil(s) traité(s).', $applied));

        return self::SUCCESS;
    }

    /**
     * Remplace les droits de module d'un profil, et VÉRIFIE le compte écrit.
     *
     * REMPLACE plutôt qu'ajoute : la commande doit être rejouable, et une grille corrigée doit
     * effacer ce qu'elle corrige. On ne supprime QUE les lignes du profil traité — les profils hors
     * grille, `Direction` et `SI`, gardent les leurs.
     */
    private function apply(CSQLDataSource $datasource, int $profile_id, array $granted): string
    {
        try {
            $datasource->exec(
                $datasource->prepare('DELETE FROM perm_module WHERE user_id = %1', $profile_id)
            );

            foreach ($granted as $mod_id => $level) {
                // Les marqueurs sont NUMÉROTÉS : un « % » nu est un alias du PREMIER argument, si
                // bien que plusieurs marqueurs nus recevraient tous la même valeur.
                $datasource->exec(
                    $datasource->prepare(
                        'INSERT INTO perm_module (user_id, mod_id, permission, view) VALUES (%1, %2, %3, %4)',
                        $profile_id,
                        $mod_id,
                        $level,
                        $level
                    )
                );
            }

            $written = (int)$datasource->loadResult(
                $datasource->prepare('SELECT COUNT(*) FROM perm_module WHERE user_id = %1', $profile_id)
            );

            // RELU APRÈS ÉCRITURE : zéro ligne insérée n'est pas une erreur SQL, et un succès
            // annoncé sans relecture est le défaut que ce dépôt passe son temps à corriger.
            if ($written !== count($granted)) {
                return sprintf('ÉCART : %d ligne(s) écrite(s) pour %d attendue(s)', $written, count($granted));
            }

            return sprintf('%d droit(s) posé(s)', $written);
        } catch (Throwable $e) {
            return get_class($e) . ' : ' . $e->getMessage();
        }
    }

    /**
     * @return array<string, int>
     */
    private function moduleIds(CSQLDataSource $datasource): array
    {
        $ids = [];

        foreach ((array)$datasource->loadList('SELECT mod_id, mod_name FROM modules WHERE mod_active = 1') as $row) {
            $ids[$row['mod_name']] = (int)$row['mod_id'];
        }

        return $ids;
    }

    /**
     * @return array<string, int>
     */
    private function profileIds(CSQLDataSource $datasource): array
    {
        $ids = [];

        // template = '1' EN CHAÎNE : la colonne est un enum('0','1'), et une comparaison NUMÉRIQUE
        // y désigne l'INDEX — « template = 1 » sélectionne donc la valeur '0', soit l'inverse.
        $rows = (array)$datasource->loadList('SELECT user_id, user_username FROM users WHERE template = \'1\'');

        foreach ($rows as $row) {
            $ids[$row['user_username']] = (int)$row['user_id'];
        }

        return $ids;
    }

    /**
     * @throws Exception
     */
    private function bootstrap(): void
    {
        $autoloader = require $this->root . '/vendor/autoload.php';

        foreach (glob($this->root . '/modules/*/composer.json') as $composer_file) {
            $json = json_decode((string)file_get_contents($composer_file), true);
            $dir  = dirname($composer_file);

            foreach (($json['autoload']['psr-4'] ?? []) as $namespace => $path) {
                $autoloader->addPsr4($namespace, "$dir/$path");
            }
        }

        CApp::getInstance()->startForCli('ox-perms:profiles');
    }
}
