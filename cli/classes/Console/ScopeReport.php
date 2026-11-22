<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Console;

use Exception;
use Hospilibre\Admin\CPermUnit;
use Hospilibre\Hospi\CObservationMedicale;
use Hospilibre\Hospi\CTransmissionMedicale;
use Hospilibre\Admin\CUser;
use Ox\Cli\HospilibreCommand;
use Hospilibre\Admin\CPermModule;
use Hospilibre\Admin\CPermObject;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CSQLDataSource;
use Ox\Core\CClassMap;
use Ox\Core\CMbDT;
use Ox\Core\CStoredObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ce que le périmètre d'exercice laisse voir, unité par unité.
 *
 * POURQUOI UNE COMMANDE ET PAS UN TEST. Un test unitaire prouve que le prédicat répond ce qu'on
 * attend sur des cas qu'on a soi-même écrits ; il ne dit pas si le découpage est VIVABLE sur un
 * établissement réel. La question qui compte — « une infirmière de réanimation voit combien de
 * dossiers, et lesquels lui échappent ? » — ne se répond qu'en interrogeant les données. Les deux
 * sont donc nécessaires et ne se remplacent pas.
 *
 * ⚠ ELLE N'ÉCRIT RIEN. C'est un rapport, et il doit rester sans effet pour qu'on puisse le lancer
 * sur une instance en service sans y penser deux fois.
 *
 * CE QU'ELLE SAIT DÉTECTER, et c'est son intérêt principal : un périmètre qui enferme. Un agent à
 * zéro dossier accessible alors que son unité héberge des patients est le symptôme d'un
 * rattachement manquant — et ce symptôme est invisible depuis l'application, où l'agent voit
 * simplement une liste vide sans message.
 */
class ScopeReport extends HospilibreCommand
{
    /** @var SymfonyStyle */
    private $io;

    /** @var string */
    private $root;

    protected function configure(): void
    {
        $this
            ->setName('ox-perms:scope')
            ->setDescription("Rapporte ce que le périmètre d'exercice laisse voir, unité par unité")
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Racine de la copie de travail',
                dirname(__DIR__, 3)
            )
            ->addOption(
                'agents',
                null,
                InputOption::VALUE_OPTIONAL,
                'Nombre d\'agents échantillonnés par unité',
                '2'
            );
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io   = new SymfonyStyle($input, $output);
        $this->root = rtrim((string)$input->getOption('path'), '/');
        $sample     = max(1, (int)$input->getOption('agents'));

        $this->bootstrap();

        $this->io->title("Périmètre d'exercice");

        if (!CPermUnit::isActive()) {
            $this->io->warning(
                "Le périmètre est DÉSACTIVÉ (admin CPermUnit active = 0) : le rapport ci-dessous "
                . "décrit ce qu'il refuserait s'il était en service, mais rien n'est refusé."
            );
        }

        $ds = CSQLDataSource::get('std');

        if (!$ds) {
            $this->io->error("La datasource « std » n'est pas joignable.");

            return self::FAILURE;
        }

        // LES PATIENTS PRÉSENTS SONT COMPTÉS EN MÊME TEMPS QUE LES AGENTS, parce que c'est ce qui
        // permet de lire un zéro. Un agent qui n'accède à rien dans une unité VIDE est normal : il
        // n'y a rien à voir. Le même zéro dans une unité qui héberge des patients est un
        // rattachement manquant. Sans cette colonne, le rapport signalerait les deux de la même
        // façon et son alarme deviendrait inutilisable.
        $units = $ds->loadList(
            $ds->prepare(
                "SELECT sv.service_id, sv.nom,
                        (SELECT COUNT(DISTINCT a.user_id) FROM affectation_user_service a
                          WHERE a.service_id = sv.service_id AND a.date IS NULL) agents,
                        (SELECT COUNT(DISTINCT f.sejour_id) FROM affectation f
                          WHERE f.service_id = sv.service_id AND %1 BETWEEN f.entree AND f.sortie) presents
                   FROM service sv ORDER BY sv.nom",
                CMbDT::dateTime()
            )
        );

        if (!is_array($units) || $units === []) {
            $this->io->error("Aucun service : il n'y a pas de périmètre à rapporter.");

            return self::FAILURE;
        }

        $records = $this->careRecords($ds);

