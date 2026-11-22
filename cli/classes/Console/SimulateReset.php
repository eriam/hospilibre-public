<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Ox\Cli\Console\Simulation\CSimulationLock;
use Ox\Cli\Console\Simulation\CSimulationState;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Ox\Core\CSQLDataSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Purge l'hôpital fictif (établissement + descendance rattachée au groupe) créé
 * par `simulate:bootstrap`, ainsi que les objets de flux produits par
 * `simulate:advance` (Plan 2 : consultations, plages, actes NGAP, slots,
 * opérations, constantes, salles).
 *
 * Suppression explicite par datasource (pas de `$group->delete()`, dont la
 * cascade OX se bloque sur les back-refs non-cascade), dans l'ordre
 * enfants -> parents, strictement filtrée sur le group_id de la sentinelle.
 * Les patients sont globaux (table `patients`, non rattachée à un groupe) :
 * ils ne sont pas supprimés.
 *
 * Les objets de flux (Plan 2) n'ont pas de colonne `group_id` directe :
 * - `acte_ngap` référence sa cible via `object_class`/`object_id`
 *   (CConsultation ou CSejour, cf. CActeNGAPGenerator/CConsultationGenerator) ;
 * - `consultation` référence sa plage via `plageconsult_id` (notNull) et
 *   éventuellement un séjour via `sejour_id` ;
 * - `plageconsult`/`slot` référencent le praticien via `chir_id` ;
 * - `operations`/`constantes_medicales` référencent le séjour via `sejour_id`
 *   (`constantes_medicales.context_id` avec `context_class = 'CSejour'`,
 *   cf. SimulateAdvance::createConstantes()) ;
 * - `sallesbloc` référence le bloc via `bloc_id`.
 * Toutes ces suppressions doivent donc s'exécuter AVANT celles de `sejour`,
 * `plageconsult` et `bloc_operatoire` (jointure nécessaire pour filtrer).
 */
class SimulateReset extends HospilibreCommand
{
    private SymfonyStyle $io;

    protected function configure(): void
    {
        $this
            ->setName('simulate:reset')
            ->setDescription("Purge l'hôpital fictif créé par simulate:bootstrap")
            ->addOption('group', null, InputOption::VALUE_OPTIONAL, "Purge un group_id précis (récupération d'un hôpital fictif orphelin, sans sentinelle)")
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Racine du projet', dirname(__DIR__, 3) . '/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $root     = rtrim($input->getOption('path'), '/');
        $this->bootstrap($root);

        // Même verrou que simulate:advance : purger pendant qu'une avance écrit laisserait des
        // lignes rattachées à un établissement effacé (constaté pendant la mesure Task 0).
        $lock = CSimulationLock::forRoot($root);
        if (!$lock->acquire()) {
            $this->io->error('Une avance de simulation est en cours : purge refusée, réessayer quand elle sera finie.');
            return self::FAILURE;
        }
        try {
            return $this->purge($input);
        } finally {
            $lock->release();
        }
    }

