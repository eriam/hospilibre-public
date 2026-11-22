<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Console;

use Ox\Cli\HospilibreCommand;
use Ox\Core\CMbDT;
use Ox\Cli\Seed\AnchoredCohort;
use Ox\Cli\Seed\BulkWriter;
use Ox\Cli\Seed\CohortExport;
use Ox\Cli\Seed\CollectingWriter;
use Ox\Cli\Seed\FictitiousNames;
use Ox\Cli\Seed\HospiSeeder;
use Ox\Cli\Seed\IppRegistry;
use Ox\Cli\Seed\Decor\DecorRegistry;
use Ox\Cli\Seed\ModuleGuard;
use Ox\Cli\Seed\OrmWriter;
use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedPurger;
use Ox\Cli\Seed\SeedRandom;
use Ox\Cli\Seed\SeedWriter;
use Ox\Core\CAppUI;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Peuple une instance de séjours d'hospitalisation plausibles.
 *
 * Destinée à l'OPÉRATEUR d'un range pédagogique ou d'une démonstration. Elle partage
 * intégralement ses builders avec la fixture de groupe `hospi-seed` (HospiSeedFixtures) : aucune
 * logique métier n'est dupliquée entre les deux, seule l'enveloppe change.
 *
 * Tout ce qui permet de REJOUER le jeu de données est affiché en fin de course — profil, graine,
 * instant de référence. Un jeu de données de range dont on a perdu la graine n'est plus
 * reproductible, et une consigne d'exercice qui cite un lit précis devient fausse.
 */
class SeedHospi extends HospilibreCommand implements IAppDependantCommand
{
    /** Classe portée par les références de traçabilité, et donc clé de la purge. */
    public const SEED_MARKER = 'ox-seed:hospi';

    /**
     * Plancher de mémoire par palier de volume estimé, en Mio. Voir ensureMemoryLimit().
     *
     * LES VALEURS VIENNENT DE MESURES, et les paliers aussi :
     *   - 1 212 objets estimés mouraient à 128 Mio (le défaut de l'image de production) ;
     *   - 71 097 objets estimés — le profil `medium`, 200 lits — ont culminé à 1 475 Mio,
     *     soit juste AU-DESSUS de 1 Go : trois exécutions sont mortes à quelques minutes du but
     *     parce que le plancher précédent valait précisément 1 Go.
     *
     * Le plafond de chaque palier garde donc une marge franche sur la mesure : être trop généreux ne
     * coûte rien — c'est une limite, pas une réservation — alors qu'être trop juste tue le processus
     * au milieu de l'écriture.
     *
     * @var array<int, string> volume estimé maximal => plancher
     */
    private const MEMORY_FLOORS = [
        10000  => '512M',
        120000 => '2048M',
    ];

    /** Plancher au-delà du dernier palier. */
    private const MEMORY_FLOOR_MAX = '4096M';