        $this->io->writeln(
            sprintf(
                " Dossier de soins : <info>%d</info> pièce(s) sur <info>%d</info> séjour(s).",
                count($records),
                count(array_unique(array_column($records, 'sejour_id')))
            )
        );
        $this->io->newLine();

        $rows      = [];
        $enclosed  = [];
        $unlinked  = 0;

        foreach ($units as $_unit) {
            if (!(int)$_unit['agents']) {
                // UNE UNITÉ SANS AGENT RATTACHÉ : à signaler, parce que ses patients ne sont
                // accessibles à personne par le périmètre — seule la participation individuelle
                // ouvre encore leurs dossiers.
                $unlinked++;
                $rows[] = [$_unit['nom'], '—', '0', (string)(int)$_unit['presents'], '—', '—', '—', 'aucun agent rattaché'];
                continue;
            }

            foreach ($this->agentsOf($ds, (int)$_unit['service_id'], $sample) as $_agent) {
                $user = CUser::get($_agent['user_id']);

                // LE CACHE DOIT TOMBER ENTRE DEUX AGENTS : il mémorise un verdict par utilisateur,
                // et le rapport en parcourt des dizaines dans un seul processus.
                CPermUnit::resetCache();

                $seen     = $this->countVisible($records, $user);
                $presents = (int)$_unit['presents'];

                // LA DÉCOMPOSITION DES ACCÈS, et elle ne refait PAS le calcul du périmètre : la
                // décision d'accès vient entièrement de `CPermUnit`, et seule la RAISON est
                // attribuée ici, en demandant à la base les séjours où l'agent a laissé une trace.
                // Un rapport qui recalculerait le périmètre pour le vérifier pourrait tomber
                // d'accord avec lui-même en étant faux deux fois.
                $retained = $this->countVisibleOnStays($records, $user, $this->involvedStays($ds, $_agent['user_id']));

                $remark = '';

                if ($seen === 0 && $presents > 0) {
                    // LE SEUL CAS ALARMANT : l'unité héberge des patients et l'agent n'accède à
                    // aucun dossier. Le rattachement durable manque, ou il désigne un autre
                    // service que celui où les patients sont affectés.
                    $enclosed[] = $_agent['nom'] . ' (' . $_unit['nom'] . ')';
                    $remark     = '⚠ enfermé';
                } elseif ($seen === 0) {
                    $remark = 'unité vide';
                }

                $rows[] = [
                    $_unit['nom'],
                    $_agent['nom'],
                    (string)$_unit['agents'],
                    (string)$presents,
                    (string)($seen - $retained),
                    (string)$retained,
                    (string)(count($records) - $seen),
                    $remark,
                ];
            }
        }

        $this->io->table(
            ['Unité', 'Agent', 'Agents', 'Présents', "Par l'unité", 'Par participation', 'Refusés', ''],
            $rows
        );

        if ($unlinked) {
            $this->io->warning(
                sprintf('%d unité(s) sans agent rattaché : leurs patients ne sont dans le périmètre de personne.', $unlinked)
            );
        }

        if (!$this->theatreClinicians($ds, $records)) {
            return self::FAILURE;
        }

        $crossed = $this->crossCheck($ds, $units, $sample);

        if ($enclosed !== []) {
            $this->io->error(
                "Des agents n'accèdent à AUCUNE pièce du dossier de soins :\n  - "
                . implode("\n  - ", $enclosed)
            );

            return self::FAILURE;
        }

        if (!$crossed) {
            return self::FAILURE;
        }

        if (!$this->endToEnd($ds, $units)) {
            return self::FAILURE;
        }

        $this->io->success(
            'Chaque agent échantillonné accède au dossier de soins de son unité, et seulement au sien.'
        );