    private function purge(InputInterface $input): int
    {
        $state = new CSimulationState();
        $state->ensureTable();

        // --group cible un group_id précis (orphelin sans sentinelle) ; sinon la sentinelle.
        $optGroup = $input->getOption('group');
        $gid      = $optGroup !== null ? (int) $optGroup : $state->getGroupId();
        if ($gid === null) {
            $this->io->writeln('Aucun hôpital fictif à purger');
            return self::SUCCESS;
        }

        $gid = (int) $gid; // jamais interpolé autrement

        try {
            $ds = CSQLDataSource::get('std');

            // 0. user_id des mediusers (praticiens/personnel) du groupe, calculé tôt : réutilisé
            //    ci-dessous pour filtrer plageconsult/slot (chir_id) PUIS, plus bas, pour purger
            //    personnel/users_hospilibre/users. `users_hospilibre.user_id` est la même colonne
            //    que la PK CMediusers (table fille de CUser, clé partagée `user_id`), donc ces ids
            //    sont directement comparables à `plageconsult.chir_id`.
            $userIds = $ds->loadColumn(
                'SELECT m.user_id FROM `users_hospilibre` m JOIN `functions_hospilibre` f ON m.function_id = f.function_id WHERE f.group_id = ' . $gid
            );
            $userIds = $userIds ?: [];
            $userIdsCsv = $userIds !== [] ? implode(',', array_map('intval', $userIds)) : null;

            // 1. affectations des séjours du groupe (affectation n'a pas de group_id -> jointure séjour)
            $ds->exec(
                'DELETE a FROM `affectation` a JOIN `sejour` s ON a.sejour_id = s.sejour_id WHERE s.group_id = ' . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' affectation(s) supprimée(s)');

            // 1bis. actes NGAP (Plan 2, CActeNGAPGenerator) : ciblent une CConsultation ou un CSejour
            //    via object_class/object_id (pas de group_id direct) -> à purger AVANT consultation/sejour.
            $ds->exec(
                'DELETE ang FROM `acte_ngap` ang '
                . 'JOIN `consultation` c ON ang.object_id = c.consultation_id AND ang.object_class = \'CConsultation\' '
                . 'LEFT JOIN `plageconsult` p ON c.plageconsult_id = p.plageconsult_id '
                . 'LEFT JOIN `sejour` s ON c.sejour_id = s.sejour_id '
                . 'WHERE p.chir_id IN (' . ($userIdsCsv ?? '0') . ') OR s.group_id = ' . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' acte(s) NGAP supprimé(s) (via consultation)');
            $ds->exec(
                'DELETE ang FROM `acte_ngap` ang '
                . 'JOIN `sejour` s ON ang.object_id = s.sejour_id AND ang.object_class = \'CSejour\' '
                . 'WHERE s.group_id = ' . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' acte(s) NGAP supprimé(s) (via séjour)');

            // 1ter. consultations (Plan 2, CConsultationGenerator) : rattachées à une plage du groupe
            //    (chir_id) et/ou à un séjour du groupe -> à purger AVANT plageconsult/sejour.
            $ds->exec(
                'DELETE c FROM `consultation` c '
                . 'LEFT JOIN `plageconsult` p ON c.plageconsult_id = p.plageconsult_id '
                . 'LEFT JOIN `sejour` s ON c.sejour_id = s.sejour_id '
                . 'WHERE p.chir_id IN (' . ($userIdsCsv ?? '0') . ') OR s.group_id = ' . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' consultation(s) supprimée(s)');

            // 1quater. opérations (Plan 2, COperationGenerator) : rattachées au séjour du groupe
            //    (sejour_id notNull) -> à purger AVANT sejour.
            $ds->exec(
                'DELETE o FROM `operations` o JOIN `sejour` s ON o.sejour_id = s.sejour_id WHERE s.group_id = ' . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' opération(s) supprimée(s)');

            // 1quinquies. constantes médicales (Plan 2, SimulateAdvance::createConstantes(), context_class
            //    = CSejour) -> à purger AVANT sejour.
            $ds->exec(
                'DELETE cm FROM `constantes_medicales` cm JOIN `sejour` s ON cm.context_id = s.sejour_id '
                . "WHERE cm.context_class = 'CSejour' AND s.group_id = " . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' relevé(s) de constantes supprimé(s)');

            // 1sexies. slots (Plan 2, CPlageconsultGenerator) : rattachés à une plage du groupe (chir_id)
            //    -> à purger AVANT plageconsult.
            $ds->exec(
                'DELETE sl FROM `slot` sl JOIN `plageconsult` p ON sl.plageconsult_id = p.plageconsult_id '
                . 'WHERE p.chir_id IN (' . ($userIdsCsv ?? '0') . ')'
            );
            $this->io->writeln($ds->affectedRows() . ' slot(s) supprimé(s)');

            // 1septies. plages de consultation (Plan 2, CPlageconsultGenerator) : rattachées au praticien
            //    (chir_id) du groupe.
            if ($userIdsCsv !== null) {
                $ds->exec('DELETE FROM `plageconsult` WHERE chir_id IN (' . $userIdsCsv . ')');
                $this->io->writeln($ds->affectedRows() . ' plage(s) de consultation supprimée(s)');
            }

            // 2. séjours du groupe
            $ds->exec('DELETE FROM `sejour` WHERE group_id = ' . $gid);
            $this->io->writeln($ds->affectedRows() . ' séjour(s) supprimé(s)');

            // 3. lits -> chambre -> service du groupe
            $ds->exec(
                'DELETE l FROM `lit` l JOIN `chambre` c ON l.chambre_id = c.chambre_id JOIN `service` s ON c.service_id = s.service_id WHERE s.group_id = ' . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' lit(s) supprimé(s)');

            // 4. chambres -> service du groupe
            $ds->exec(
                'DELETE c FROM `chambre` c JOIN `service` s ON c.service_id = s.service_id WHERE s.group_id = ' . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' chambre(s) supprimée(s)');

            // 5. services du groupe
            $ds->exec('DELETE FROM `service` WHERE group_id = ' . $gid);
            $this->io->writeln($ds->affectedRows() . ' service(s) supprimé(s)');

            // 5bis. salles (table `sallesbloc`, Plan 1) rattachées aux blocs du groupe -> à purger
            //    AVANT bloc_operatoire.
            $ds->exec(
                'DELETE sb FROM `sallesbloc` sb JOIN `bloc_operatoire` b ON sb.bloc_id = b.bloc_operatoire_id WHERE b.group_id = ' . $gid
            );
            $this->io->writeln($ds->affectedRows() . ' salle(s) supprimée(s)');

            // 6. blocs opératoires du groupe
            $ds->exec('DELETE FROM `bloc_operatoire` WHERE group_id = ' . $gid);
            $this->io->writeln($ds->affectedRows() . ' bloc(s) opératoire(s) supprimé(s)');

            // 7. unités fonctionnelles du groupe (table `uf`)
            $ds->exec('DELETE FROM `uf` WHERE group_id = ' . $gid);
            $this->io->writeln($ds->affectedRows() . ' unité(s) fonctionnelle(s) supprimée(s)');

            // 8. mediusers du groupe (users_hospilibre) via leurs fonctions, PUIS leurs users parents
            //    a) user_id des mediusers du groupe : déjà chargé en $userIds tout en haut (étape 0),
            //    réutilisé ici tel quel pour purger personnel/users_hospilibre/users.

            //    a-bis) personnels (table `personnel`) rattachés à ces mediusers : créés par
            //    CPersonnelGenerator via createStaff, leur user_id référence users_hospilibre
            //    (notNull). À supprimer AVANT les mediusers pour ne pas laisser de référence
            //    pendante (pas de FK SQL, donc non bloquant mais accumulé sinon).
            if ($userIds !== []) {
                $ds->exec('DELETE FROM `personnel` WHERE user_id IN (' . implode(',', array_map('intval', $userIds)) . ')');
                $this->io->writeln($ds->affectedRows() . ' personnel(s) supprimé(s)');
            }

            //    b) mediusers eux-mêmes
            $ds->exec(
                'DELETE FROM `users_hospilibre` WHERE function_id IN (SELECT function_id FROM `functions_hospilibre` WHERE group_id = ' . $gid . ')'
            );
            $this->io->writeln($ds->affectedRows() . ' mediuser(s) supprimé(s)');

            //    c) rows CUser parentes — seulement si des ids ont été récupérés (IN () est invalide)
            if ($userIds !== []) {
                $ds->exec('DELETE FROM `users` WHERE user_id IN (' . implode(',', array_map('intval', $userIds)) . ')');
                $this->io->writeln($ds->affectedRows() . ' user(s) supprimé(s)');
            }

            // 9. fonctions du groupe
            $ds->exec('DELETE FROM `functions_hospilibre` WHERE group_id = ' . $gid);
            $this->io->writeln($ds->affectedRows() . ' fonction(s) supprimée(s)');

            // 10. l'établissement lui-même (table groups_hospilibre)
            $ds->exec('DELETE FROM `groups_hospilibre` WHERE group_id = ' . $gid);
            $this->io->writeln($ds->affectedRows() . ' établissement(s) supprimé(s)');
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());
            return self::FAILURE;
        }