    protected function configure(): void
    {
        $this
            ->setName('ox-seed:hospi')
            ->setDescription("Peuple l'instance de séjours d'hospitalisation plausibles")
            ->addOption(
                'profile',
                'p',
                InputOption::VALUE_REQUIRED,
                'Profil d\'échelle : ' . implode('|', SeedProfile::names()),
                SeedProfile::DEFAULT_PROFILE
            )
            ->addOption(
                'decor',
                'd',
                InputOption::VALUE_REQUIRED,
                "Décor de l'établissement : " . implode('|', DecorRegistry::names()),
                DecorRegistry::DEFAULT_DECOR
            )
            ->addOption(
                'seed',
                's',
                InputOption::VALUE_REQUIRED,
                'Graine du générateur. Même profil + même graine = mêmes données',
                (string)SeedRandom::DEFAULT_SEED
            )
            ->addOption('services', null, InputOption::VALUE_REQUIRED, 'Surcharge le nombre de services')
            ->addOption('lits', null, InputOption::VALUE_REQUIRED, 'Surcharge le nombre de lits')
            ->addOption('patients', null, InputOption::VALUE_REQUIRED, 'Surcharge le nombre de patients')
            ->addOption('sejours', null, InputOption::VALUE_REQUIRED, 'Surcharge le nombre de séjours')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Surcharge la fenêtre, en mois')
            ->addOption(
                'now',
                null,
                InputOption::VALUE_REQUIRED,
                'Instant de référence (Y-m-d H:i:s). Par défaut, maintenant'
            )
            ->addOption(
                'writer',
                'w',
                InputOption::VALUE_REQUIRED,
                'Chemin d\'écriture : auto|orm|bulk',
                'auto'
            )
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Purge le seed précédent avant de charger')
            ->addOption('only-purge', null, InputOption::VALUE_NONE, 'Purge le seed précédent, sans rien charger')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcule et rapporte sans rien écrire')
            ->addOption(
                'ancres',
                null,
                InputOption::VALUE_REQUIRED,
                'Fichier CSV d\'identités imposées : ' . implode(',', AnchoredCohort::REQUIRED)
                . ' (+ ' . implode(',', AnchoredCohort::OPTIONAL) . '). Semées en premier et'
                . ' COMPTÉES dans --patients'
            )
            ->addOption(
                'export',
                null,
                InputOption::VALUE_REQUIRED,
                'Écrit la cohorte produite dans ce fichier CSV : ' . implode(',', CohortExport::COLUMNS)
            )
            ->addOption(
                'only-export',
                null,
                InputOption::VALUE_REQUIRED,
                'Exporte la cohorte du semis PRÉCÉDENT dans ce fichier, sans rien semer'
            )
            ->addOption('no-ipp', null, InputOption::VALUE_NONE, 'N\'attribue aucun IPP aux patients')
            ->addOption(
                'ipp-prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Préfixe des IPP',
                IppRegistry::DEFAULT_PREFIX
            )
            ->addOption(
                'ipp-start',
                null,
                InputOption::VALUE_REQUIRED,
                'Premier numéro d\'IPP attribué à la masse',
                (string)IppRegistry::DEFAULT_START
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->disableInteractionWithoutTerminal($input, $io);

        try {
            return $this->run_seed($input, $io);
        } catch (SeedException $e) {
            // Les erreurs du moteur portent déjà la consigne pour s'en sortir : les afficher
            // telles quelles, sans pile d'appel, est plus utile à un opérateur.
            $io->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $t) {
            $io->error(get_class($t) . ' : ' . $t->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @throws Throwable
     */
    private function run_seed(InputInterface $input, SymfonyStyle $io): int
    {
        $dry_run    = (bool)$input->getOption('dry-run');
        $only_purge = (bool)$input->getOption('only-purge');
        $purge      = (bool)$input->getOption('purge') || $only_purge;

        // Les combinaisons impossibles sont refusées ICI, avant tout travail : une option ignorée en
        // silence est pire qu'une option refusée, parce que l'opérateur croit l'avoir obtenue.
        $this->assertCoherentOptions($input, $dry_run, $purge);

        // Le DÉCOR d'abord : le profil en dépend pour son arithmétique — les durées de séjour et les
        // bandes d'occupation décident du nombre de séjours qu'une capacité peut soutenir.
        $decor = DecorRegistry::get((string)$input->getOption('decor'));

        $profile = SeedProfile::named(
            (string)$input->getOption('profile'),
            $this->overrides($input),
            $decor
        );

        // Avant tout : un module inactif ne produit pas d'erreur, il produit un graphe troué.
        ModuleGuard::assertActive();

        // L'export du semis PRÉCÉDENT ne sème rien, ne purge rien et ne demande aucune
        // confirmation : il lit. Traité avant tout le reste pour cette raison.
        if ($only_export = $input->getOption('only-export')) {
            $io->title("Seed d'hospitalisation — export de la cohorte précédente");

            $now = $input->getOption('now');

            return $this->export(
                $io,
                (string)$only_export,
                $now === null ? $this->recordedReferenceMoment($io) : (string)$now
            );
        }

        if ($only_purge) {
            // Pas de titre d'échelle ici : annoncer « profil medium, 2000 séjours » avant une purge
            // laisserait croire qu'on est sur le point d'en écrire autant.
            $io->title("Seed d'hospitalisation — purge");
            $this->purge($io, $dry_run);

            return self::SUCCESS;
        }

        $writer_mode = $this->writerMode($input, $profile);

        $this->ensureMemoryLimit($io, $profile);

        $io->title(
            sprintf(
                "Seed d'hospitalisation — profil '%s', %d séjours",
                $profile->name(),
                $profile->sejours()
            )
        );

        // Les ancres sont lues et VALIDÉES avant la purge et la confirmation : un fichier fautif
        // doit se voir avant qu'une base ait été effacée pour lui.
        $anchors = $this->anchors($input, $profile);

        if ($purge) {
            $this->purge($io, $dry_run);
        }

        if (!$dry_run && !$this->confirm($input, $io, $profile)) {
            $io->writeln('Abandon.');

            return self::SUCCESS;
        }

        $seed   = (int)$input->getOption('seed');
        $now    = $input->getOption('now');
        $writer = $this->writer($writer_mode, $dry_run);

        $context = new SeedContext(
            $profile,
            new SeedRandom($seed),
            $writer,
            $now === null ? null : (string)$now,
            $this->ippRegistry($input, $io, $writer, $dry_run),
            $anchors
        );

        if ($writer instanceof BulkWriter) {
            // L'enregistrement de run doit porter de quoi REPRODUIRE le jeu de données, pas
            // seulement de quoi le supprimer : profil, graine et instant de référence.
            $writer->describeRun($profile->name(), $seed, $context->now());
        }

        $started = microtime(true);
        $report  = (new HospiSeeder($context))->run();
        $elapsed = round(microtime(true) - $started, 1);

        $rows = [];
        foreach ($report->lines() as $label => $value) {
            $rows[] = [$label, (string)$value];
        }
        $rows[] = ['graine', (string)$seed];
        $rows[] = ['durée', $elapsed . ' s'];

        $io->section($dry_run ? 'Simulation (rien n\'a été écrit)' : 'Jeu de données produit');
        $io->table(['', ''], $rows);

        if ($io->isVerbose()) {
            $counts = [];
            foreach ($report->counts() as $class => $count) {
                $counts[] = [$class, (string)$count];
            }
            $io->table(['Classe', 'Objets'], $counts);
        }

        if (!$report->occupancyInBand()) {
            // Ce n'est pas un échec d'écriture, mais le jeu de données n'est pas plausible : autant
            // le dire, c'est le premier chiffre qu'un formateur regarde.
            $io->warning(
                "L'occupation instantanée est de {$report->occupancyRate()} %, hors de la bande visée."
                . ' Le jeu de données est écrit mais son taux d\'occupation n\'est pas crédible :'
                . ' ajuster --lits ou --sejours.'
            );
        }

        if ($report->hasUnplacedAnchors()) {
            // Pas un écart mais un ÉCHEC du service rendu : le dossier qu'une consigne d'exercice
            // cite nommément serait le seul dossier vide de l'établissement.
            $io->warning(
                count($report->unplacedAnchors()) . ' patient(s) ancré(s) n\'ont obtenu aucun'
                . ' séjour : leur dossier est une identité sans parcours, alors que c\'est'
                . ' précisément celui qu\'un scénario cite. Augmenter --sejours, ou relâcher la'
                . ' colonne « service » du fichier d\'ancres.'
            );
        }

        if ($dry_run) {
            $io->note(
                'Simulation : les builders ont tourné pour de vrai et les comptes ci-dessus sont'
                . ' ceux d\'un vrai run. En revanche aucun store() n\'a eu lieu, donc AUCUNE règle'
                . ' métier du socle n\'a été appliquée : un dry-run confirme l\'échelle, pas que la'
                . ' base acceptera le résultat.'
            );

            return self::SUCCESS;
        }

        if ($export = $input->getOption('export')) {
            $this->export($io, (string)$export, $context->now());
        }

        // ⚠ « À L'IDENTIQUE » N'ÉTAIT PAS VRAI, ET LA PHRASE LE PROMETTAIT SANS RÉSERVE. Les
        // paramètres ne suffisent pas : le jeu d'identités est un FICHIER DU DÉPÔT, donc une
        // livraison peut le changer entre deux semis de mêmes paramètres.
        //
        // Mesuré par infra sur la production le 2026-10-04 : deux semis de même décor, même graine
        // et même instant de référence ont donné 16 560 noms différents sur 8 324 patients, les
        // dates de naissance ne bougeant qu'à la marge — flux aléatoire identique, table
        // d'identités changée, un déploiement entre les deux. Quelqu'un qui garde une cohorte comme
        // témoin conclut à une dérive de données là où il y a eu une livraison.
        //
        // L'empreinte rend donc la promesse VÉRIFIABLE : deux semis sont comparables si et
        // seulement si leurs quatre paramètres ET leur empreinte coïncident.
        $this->recordRun($io, $decor->name(), $profile->name(), $seed, $context->now());

        $io->success(
            sprintf(
                'Jeu de données écrit. Rejouable à l\'identique AVEC LA MÊME EMPREINTE'
                . ' D\'IDENTITÉS (%s) : ox-seed:hospi --decor=%s --profile=%s --seed=%d'
                . ' --now="%s". Une livraison qui modifie le jeu d\'identités change l\'empreinte,'
                . ' et les mêmes paramètres produisent alors d\'autres personnes — les mêmes IPP,'
                . ' mais pas les mêmes patients derrière.',
                FictitiousNames::fingerprint(),
                $decor->name(),
                $profile->name(),
                $seed,
                $context->now()
            )
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     * @throws SeedException
     */
    /**
     * Dépose un relevé de ce qui vient d'être semé, à côté de l'export.
     *
     * ⚠ POURQUOI PAS LE MARQUEUR DE L'ENTRYPOINT. `files/seed/seed-boot.state` remplit son rôle de
     * GARDE — il empêche un second semis au démarrage — mais il n'est écrit QUE par l'entrypoint.
     * Un semis lancé à la main ne le touche pas, si bien qu'il continue d'annoncer le précédent.
     * Mesuré par infra sur la production le 2026-10-04 : le marqueur disait
     * « completed 2026-10-03T10:53:16Z » alors que la base portait le semis du lendemain — une
     * cohorte qui n'existait plus.
     *
     * Même forme que les autres défauts de la journée : un marqueur qui répond « un semis s'est
     * achevé » lu comme « voici lequel ». Il répond juste à une autre question.
     *
     * ÉCRIT PAR LA COMMANDE, donc juste quel que soit le chemin — démarrage ou main. Il ne remplace
     * pas la garde, il répond à la question que la garde ne pose pas : QUEL jeu est en base.
     *
     * ⚠ CE QU'IL N'EST PAS : une source d'autorité. Rien ne garantit qu'il décrive encore la base —
     * une purge, un semis par un autre chemin ou une restauration le laisseraient en place. La
     * seule réponse autoritaire vivrait DANS la base, et la carte ouverte à ce sujet documente le
     * piège : `hasBulkRun()` fait refuser la purge dès qu'une ligne `seed_run` existe, donc y
     * enregistrer tous les semis casserait la purge ordinaire.
     *
     * L'échec d'écriture est signalé et n'interrompt rien : le jeu de données est déjà écrit, et
     * perdre le relevé est moins grave que faire échouer une commande qui a réussi.
     *
     * @throws SeedException
     */
    private function recordRun(
        SymfonyStyle $io,
        string $decor,
        string $profile,
        int $seed,
        string $now
    ): void {
        // LA RACINE VIENT DE LA CONFIGURATION, comme ailleurs dans cette commande (cf. le tag IPP) :
        // cette classe n'a pas de propriété `root`, et la déduire du chemin du fichier serait fragile
        // dès qu'on déplace la classe.
        $directory = rtrim((string)CAppUI::conf('root_dir'), '/') . '/files/seed';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            $io->warning("Relevé du semis non écrit : {$directory} inaccessible.");

            return;
        }

        $record = [
            'date'                => CMbDT::dateTime(),
            'decor'               => $decor,
            'profile'             => $profile,
            'seed'                => $seed,
            'now'                 => $now,
            'empreinte_identites' => FictitiousNames::fingerprint(),
            'composants'          => FictitiousNames::fingerprintParts(),
        ];

        $path    = $directory . '/dernier-semis.json';
        $written = @file_put_contents(
            $path,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        if ($written === false) {
            $io->warning("Relevé du semis non écrit : {$path} non inscriptible.");

            return;
        }

        $io->writeln(" Relevé déposé dans <info>{$path}</info>.");
    }

    private function overrides(InputInterface $input): array
    {
        $map = [
            'services' => 'services',
            'lits'     => 'beds',
            'patients' => 'patients',
            'sejours'  => 'sejours',
            'window'   => 'window',
        ];

        $overrides = [];
        foreach ($map as $option => $key) {
            $value = $input->getOption($option);

            if ($value === null) {
                continue;
            }

            if (!ctype_digit((string)$value) || (int)$value < 1) {
                throw new SeedException("--{$option} attend un entier positif, reçu '{$value}'");
            }

            $overrides[$key] = (int)$value;
        }

        return $overrides;
    }

    /**
     * @throws SeedException
     */
    private function writerMode(InputInterface $input, SeedProfile $profile): string
    {
        $mode = (string)$input->getOption('writer');

        if ($mode === 'auto') {
            return $profile->defaultWriter();
        }

        if (!in_array($mode, [SeedProfile::WRITER_ORM, SeedProfile::WRITER_BULK], true)) {
            throw new SeedException("--writer attend auto|orm|bulk, reçu '{$mode}'");
        }

        return $mode;
    }

    /**
     * @throws SeedException
     */
    private function writer(string $mode, bool $dry_run): SeedWriter
    {
        if ($dry_run) {
            // Sans conserver les objets : sur un profil xl, tout garder en mémoire n'a pas de sens.
            return new CollectingWriter(false);
        }

        if ($mode === SeedProfile::WRITER_BULK) {
            return new BulkWriter(self::SEED_MARKER);
        }

        return new OrmWriter(self::SEED_MARKER);
    }

    /**
     * @throws Throwable
     */
    private function purge(SymfonyStyle $io, bool $dry_run): void
    {
        $purger = new SeedPurger(self::SEED_MARKER);
        $count  = $purger->count();
        $runs   = count($purger->bulkRuns());

        if ($count === 0 && $runs === 0) {
            $io->writeln('Aucun jeu de données de seed antérieur à purger.');

            return;
        }

        if ($dry_run) {
            $io->writeln("Simulation : {$count} objet(s) tracés et {$runs} run(s) par lots seraient purgés.");

            return;
        }

        $io->writeln("Purge du jeu de données antérieur ({$count} objet(s) tracés, {$runs} run(s) par lots)…");

        // purgeAll() et non purge() : les entités de masse d'un run par lots ne sont pas tracées
        // objet par objet, elles se suppriment par plages d'identifiants.
        $purged = $purger->purgeAll();
        $purger->assertClean();

        $io->writeln("  {$purged} objet(s) supprimés par l'ORM, {$purger->bulkDeleted()} ligne(s) par plages.");
    }

    /**
     * Élève la limite mémoire du processus à ce que l'échelle demandée exige.
     *
     * POURQUOI LA COMMANDE S'EN CHARGE elle-même, plutôt que de laisser l'exploitant y penser :
     * dépasser la limite ne produit pas une erreur rattrapable mais une ERREUR FATALE, qui tue le
     * processus au milieu de l'écriture et laisse une base à moitié peuplée — l'état le plus
     * coûteux, puisqu'il faut purger avant de réessayer. Et le message part sur stderr, que bien
     * des enveloppes d'exécution écartent : l'opérateur ne voit qu'un code 255.
     *
     * Les seuils viennent de mesures, pas d'intuitions. L'image de production livre 128 Mo, et un
     * semis de 1 212 objets y mourait ; le profil `medium` (environ 71 000 objets) dépassait 1 Go
     * avant que le cache d'objets du socle ne soit vidé périodiquement.
     *
     * Une limite déjà supérieure n'est jamais abaissée, et `-1` (illimité) est respecté.
     */
    private function ensureMemoryLimit(SymfonyStyle $io, SeedProfile $profile): void
    {
        $floor   = self::memoryFloorFor($profile->estimatedObjects());
        $current = (string)ini_get('memory_limit');

        if ($current === '-1' || self::bytes($current) >= self::bytes($floor)) {
            return;
        }

        ini_set('memory_limit', $floor);

        if ((string)ini_get('memory_limit') !== $floor) {
            // Limite imposée de l'extérieur : le dire, avec le remède, plutôt que de laisser le
            // processus mourir sans explication à mi-parcours.
            $io->warning(
                "La limite mémoire est de {$current} et n'a pas pu être élevée à {$floor}, qu'un"
                . ' semis d\'environ ' . $profile->estimatedObjects() . ' objets demande.'
                . ' Il risque de s\'interrompre par une erreur fatale, en laissant la base à moitié'
                . " peuplée.\nRelancer avec « php -d memory_limit={$floor} bin/console … »."
            );

            return;
        }

        $io->text("Limite mémoire élevée de {$current} à {$floor} pour cette échelle.");
    }

    /**
     * Plancher de mémoire pour un volume estimé.
     */
    private static function memoryFloorFor(int $objects): string
    {
        foreach (self::MEMORY_FLOORS as $ceiling => $floor) {
            if ($objects <= $ceiling) {
                return $floor;
            }
        }

        return self::MEMORY_FLOOR_MAX;
    }

    /**
     * Une valeur de `memory_limit` en octets. Accepte les suffixes K, M et G du format PHP.
     */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        $unit  = strtoupper(substr($value, -1));
        $size  = (int)$value;

        switch ($unit) {
            case 'G':
                return $size * 1024 * 1024 * 1024;
            case 'M':
                return $size * 1024 * 1024;
            case 'K':
                return $size * 1024;
            default:
                return $size;
        }
    }

    /**
     * Refuse les combinaisons d'options qui ne peuvent rien produire.
     *
     * @throws SeedException
     */
    private function assertCoherentOptions(InputInterface $input, bool $dry_run, bool $purge): void
    {
        if ($dry_run && $input->getOption('export')) {
            throw new SeedException(
                '--export et --dry-run sont incompatibles : la simulation n\'écrit rien, il n\'y'
                . ' aurait donc aucune cohorte à relire. Pour exporter sans semer, employer'
                . ' --only-export=<fichier>, qui exporte le semis précédent.'
            );
        }

        if ($input->getOption('only-export') === null) {
            return;
        }

        if ($purge) {
            throw new SeedException(
                '--only-export et --purge sont incompatibles : la purge défait justement la cohorte'
                . " qu'il s'agit d'exporter. Exporter d'abord, purger ensuite."
            );
        }

        // Ces options décrivent un semis, et --only-export n'en fait pas : les accepter en les
        // ignorant laisserait croire qu'elles ont servi.
        // `now` n'y figure pas : il sert à l'export pour situer les colonnes « actuel ».
        foreach (['ancres', 'export', 'patients', 'sejours', 'lits', 'services', 'window'] as $option) {
            if ($input->getOption($option) !== null) {
                throw new SeedException(
                    "--only-export n'écrit rien : --{$option} n'aurait aucun effet."
                    . ' Employer --export=<fichier> pour exporter à la fin d\'un semis.'
                );
            }
        }
    }

    /**
     * Lit le fichier d'ancres, et vérifie qu'il tient dans le volume demandé.
     *
     * Les ancrés sont COMPTÉS dans `--patients` et non ajoutés par-dessus : `--patients=1000` en
     * produit mille, dont les ancrés. En demander plus que le profil n'en prévoit est donc refusé
     * plutôt que silencieusement arrondi vers le haut — sinon le dimensionnement mentirait, et
     * l'occupation calculée avec lui aussi.
     *
     * @throws SeedException
     */
    private function anchors(InputInterface $input, SeedProfile $profile): AnchoredCohort
    {
        $path = $input->getOption('ancres');

        if ($path === null) {
            return AnchoredCohort::none();
        }

        $cohort = AnchoredCohort::fromCsv((string)$path);

        if ($cohort->count() > $profile->patients()) {
            throw new SeedException(
                $cohort->count() . ' patients ancrés pour un profil qui n\'en prévoit que '
                . $profile->patients() . '. Les ancrés sont comptés dans le total, pas ajoutés'
                . ' par-dessus : augmenter --patients.'
            );
        }

        return $cohort;
    }

    /**
     * Le registre d'IPP, ou null si l'opérateur n'en veut pas.
     *
     * S'assure au passage que l'instance AFFICHE les IPP. Sans la configuration
     * « dPpatients CPatient tag_ipp », `CPatient::getTagIPP()` rend null et aucun écran ne montre
     * d'identifiant : le seed aurait écrit un travail invisible. La configuration n'est posée que
     * lorsqu'elle est VIDE — une instance qui a déjà sa convention d'IPP la garde, et le seed s'y
     * conforme.
     *
     * Rien n'est écrit en simulation : un dry-run ne doit pas modifier includes/config.php.
     *
     * @throws SeedException
     */
    private function ippRegistry(
        InputInterface $input,
        SymfonyStyle $io,
        SeedWriter $writer,
        bool $dry_run
    ): ?IppRegistry {
        if ($input->getOption('no-ipp')) {
            $io->warning(
                'Aucun IPP ne sera attribué (--no-ipp). Les dossiers produits n\'auront pas'
                . ' d\'identifiant permanent, et aucun système extérieur ne pourra désigner ces'
                . ' patients autrement que par leur nom — ce qui échoue dès qu\'il y a deux homonymes.'
            );

            return null;
        }

        $prefix = (string)$input->getOption('ipp-prefix');
        $start  = (string)$input->getOption('ipp-start');

        if (!ctype_digit($start) || (int)$start < 1) {
            throw new SeedException("--ipp-start attend un entier positif, reçu '{$start}'");
        }

        if ($prefix === '') {
            throw new SeedException('--ipp-prefix ne peut pas être vide.');
        }

        // Une simulation ne touche PAS à includes/config.php : elle n'écrit rien, et un dry-run qui
        // modifierait la configuration de l'instance serait une surprise désagréable.
        if (!$dry_run && !IppRegistry::instanceDisplaysIpp()) {
            $tag = IppRegistry::declareTagInConfig((string)CAppUI::conf('root_dir'));

            if ($tag !== null) {
                $io->text(
                    'Configuration « dPpatients CPatient tag_ipp » posée à « ' . $tag . ' » :'
                    . ' sans elle, aucun écran n\'affiche d\'IPP.'
                );
            }
        }

        return new IppRegistry($writer, $prefix, (int)$start, $dry_run);
    }

    /**
     * L'instant de référence du dernier semis enregistré, ou null.
     *
     * POURQUOI LE CHERCHER, plutôt que de prendre l'heure courante : les colonnes « actuel » de
     * l'export désignent le séjour EN COURS à un instant donné, et le jeu de données a été construit
     * autour de l'instant de référence du semis — que `--now` peut avoir fixé loin de maintenant.
     * Exporter à l'heure de la machine rend alors des colonnes qui contredisent le même export fait
     * en fin de semis.
     *
     * Constaté en comparant les deux chemins : un patient hospitalisé en chirurgie orthopédique à
     * l'instant de référence ressortait sans unité actuelle deux heures plus tard, sous le même IPP.
     * Deux fichiers censés décrire la même cohorte, qui se contredisent — exactement ce qu'un
     * consommateur ne peut pas démêler.
     *
     * L'instant n'est enregistré que pour les runs écrits PAR LOTS (`CSeedRun`), c'est-à-dire au-delà
     * du seuil de volume. En dessous, il n'y a rien à lire : on le dit, plutôt que de laisser croire
     * que les colonnes « actuel » sont celles du semis.
     *
     * @throws Throwable
     */
    private function recordedReferenceMoment(SymfonyStyle $io): ?string
    {
        $runs = (new SeedPurger(self::SEED_MARKER))->bulkRuns();
        $run  = reset($runs);

        if (!$run || !$run->reference_date) {
            $io->note(
                'Aucun instant de référence enregistré pour le semis précédent : les colonnes'
                . ' « service_actuel » et « motif_actuel » sont calculées à l\'heure courante, et'
                . ' peuvent donc différer de celles qu\'un export fait en fin de semis aurait'
                . ' rendues. Employer --now="<instant du semis>" pour les faire coïncider.'
            );

            return null;
        }

        $io->text("Instant de référence du semis précédent : {$run->reference_date}.");

        return (string)$run->reference_date;
    }

    /**
     * Écrit la cohorte du semis tracé dans un fichier CSV.
     *
     * @throws Throwable
     */
    private function export(SymfonyStyle $io, string $path, ?string $now = null): int
    {
        // L'instant de référence du semis, quand il y en a un : les colonnes « actuel » désignent le
        // séjour en cours À CET INSTANT, et le jeu de données a été construit autour de lui. Employer
        // l'heure de la machine rendrait l'export d'un semis daté (--now) incohérent avec lui.
        $export = new CohortExport(self::SEED_MARKER, $now);
        $count  = $export->toFile($path);

        $io->text("Cohorte exportée : {$count} patient(s) dans {$path}");

        // LES MANQUES SONT DITS. Une ligne sans IPP est inutilisable pour un rapprochement entre
        // systèmes, et c'est exactement l'usage du fichier : la taire en ferait une fausse piste.
        if ($export->withoutIpp() > 0) {
            $io->warning(
                $export->withoutIpp() . ' patient(s) exporté(s) SANS IPP : ces lignes ne permettent'
                . ' aucun rapprochement entre systèmes. Ils ont probablement été semés avec'
                . ' --no-ipp, ou avant que le tag d\'IPP ne soit configuré.'
            );
        }

        if ($export->withoutSejour() > 0) {
            $io->warning(
                $export->withoutSejour() . ' patient(s) exporté(s) sans séjour : leur dossier ne'
                . ' porte qu\'une identité. Les colonnes service, motif_sejour et date_entree sont'
                . ' vides pour eux.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * NE JAMAIS POSER DE QUESTION QU'AUCUN HUMAIN NE PEUT ENTENDRE.
     *
     * Signalé par un environnement de cours (déploiement multi-conteneur) : son provisionneur lance le semis par
     * « docker exec -d », donc sans stdin, et la commande affichait quand même
     * « Continuer ? (yes/no) [yes]: ». Le semis est passé — et c'est ce qui rend le défaut
     * dangereux, parce qu'il n'est passé que par accident.
     *
     * Le mécanisme, dans symfony/console v5.4.12 : `Application::configureIO()` ne rend l'entrée
     * non interactive que pour `--no-interaction`/`-n` ou `-q`. La détection de terminal des
     * versions 3.x a été RETIRÉE, donc sans `-n` l'entrée reste « interactive » alors que stdin est
     * /dev/null. `QuestionHelper::ask()` lit ce flux mort, attrape la `MissingInputException` et se
     * rabat sur le DÉFAUT de la question — mais il la RELANCE si la question n'en a pas.
     *
     * Autrement dit, la robustesse d'un semis automatisé reposait sur le fait que
     * `confirm('Continuer ?', true)` avait un défaut à `true`. Changer ce défaut, ou ajouter
     * ailleurs une question qui n'en a pas, suffirait à faire mourir le provisionneur sur un
     * « Aborted. » muet sur sa cause.
     *
     * On bascule donc l'entrée en non interactive DÈS L'ENTRÉE DANS execute(), avant toute branche,
     * pour que la règle vaille aussi pour les questions qui seront ajoutées plus tard.
     */
    private function disableInteractionWithoutTerminal(InputInterface $input, SymfonyStyle $io): void
    {
        if (!$input->isInteractive() || $this->stdinIsTerminal()) {
            return;
        }

        $input->setInteractive(false);

        $io->writeln(
            'Entrée non interactive (stdin n\'est pas un terminal) : aucune question ne sera posée.'
        );
    }

    /**
     * Demande-t-on confirmation ?
     *
     * Deux conditions, et les deux sont nécessaires : `--no-interaction` doit être respecté même
     * sur un terminal, et un terminal doit exister pour qu'une question ait un sens.
     */
    protected function shouldAskForConfirmation(InputInterface $input): bool
    {
        return $input->isInteractive() && $this->stdinIsTerminal();
    }

    /**
     * Stdin est-il un terminal ?
     *
     * Isolé dans une méthode surchargeable parce qu'un test ne peut pas se fabriquer un terminal.
     */
    protected function stdinIsTerminal(): bool
    {
        if (!defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return (bool)@stream_isatty(STDIN);
        }

        if (function_exists('posix_isatty')) {
            return (bool)@posix_isatty(STDIN);
        }

        // Ni l'une ni l'autre : on considère qu'il n'y a PAS de terminal. Se taire et continuer est
        // préférable à poser une question dont on ne saura pas si quelqu'un l'a lue.
        return false;
    }

    /**
     * Confirmation avant écriture.
     *
     * Le seed écrit des milliers d'objets et SUPPOSE l'absence d'écritures concurrentes : ce n'est
     * pas une commande à lancer sur une instance en service. Mieux vaut le demander une fois de
     * trop que de peupler la base d'un établissement par erreur.
     */
    private function confirm(InputInterface $input, SymfonyStyle $io, SeedProfile $profile): bool
    {
        $io->writeln(
            sprintf(
                'À écrire : environ %d objets (%d patients, %d séjours, %d lits).',
                $profile->estimatedObjects(),
                $profile->patients(),
                $profile->sejours(),
                $profile->beds()
            )
        );
        $io->writeln(
            'Le seed suppose qu\'aucune autre écriture n\'a lieu pendant son exécution :'
            . ' à ne pas lancer sur une instance en service.'
        );

        if (!$this->shouldAskForConfirmation($input)) {
            // Le récapitulatif ci-dessus reste écrit : c'est lui qui, dans le journal d'un semis
            // automatisé, dit ce qui a été écrit et sous quelles hypothèses.
            $io->writeln(
                'Aucun terminal pour répondre : poursuite SANS confirmation.'
                . ' Utiliser --dry-run pour contrôler avant d\'écrire.'
            );

            return true;
        }

        return $io->confirm('Continuer ?', true);
    }
}