        return self::SUCCESS;
    }

    /**
     * LES AGENTS DU BLOC, qui ne tiennent aucun service.
     *
     * POURQUOI CETTE SECTION EXISTE. Le tableau précédent parcourt les SERVICES : il ne pouvait donc
     * pas voir les anesthésistes, qui n'en tiennent aucun. Son verdict « chaque agent échantillonné
     * accède au dossier de son unité » était vrai et omettait une catégorie entière — exactement le
     * défaut de forme qu'on a payé trois fois sur ce dossier : une grandeur juste sous une
     * conclusion plus large que sa portée.
     *
     * CE QU'ELLE VÉRIFIE : que le périmètre de bloc RESTREINT sans ENFERMER. Un anesthésiste doit
     * atteindre les séjours où il est nommé, et pas les autres. Les deux bornes comptent : zéro
     * accès serait un enfermement, l'accès à tout signifierait que la clause « sans unité donc
     * dispensé » le couvre encore.
     */
    private function theatreClinicians(CSQLDataSource $ds, array $records): bool
    {
        // NOMMÉS SUR UNE INTERVENTION ET SANS SERVICE : c'est la définition qu'emploie CPermUnit,
        // et la reprendre ici garantit que le rapport mesure la même population que le droit.
        // LA CONSULTATION COMPTE AUTANT QUE L'INTERVENTION, et l'omettre laissait un angle mort :
        // un anesthésiste qui ne ferait QUE des consultations pré-anesthésiques n'apparaîtrait pas
        // dans ce tableau, alors que le périmètre le restreint. La définition doit être celle de
        // `CPermUnit`, sans quoi le rapport mesure une autre population que le droit.
        $rows = $ds->loadList(
            "SELECT DISTINCT u.user_id, CONCAT(u.user_last_name, ' ', u.user_first_name) nom
               FROM users u
              WHERE u.user_id NOT IN (
                    SELECT user_id FROM affectation_user_service
                     WHERE date IS NULL AND user_id IS NOT NULL)
                AND (EXISTS (SELECT 1 FROM operations o
                              WHERE o.anesth_id = u.user_id OR o.prat_visite_anesth_id = u.user_id)
                  OR EXISTS (SELECT 1 FROM consultation_anesth ca
                              JOIN consultation c ON c.consultation_id = ca.consultation_id
                              JOIN plageconsult pc ON pc.plageconsult_id = c.plageconsult_id
                             WHERE pc.chir_id = u.user_id))
              ORDER BY u.user_last_name"
        );

        if (!is_array($rows) || $rows === []) {
            $this->io->section('Agents du bloc sans service');
            $this->io->writeln(" Aucun : personne n'est nommé sur une intervention sans tenir de service.");

            return true;
        }

        $table = [];
        $ok    = true;

        foreach ($rows as $_row) {
            CPermUnit::resetCache();

            $seen  = $this->countVisible($records, CUser::get($_row['user_id']));
            // LES DEUX VOIES RÉUNIES, parce que la colonne se lit en regard de « Accessibles » :
            // ne compter que les interventions donnait un nombre plus ÉTROIT que le périmètre
            // qu'il prétend expliquer, et la comparaison devenait trompeuse.
            $stays = (int)$ds->loadResult(
                $ds->prepare(
                    'SELECT COUNT(DISTINCT sejour_id) FROM (
                        SELECT sejour_id FROM operations
                         WHERE anesth_id = %1 OR prat_visite_anesth_id = %1
                      UNION SELECT ca.sejour_id FROM consultation_anesth ca
                              JOIN consultation c ON c.consultation_id = ca.consultation_id
                              JOIN plageconsult pc ON pc.plageconsult_id = c.plageconsult_id
                             WHERE pc.chir_id = %1) voies',
                    $_row['user_id']
                )
            );

            $remark = '';

            if ($seen === 0) {
                $remark = '⚠ enfermé';
                $ok     = false;
            } elseif ($seen === count($records)) {
                $remark = '⚠ aucun refus : encore dispensé';
                $ok     = false;
            }

            $table[] = [
                $_row['nom'],
                (string)$stays,
                (string)$seen,
                (string)(count($records) - $seen),
                $remark,
            ];
        }

        $this->io->section('Agents du bloc sans service : périmètre par interventions et consultations');
        $this->io->table(
            ['Agent', 'Séjours où il est intervenu', 'Accessibles', 'Refusés', ''],
            $table
        );

        if (!$ok) {
            $this->io->error(
                "Le périmètre de bloc ne restreint pas comme il doit : voir la dernière colonne."
            );
        }

        return $ok;
    }

    /**
     * LE CHEMIN DE L'ÉCRAN, et non celui du prédicat.
     *
     * POURQUOI CETTE SECTION EXISTE. Les deux précédentes interrogent `CPermUnit` directement ;
     * elles prouvent que le prédicat répond juste, PAS qu'il est consulté. La distinction a déjà
     * coûté une implémentation : le périmètre avait d'abord été posé dans
     * `CPermObject::getPermObject()`, où les pièces du dossier ne passent jamais — elles
     * redéfinissent `getPerm()` et délèguent au séjour. Le prédicat était bon, le rapport était
     * satisfait, et aucun écran n'aurait rien refusé.
     *
     * ON SE SUBSTITUE À L'UTILISATEUR, le temps d'un appel. `getPerm()` n'accepte pas
     * d'utilisateur en paramètre : il lit celui de la session. C'est donc `CAppUI` qu'il faut
     * déplacer, et les caches de permission avec lui, sinon le deuxième agent relirait le verdict
     * du premier.
     *
     * ⚠ LE DROIT DE MODULE SE MÊLE AU RÉSULTAT, inévitablement : `getPerm()` compose le périmètre,
     * le droit sur l'établissement, celui sur le praticien et celui sur le module. Un refus ne dit
     * donc pas à lui seul que c'est le périmètre qui a parlé — d'où la colonne qui rappelle le
     * droit de module de chaque agent, sans quoi on attribuerait au périmètre un refus qui vient
     * de la grille métier.
     */
    private function endToEnd(CSQLDataSource $ds, array $units): bool
    {
        $occupied = array_values(array_filter($units, fn(array $unit): bool => (int)$unit['presents'] > 0));

        if (count($occupied) < 2) {
            return true;
        }

        $previous_user = CAppUI::$instance->user_id;
        $rows          = [];
        $ok            = true;

        try {
            foreach ($occupied as $_index => $_unit) {
                $record = $this->recordInUnit($ds, (int)$_unit['service_id']);

                if ($record === null) {
                    continue;
                }

                $foreign = $occupied[($_index + 1) % count($occupied)];
                $inside  = $this->agentsOf($ds, (int)$_unit['service_id'], 1);
                $outside = $this->agentsOf($ds, (int)$foreign['service_id'], 1);

                if ($inside === [] || $outside === []) {
                    continue;
                }

                // L'agent étranger ne doit pas avoir participé au séjour, sinon le refus attendu
                // n'est pas dû : on le signale plutôt que de conclure à tort.
                $foreign_involved = $this->isInvolved($ds, (int)$record['sejour_id'], $outside[0]['user_id']);

                $in  = $this->permAs($inside[0]['user_id'], $record);
                $out = $this->permAs($outside[0]['user_id'], $record);

                $verdict = 'conforme';

                if (!$in['perm']) {
                    $verdict = $in['module']
                        ? "⚠ l'agent de l'unité est REFUSÉ malgré son droit de module"
                        : "indécis : l'agent de l'unité n'a pas le droit de module";
                    $ok      = $ok && !$in['module'];
                } elseif ($out['perm']) {
                    $verdict = $foreign_involved
                        ? 'accès étranger justifié par sa participation'
                        : "⚠ l'agent étranger ACCÈDE par getPerm()";
                    $ok      = $ok && $foreign_involved;
                }

                $rows[] = [
                    $record['class'] . ' ' . $record['id'] . ' (' . $_unit['nom'] . ')',
                    $inside[0]['nom'] . ' : ' . ($in['perm'] ? 'accès' : 'refus')
                    . ' [module ' . ($in['module'] ? 'oui' : 'non') . ']',
                    $outside[0]['nom'] . ' : ' . ($out['perm'] ? 'ACCÈS' : 'refus')
                    . ' [module ' . ($out['module'] ? 'oui' : 'non') . ']',
                    $verdict,
                ];
            }
        } finally {
            CAppUI::$instance->user_id = $previous_user;
            $this->forgetPermissions();
        }

        $this->io->section("Bout en bout : par getPerm(), le chemin de l'écran");
        $this->io->table(['Pièce', "Agent de l'unité", 'Agent étranger', 'Verdict'], $rows);

        if (!$ok) {
            $this->io->error("Le périmètre ne mord pas à l'écran : voir la colonne « Verdict ».");
        }

        return $ok;
    }

    /**
     * Le droit réel sur cette pièce, vu par cet utilisateur.
     *
     * @return array{perm: bool, module: bool}
     */
    private function permAs(string $user_id, array $record): array
    {
        CAppUI::$instance->user_id = $user_id;
        $this->forgetPermissions();

        $class  = CClassMap::getInstance()->getAliasByShortName($record['class']);
        $object = new $class();
        $object->load($record['id']);

        $module = $object->_ref_module ? $object->_ref_module->getPerm(PERM_READ) : false;

        return [
            'perm'   => (bool)$object->getPerm(PERM_READ),
            'module' => (bool)$module,
        ];
    }

    /**
     * Fait oublier aux trois systèmes de droits ce qu'ils savent de l'utilisateur précédent.
     *
     * LES TROIS, ET PAS SEULEMENT LE NÔTRE. `CPermModule` et `CPermObject` mémorisent leurs
     * verdicts dans des tableaux statiques indexés par utilisateur — mais `buildUser()` refuse de
     * recharger un utilisateur déjà connu, si bien qu'un cache conservé entre deux substitutions
     * rendrait le même verdict pour tout le monde. Un rapport qui tomberait dans ce piège
     * afficherait une colonne parfaitement uniforme, qu'on lirait comme une preuve.
     */
    private function forgetPermissions(): void
    {
        CPermUnit::resetCache();

        CPermModule::$users_perms = is_array(CPermModule::$users_perms) ? [] : CPermModule::$users_perms;
        CPermModule::$users_cache = [];
        CPermObject::$users_perms = is_array(CPermObject::$users_perms) ? [] : CPermObject::$users_perms;
        CPermObject::$users_cache = [];
    }

    /**
     * Le croisement : la preuve que la règle a un SENS, et pas seulement un volume.
     *
     * CE QUE LE TABLEAU DES COMPTES NE DIT PAS. « 45 accessibles sur 2 605 » est un ordre de
     * grandeur vraisemblable, et il le resterait si le prédicat refusait au hasard. Ce qu'il faut
     * établir est une double implication, sur des lignes NOMMÉES : l'agent de l'unité où le patient
     * se trouve y accède, et l'agent d'une AUTRE unité en est écarté.
     *
     * LA PARTICIPATION EST VÉRIFIÉE AVANT DE CRIER, parce qu'un accès d'un agent étranger n'est pas
     * forcément une fuite : il peut avoir signé une pièce de ce séjour, et le périmètre est fait
     * pour le lui garder. Confondre les deux produirait une fausse alarme à chaque fois qu'un
     * patient a traversé deux unités — c'est-à-dire sur tous les transferts, les cas mêmes qui
     * justifient la règle.
     */
    private function crossCheck(CSQLDataSource $ds, array $units, int $sample): bool
    {
        $occupied = array_values(array_filter($units, fn(array $unit): bool => (int)$unit['presents'] > 0));

        if (count($occupied) < 2) {
            $this->io->warning(
                'Moins de deux unités occupées : le croisement est impossible, la règle reste non '
                . "prouvée dans le sens du refus."
            );

            return true;
        }

        $rows = [];
        $ok   = true;

        foreach ($occupied as $_index => $_unit) {
            $record = $this->recordInUnit($ds, (int)$_unit['service_id']);

            if ($record === null) {
                $rows[] = [$_unit['nom'], '—', '—', 'aucune pièce sur un patient présent'];
                continue;
            }

            // L'unité suivante dans la liste, en boucle : chaque unité occupée sert ainsi d'étrangère
            // à une autre, sans qu'on ait à en désigner une arbitrairement.
            $foreign = $occupied[($_index + 1) % count($occupied)];

            $inside  = $this->agentsOf($ds, (int)$_unit['service_id'], 1);
            $outside = $this->agentsOf($ds, (int)$foreign['service_id'], 1);

            if ($inside === [] || $outside === []) {
                $rows[] = [$_unit['nom'], '—', $foreign['nom'], 'unité sans agent rattaché'];
                continue;
            }

            CPermUnit::resetCache();
            $stub = $this->stub($record);

            $allowed_inside = !CPermUnit::denies($stub, CUser::get($inside[0]['user_id']));
            $denied_outside = CPermUnit::denies($stub, CUser::get($outside[0]['user_id']));

            $verdict = 'conforme';

            if (!$allowed_inside) {
                $verdict = "⚠ l'agent de l'unité est REFUSÉ";
                $ok      = false;
            } elseif (!$denied_outside) {
                $involved = $this->isInvolved($ds, (int)$record['sejour_id'], $outside[0]['user_id']);
                $verdict  = $involved
                    ? "accès étranger justifié par sa participation"
                    : "⚠ l'agent étranger ACCÈDE sans participation";
                $ok       = $ok && $involved;
            }

            $rows[] = [
                $_unit['nom'] . ' / séjour ' . $record['sejour_id'],
                $inside[0]['nom'] . ' : ' . ($allowed_inside ? 'accès' : 'REFUS'),
                $outside[0]['nom'] . ' (' . $foreign['nom'] . ') : ' . ($denied_outside ? 'refus' : 'ACCÈS'),
                $verdict,
            ];
        }

        $this->io->section('Croisement : le patient est ici, l\'agent est ailleurs');
        $this->io->table(
            ["Pièce (unité d'accueil)", "Agent de l'unité", 'Agent étranger', 'Verdict'],
            $rows
        );

        if (!$ok) {
            $this->io->error("Le périmètre ne se comporte pas comme il doit : voir la colonne « Verdict ».");
        }

        return $ok;
    }

    /**
     * Une pièce du dossier portant sur un patient actuellement hébergé par cette unité.
     *
     * @return array{class: string, id: int, sejour_id: int}|null
     */
    private function recordInUnit(CSQLDataSource $ds, int $service_id): ?array
    {
        $sources = [
            'CObservationMedicale'  => ['observation_medicale', 'observation_medicale_id'],
            'CTransmissionMedicale' => ['transmission_medicale', 'transmission_medicale_id'],
        ];

        foreach ($sources as $_class => [$_table, $_key]) {
            $row = $ds->loadList(
                $ds->prepare(
                    "SELECT r.$_key id, r.sejour_id
                       FROM $_table r
                       JOIN affectation a ON a.sejour_id = r.sejour_id
                      WHERE a.service_id = %1 AND %2 BETWEEN a.entree AND a.sortie
                      LIMIT 1",
                    $service_id,
                    CMbDT::dateTime()
                )
            );

            if (is_array($row) && $row !== []) {
                return [
                    'class'     => $_class,
                    'id'        => (int)$row[0]['id'],
                    'sejour_id' => (int)$row[0]['sejour_id'],
                ];
            }
        }

        return null;
    }

    private function isInvolved(CSQLDataSource $ds, int $sejour_id, string $user_id): bool
    {
        $query = $ds->prepare(
            "SELECT 1 FROM sejour WHERE sejour_id = %1 AND praticien_id = %2
          UNION SELECT 1 FROM observation_medicale WHERE sejour_id = %1 AND user_id = %2
          UNION SELECT 1 FROM transmission_medicale WHERE sejour_id = %1 AND user_id = %2
          LIMIT 1",
            $sejour_id,
            $user_id
        );

        return (bool)$ds->loadResult($query);
    }

    /**
     * Amorce l'application hors contexte HTTP.
     *
     * LE MÊME AMORÇAGE QUE LES AUTRES COMMANDES `ox-*` de ce répertoire, et il doit l'être : les
     * espaces de noms des modules ne sont pas dans l'autoloader de Composer tant que cette boucle
     * ne les y a pas ajoutés, si bien que `CPermUnit` et `CUser` seraient introuvables.
     *
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

        CApp::getInstance()->startForCli('ox-perms:scope');
    }

    /**
     * Les pièces du dossier de soins, sous une forme assez légère pour être parcourue en mémoire.
     *
     * ON CHARGE LES IDENTIFIANTS, PAS LES OBJETS : le rapport compare des milliers de pièces à des
     * dizaines d'agents, et instancier chaque pièce pour chaque agent ferait des centaines de
     * milliers de chargements. Les objets sont reconstitués à la volée, sans passer par la base,
     * parce que le prédicat n'a besoin que de la classe, de l'identifiant et du séjour.
     *
     * @return array<array{class: string, id: int, sejour_id: int}>
     */
    private function careRecords(CSQLDataSource $ds): array
    {
        $records = [];

        $sources = [
            'CObservationMedicale'  => 'SELECT observation_medicale_id id, sejour_id FROM observation_medicale',
            'CTransmissionMedicale' => 'SELECT transmission_medicale_id id, sejour_id FROM transmission_medicale',
        ];

        foreach ($sources as $_class => $_query) {
            $rows = $ds->loadList($_query);

            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $_row) {
                $records[] = [
                    'class'     => $_class,
                    'id'        => (int)$_row['id'],
                    'sejour_id' => (int)$_row['sejour_id'],
                ];
            }
        }

        return $records;
    }

    /**
     * Combien de pièces cet agent peut-il atteindre ?
     */
    private function countVisible(array $records, CUser $user): int
    {
        $visible = 0;

        foreach ($records as $_record) {
            if (!CPermUnit::denies($this->stub($_record), $user)) {
                $visible++;
            }
        }

        return $visible;
    }

    /**
     * Les séjours où l'agent a laissé une trace, en UNE requête.
     *
     * UNE SEULE REQUÊTE PAR AGENT, et c'est ce qui rend la décomposition abordable : la poser
     * pièce par pièce ferait des milliers d'allers-retours pour un rapport, et la lenteur aurait
     * fini par faire supprimer la colonne la plus instructive du tableau.
     *
     * @return array<int, bool>
     */
    private function involvedStays(CSQLDataSource $ds, string $user_id): array
    {
        $query = $ds->prepare(
            "SELECT sejour_id FROM sejour WHERE praticien_id = %1
          UNION SELECT sejour_id FROM observation_medicale WHERE user_id = %1
          UNION SELECT sejour_id FROM transmission_medicale WHERE user_id = %1",
            $user_id
        );

        $rows = $ds->loadColumn($query);

        if (!is_array($rows)) {
            return [];
        }

        $stays = [];

        foreach ($rows as $_sejour_id) {
            $stays[(int)$_sejour_id] = true;
        }

        return $stays;
    }

    /**
     * Parmi les pièces accessibles, celles qui portent sur un séjour où l'agent a participé.
     */
    private function countVisibleOnStays(array $records, CUser $user, array $stays): int
    {
        if ($stays === []) {
            return 0;
        }

        $count = 0;

        foreach ($records as $_record) {
            if (isset($stays[$_record['sejour_id']]) && !CPermUnit::denies($this->stub($_record), $user)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Une pièce du dossier réduite à ce que le prédicat lui demande.
     *
     * LES DEUX CLASSES SONT NOMMÉES, et non résolues dynamiquement depuis le classmap. Une
     * instanciation dynamique rendait un objet que l'analyse statique ne pouvait voir que comme un
     * `CStoredObject` — lequel ne déclare pas `sejour_id` —, si bien qu'écrire ce champ passait
     * pour une propriété dynamique : tolérée en PHP 8.1, dépréciée ensuite. Les nommer rend le
     * champ vérifiable, et le couplage existe de toute façon dans `careRecords()`, qui connaît déjà
     * les deux tables. Un lot élargi se met donc à jour ICI, délibérément.
     *
     * ⚠ NON CHARGÉE DEPUIS LA BASE, en revanche, et c'est le raccourci assumé : seuls `_id` et
     * `sejour_id` sont posés, parce que ce sont les deux seuls champs que `CPermUnit` consulte.
     */
    private function stub(array $record): CStoredObject
    {
        $stub = $record['class'] === 'CObservationMedicale'
            ? new CObservationMedicale()
            : new CTransmissionMedicale();

        $stub->_id       = $record['id'];
        $stub->sejour_id = $record['sejour_id'];

        return $stub;
    }

    /**
     * @return array<array{user_id: string, nom: string}>
     */
    private function agentsOf(CSQLDataSource $ds, int $service_id, int $sample): array
    {
        // LA BORNE EST INTERPOLÉE, PAS PRÉPARÉE : `prepare()` entoure toujours la valeur de
        // quotes, et `LIMIT '2'` est une erreur de syntaxe MySQL. Le transtypage en entier est donc
        // ce qui tient lieu d'échappement ici, et c'est suffisant parce que la valeur ne peut plus
        // être autre chose qu'un nombre après lui.
        $query = $ds->prepare(
            "SELECT u.user_id, CONCAT(u.user_last_name, ' ', u.user_first_name) nom
               FROM affectation_user_service a
               JOIN users u ON u.user_id = a.user_id
              WHERE a.service_id = %1 AND a.date IS NULL
              ORDER BY u.user_last_name LIMIT " . (int)$sample,
            $service_id
        );

        $rows = $ds->loadList($query);

        return is_array($rows) ? $rows : [];
    }
}