        // Purge courante (sans --group) OU --group qui vise justement le groupe de la
        // sentinelle : on efface la sentinelle. En mode --group visant un orphelin (autre
        // groupe / sentinelle absente), on n'y touche pas.
        if ($optGroup === null || $state->getGroupId() === $gid) {
            $state->clear();
        }
        $this->io->success("Hôpital fictif purgé (group_id=$gid)");

        return self::SUCCESS;
    }

    private function bootstrap(string $root): void
    {
        $autoloader = require "$root/vendor/autoload.php";
        foreach (glob("$root/modules/*/composer.json") as $composerFile) {
            $json = json_decode(file_get_contents($composerFile), true);
            $dir  = dirname($composerFile);
            foreach (($json['autoload']['psr-4'] ?? []) as $namespace => $path) {
                $autoloader->addPsr4($namespace, "$dir/$path");
            }
        }
        // NB (Task 6) : ne PAS passer CComposerScript::$is_running à true ici. Ce flag
        // désactive cache/back-refs le temps qu'il est actif (cf. usages dans
        // CModelObject::getBackProps(), CacheManager, Cache, CMbObjectSpec,
        // CAbstractModuleCache) — utile pendant le vrai hook Composer
        // pre-autoload-dump, mais startForCli() n'en a pas besoin ici (schéma déjà
        // en place sur cette instance). Pire : getBackProps() met en cache le
        // résultat par classe en statique pour tout le process ; l'activer même
        // brièvement pendant startForCli() (qui instancie déjà des CMediusers via
        // authTestUser()) fige leurs back-refs à vide pour le reste de l'exécution,
        // même après une remise à false — ex. CPatient::store() plante alors sur
        // loadBackRefs('sources_identite') qui retourne null au lieu d'un tableau
        // (TypeError), ou CMediusers::getPerm() sur 'secondary_functions' (accès de
        // clé de tableau indéfinie). Constaté à l'implémentation de createPatients().
        CApp::getInstance()->startForCli('simulate:bootstrap');
    }
}
