<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Ox\Cli\Console\Simulation\CCrisisScenario;
use Ox\Cli\Console\Simulation\CSimulationClock;
use Ox\Cli\Console\Simulation\CSimulationConfig;
use Ox\Cli\Console\Simulation\CSimulationLock;
use Ox\Cli\Console\Simulation\CSimulationPicker;
use Ox\Cli\Console\Simulation\CSimulationState;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Ox\Core\CMbDT;
use Ox\Core\CMbObject;
use Hospilibre\Bloc\CBlocOperatoire;
use Hospilibre\Bloc\CSalle;
use Hospilibre\Hospi\CAffectation;
use Hospilibre\Hospi\CLit;
use Hospilibre\Hospi\CService;
use Hospilibre\Patients\CConstantesMedicales;
use Hospilibre\Populate\Generators\CAffectationGenerator;
use Hospilibre\Populate\Generators\COperationGenerator;
use Hospilibre\Populate\Generators\CConsultationGenerator;
use Hospilibre\Populate\Generators\CPatientGenerator;
use Hospilibre\Populate\Generators\CPlageconsultGenerator;
use Hospilibre\Populate\Generators\CSejourGenerator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Fait vivre l'hôpital fictif dans le temps : à chaque avance d'horloge, génère
 * l'activité clinique/administrative journalière (sorties, arrivées, transferts,
 * consultations, bloc, constantes, codage) sur l'état existant de l'établissement,
 * de façon idempotente. L'horloge n'avance qu'après succès complet de l'intervalle.
 */
class SimulateAdvance extends HospilibreCommand
{
    private SymfonyStyle $io;
    private CSimulationConfig $cfg;
    /** Config du jour simulé : = $cfg hors crise (la crise multiplie les débits, Task 7). */
    private CSimulationConfig $dayCfg;
    /** @var array<int,float> service_id => poids d'admission */
    private array $serviceWeights = [];
    private int $gid;

    /** @var array<string,int> compteurs par flux piloté, agrégés sur tout le run.
     *  Le codage (actes NGAP/CCAM) est produit automatiquement par les générateurs
     *  de consultation/séjour/opération : il n'a pas de compteur ici, il est vérifié
     *  par comptage SQL en e2e (Task 11). */
    private array $counters = [
        'sorties' => 0, 'arrivees' => 0, 'echecs_arrivees' => 0, 'transferts' => 0,
        'consultations' => 0, 'operations' => 0, 'constantes' => 0,
        'refus' => 0, 'deprog_operations' => 0, 'deprog_consultations' => 0,
        'lits_ouverts' => 0, 'lits_fermes' => 0,
    ];

    private ?CCrisisScenario $crisis = null;
    private ?string $crisisStart = null;
    /** Jour relatif au début de la crise (-1 hors crise). */
    private int $crisisRel = -1;
    /** @var array{deprogrammer_operations:float, deprogrammer_consultations:float, refus_admissions:float} */
    private array $effects = ['deprogrammer_operations' => 0.0, 'deprogrammer_consultations' => 0.0, 'refus_admissions' => 0.0];
    /** @var int[] service_id des services cibles de la crise */
    private array $crisisServiceIds = [];

    /** @var \Hospilibre\Hospi\CLit[] */        private array $beds = [];
    /** @var \Hospilibre\Hospi\CLit[] */        private array $freeBeds = [];
    /** @var \Hospilibre\Mediusers\CMediusers[] */ private array $praticiens = [];
    /** @var \Hospilibre\PlanningOp\CSejour[] */ private array $sejours = [];
    /** @var \Hospilibre\Patients\CPatient[] */  private array $patients = [];
    /** @var CSalle[] */ private array $salles = [];
    private bool $sallesLoaded = false;

    protected function configure(): void
    {
        $this
            ->setName('simulate:advance')
            ->setDescription("Fait vivre l'hôpital fictif : génère l'activité journalière et avance l'horloge")
            ->addOption('by', null, InputOption::VALUE_OPTIONAL, "Intervalle relatif, ex. \"7 days\", \"12 hours\"")
            ->addOption('until', null, InputOption::VALUE_OPTIONAL, "Cible absolue (dateTime) ou \"now\"")
            ->addOption('seed', null, InputOption::VALUE_OPTIONAL, 'Graine mt_srand pour un run reproductible')
            ->addOption('config', null, InputOption::VALUE_OPTIONAL, 'Fichier de config hôpital (débits inclus)')
            ->addOption('max-catchup-days', null, InputOption::VALUE_OPTIONAL, 'Plafond de rattrapage en jours (0 = illimité) : au-delà, le trou est sauté et journalisé', '0')
            ->addOption('max-days', null, InputOption::VALUE_OPTIONAL, 'Taille maximale de la tranche simulée par ce processus, en jours (0 = illimité) ; relancer jusqu\'à rattrapage', '0')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Racine du projet', dirname(__DIR__, 3) . '/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // La commande charge un graphe de classes lourd (CSejour/COperation…) :
        // 128M (défaut CLI) provoque un OOM. On relève à 512M si nécessaire pour
        // que le tick cron ne meure pas silencieusement.
        $current = trim((string) ini_get('memory_limit'));
        if ($current !== '-1') {
            $bytes = (int) $current;
            $unit  = strtolower((string) substr($current, -1));
            if ($unit === 'g') { $bytes *= 1024 * 1024 * 1024; }
            elseif ($unit === 'm') { $bytes *= 1024 * 1024; }
            elseif ($unit === 'k') { $bytes *= 1024; }
            if ($bytes < 512 * 1024 * 1024) {
                ini_set('memory_limit', '512M');
            }
        }

        $this->io = new SymfonyStyle($input, $output);
        $t0       = microtime(true);
        $root     = rtrim($input->getOption('path'), '/');
        $this->bootstrap($root);

        // Une seule écriture de simulation à la fois (rattrapage du boot, tick, purge) : un second
        // passage sort sans rien faire au lieu de régénérer les mêmes jours en double.
        $lock = CSimulationLock::forRoot($root);
        if (!$lock->acquire()) {
            $this->io->writeln('Une avance de simulation est déjà en cours : rien à faire.');
            return self::SUCCESS;
        }
        try {
            return $this->advance($input, $t0);
        } finally {
            $lock->release();
        }
    }

    private function advance(InputInterface $input, float $t0): int
    {
        $state = new CSimulationState();
        $state->ensureTable();

        if (!$state->isBootstrapped()) {
            $this->io->writeln('Aucun hôpital fictif : lancer simulate:bootstrap d\'abord.');
            return self::SUCCESS;
        }
        $this->gid = (int) $state->getGroupId();

        if (($seed = $input->getOption('seed')) !== null) {
            mt_srand((int) $seed);
        }

        $this->cfg    = $this->loadConfig($input, $state);
        $this->dayCfg = $this->cfg;

        // Résolution de l'intervalle (from, to].
        $from = $state->getClock() ?? $state->getBootstrappedAt() ?? CMbDT::dateTime();
        $to   = $this->resolveTarget($input, $from);

        $maxCatchup = (int) $input->getOption('max-catchup-days');
        $capped     = CSimulationClock::capFrom($from, $to, $maxCatchup);
        if ($capped !== $from) {
            $this->io->warning("Trou de simulation non rattrapé : $from → $capped (plafond $maxCatchup j). "
                . 'Aucune activité générée sur cette période.');
            $state->setClock($capped);
            $from = $capped;
        }
        $to = CSimulationClock::limitTarget($from, $to, (int) $input->getOption('max-days'));

        $days = CSimulationClock::daySteps($from, $to);
        if ($days === []) {
            $this->io->writeln("Rien à avancer (horloge = $from, cible = $to).");
            return self::SUCCESS;
        }

        $this->io->title("Avance de simulation : $from → $to (" . count($days) . ' jour(s))');

        $this->loadWorld();
        $this->loadCrisis($state);

        try {
            $n = 0;
            foreach ($days as $day) {
                $this->runDay($day);
                // Horloge avancée JOUR PAR JOUR : un run interrompu (conteneur tué, OOM) ne rejoue
                // au plus que le jour partiel en cours, jamais les jours déjà terminés.
                $state->setClock(CSimulationClock::clockAfterDay($day, $to));
                $this->io->writeln(sprintf('jour %d/%d (%s) simulé', ++$n, count($days), $day));
            }
            $state->setClock($to);
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());
            return self::FAILURE;
        }

        $this->io->success(sprintf(
            "Simulation avancée jusqu'à %s — %d jour(s) en %.1f s, pic mémoire PHP %.0f Mo",
            $to, count($days), microtime(true) - $t0, memory_get_peak_usage(true) / 1048576
        ));
        $this->io->table(['Flux', 'Créés'], array_map(
            static fn ($k, $v) => [$k, (string) $v],
            array_keys($this->counters),
            array_values($this->counters)
        ));

        return self::SUCCESS;
    }

    /** Résout la cible `to` à partir de --until (prioritaire) ou --by, défaut "+1 day". */
    private function resolveTarget(InputInterface $input, string $from): string
    {
        $until = $input->getOption('until');
        if ($until !== null) {
            return $until === 'now' ? CMbDT::dateTime() : (string) $until;
        }
        $by = $input->getOption('by') ?: '1 day';

        return CMbDT::dateTime('+' . ltrim((string) $by, '+'), $from);
    }

    /** --config explicite, sinon le profil enregistré au semis (l'environnement ne gouverne pas :
     *  un conteneur relancé avec un autre HOSPITAL continue l'hôpital déjà semé). */
    private function loadConfig(InputInterface $input, CSimulationState $state): CSimulationConfig
    {
        try {
            return CSimulationConfig::resolveForAdvance($input->getOption('config'), $state->getConfigName());
        } catch (\InvalidArgumentException $e) {
            $this->io->warning($e->getMessage() . ' — repli sur le profil « moyen ».');
            return CSimulationConfig::fromProfile('moyen');
        }
    }

    /** Exécute les flux d'un jour, dans l'ordre spec : sorties, arrivées, transferts
     *  (Task 9), consultations, bloc, constantes (Task 10). */
    private function runDay(string $day): void
    {
        // Chaque flux est isolé : une erreur métier ponctuelle sur un flux est journalisée
        // et n'interrompt NI les autres flux du jour NI le reste de l'intervalle (un long
        // rattrapage ne meurt plus sur un cas limite isolé). L'ordre reste celui du spec.
        // Débits et effets du jour (nominal, ou profil × courbe de crise), puis capacité en tête :
        // les lits ouverts/fermés par la crise valent pour toute la journée.
        $this->prepareDay($day);
        foreach (['capacite', 'sorties', 'arrivees', 'transferts', 'consultations', 'bloc', 'constantes'] as $flux) {
            try {
                $this->{$flux}($day);
            } catch (Throwable $e) {
                $this->io->warning("Flux $flux ($day) ignoré : " . $e->getMessage());
            }
        }
    }

    /** Sorties du jour : transition d'état directe (pas de générateur) sur les séjours
     *  en cours dont la sortie prévue tombe ce jour (ou avant, rattrapage). Clôture
     *  l'affectation courante (pose sa `sortie`) et libère le lit. */
    private function sorties(string $day): void
    {
        $dayEnd = "$day 23:59:59";
        foreach ($this->sejours as $id => $sejour) {
            if (!$sejour->sortie_prevue || $sejour->sortie_prevue > $dayEnd) {
                continue;
            }
            $sortie = $sejour->sortie_prevue;
            if (!$sejour->entree_reelle) {
                // Séjours "en cours" posés par simulate:bootstrap sans passer par
                // CSejourGenerator (affectation directe des propriétés, cf.
                // SimulateBootstrap) : seule entree_prevue y est renseignée.
                // CSejour::check() refuse une sortie_reelle sans entree_reelle
                // (règle CSejour.no_entree_relle_for_sortie) : un séjour "en cours"
                // a par définition déjà été admis, on rattrape l'entrée réelle.
                $sejour->entree_reelle = $sejour->entree_prevue;
            }
            $sejour->sortie_reelle = $sortie;
            $this->save($sejour, "sortie CSejour $id");

            if ($aff = $this->loadCurrentAffectation((int) $id)) {
                $aff->sortie = $sortie;
                $this->save($aff, "clôture CAffectation séjour $id");
                $this->releaseBedById((int) $aff->lit_id);
            }
            unset($this->sejours[$id]);
            $this->counters['sorties']++;
        }
    }

    /** Arrivées du jour : nouveaux séjours (type comp) via CSejourGenerator injecté
     *  (patient généré, praticien tiré de l'existant, lit pris parmi les lits libres).
     *  Bornées par la capacité en lits : pas de lit libre -> pas d'admission. */
    private function arrivees(string $day): void
    {
        if ($this->praticiens === []) {
            return;
        }
        $base      = $this->cfg->getRateInt('arrivals_per_day', 6);
        $target    = $this->jitter($this->dayCfg->getRateInt('arrivals_per_day', 6));
        $dayRate   = $this->dayCfg->getRateInt('arrivals_per_day', 6);
        // Arrivées de crise = surplus du débit de crise sur le débit nominal, calculé AVANT le bruit
        // (jitter) : sinon un jour revenu au nominal (facteur 1) compte son bruit comme un afflux.
        $extra     = $this->crisisActive() ? min($target, max(0, $dayRate - $base)) : 0;
        $avgStay   = max(1, $this->dayCfg->getRateInt('avg_sejour_days', 5));
        $admission = $this->crisis ? $this->crisis->getAdmission() : null;

        for ($n = 0; $n < $target; $n++) {
            $isCrisis = $n >= $target - $extra;
            $type     = $isCrisis ? $admission['type'] : $this->cfg->getSejourType();
            if ($this->chance($this->effects['refus_admissions'])) {
                $this->refuse($day, $type, $isCrisis ? $admission : null);
                continue;
            }
            $lit = $isCrisis && $this->crisisServiceIds !== []
                ? $this->takeFreeBedIn($this->crisisServiceIds)
                : $this->takeWeightedFreeBed();
            if ($lit === null) {
                if (!$this->crisisActive()) {
                    break; // nominal : capacité atteinte, on n'admet pas au-delà (comportement historique)
                }
                // En crise, la saturation laisse une trace : admission refusée faute de lit.
                $this->refuse($day, $type, $isCrisis ? $admission : null);
                continue;
            }
            $patient = (new CPatientGenerator())->generate();
            if (!$patient || !$patient->_id) {
                // Échec non fatal (voir note plus bas sur CSejourGenerator) : on
                // libère le lit et on rend l'échec visible/mesurable plutôt que
                // de le laisser disparaître dans CApp::log() (no-op sur ce fork).
                $this->counters['echecs_arrivees']++;
                $this->io->warning("Admission ignorée ($day) : génération de patient échouée.");
                $this->freeBeds[] = $lit;
                continue;
            }
            $ambu   = !$isCrisis && $this->chance($this->dayCfg->getRateFloat('ambulatoire_rate', 0.0));
            $entree = "$day 08:00:00";
            if ($ambu) {
                $sortie = "$day 18:00:00"; // séjour de 0 jour ; sorties() le clôt au jour suivant
            } else {
                $stay   = max(1, $avgStay + $this->rand(-2, 2));
                $sortie = CMbDT::dateTime("+$stay days", $entree);
            }
            $prat   = $this->praticiens[$this->rand(0, count($this->praticiens) - 1)];

            $sejour = (new CSejourGenerator())
                ->setType($ambu ? 'ambu' : $type)
                ->setPatient($patient)->setPraticien($prat)
                ->setLit($lit)->setDates($entree, $sortie)->generate();
            if (!$sejour || !$sejour->_id) {
                // Échec non fatal : CPatientGenerator peut réutiliser un patient
                // déjà porteur d'un séjour actif chevauchant (aléa random_int, non
                // maîtrisé par --seed) -> CSejour::store() refuse la collision. Un
                // aléa de peuplement ne doit pas interrompre toute l'avance :
                // on libère le lit et on retente au prochain tirage. L'échec reste
                // néanmoins visible (compteur + warning) : CAppUI::stepAjax(...
                // UI_MSG_WARNING) émis par le générateur est absorbé par
                // CApp::log(), no-op sur ce fork -> sans ceci, zéro signal.
                $this->counters['echecs_arrivees']++;
                $this->io->warning("Admission ignorée ($day) : génération de séjour échouée (collision patient probable).");
                $this->freeBeds[] = $lit;
                continue;
            }

            $this->sejours[$sejour->_id] = $sejour;
            $this->patients[]            = $patient;
            $this->counters['arrivees']++;

            if ($isCrisis) {
                $this->markCrisisAdmission($sejour, $admission, $day);
                if ($admission['intervention']) {
                    $this->operateNow($sejour, $day);
                }
            }
        }
    }

    /** Transferts du jour : une fraction des séjours en cours change de lit/service.
     *  Clôture directe de l'affectation courante (pose sa `sortie` au moment du
     *  transfert) puis nouvelle affectation sur un autre lit libre via
     *  CAffectationGenerator injecté (modes d'entrée/provenance réalistes). Bornée
     *  par la capacité en lits libres, comme arrivees(). */
    private function transferts(string $day): void
    {
        $rate  = $this->dayCfg->getRateFloat('transfer_rate', 0.15);
        $ids   = array_keys($this->sejours);
        $count = (int) round(count($ids) * $rate);
        $done  = []; // séjours déjà transférés ce jour : $moment est fixe par jour
                     // (12:00:00) donc un 2e transfert du même séjour le même jour
                     // fermerait l'affectation qu'il vient de créer au même instant
                     // que son entrée (viole CAffectation.sortie moreThan|entree).

        for ($n = 0; $n < $count; $n++) {
            if ($this->freeBeds === []) {
                break;
            }
            $id = $ids[$this->rand(0, count($ids) - 1)];
            if (isset($done[$id])) {
                continue;
            }
            $sejour = $this->sejours[$id] ?? null;
            if ($sejour === null) {
                continue;
            }
            $newLit = $this->takeFreeBed();
            if ($newLit === null) {
                break;
            }
            $current = $this->loadCurrentAffectation((int) $id);
            if ($current && (int) $current->lit_id === (int) $newLit->_id) {
                $this->freeBeds[] = $newLit;
                continue;
            }
            $moment = "$day 12:00:00";
            if ($current) {
                // _no_synchro : empêche CAffectation::store() de resynchroniser
                // sejour->sortie_prevue d'après cette affectation qu'on clôture
                // (elle deviendrait sinon transitoirement la "dernière" affectation
                // et écraserait sortie_prevue avec $moment). La nouvelle affectation
                // créée juste après, elle, doit synchroniser normalement.
                $current->_no_synchro = true;
                $current->sortie      = $moment;
                $this->save($current, "clôture affectation (transfert séjour $id)");
                $this->releaseBedById((int) $current->lit_id);
            }
            $service = new CService();
            $service->load($newLit->_ref_chambre->service_id);

            $aff = (new CAffectationGenerator())
                ->setSejour($sejour)->setService($service)->setLit($newLit)->setEntree($moment)->generate();
            $this->generated($aff, "CAffectation transfert (séjour $id)");
            $done[$id] = true;
            $this->counters['transferts']++;
        }
    }

    /** Affectation courante d'un séjour = la plus récente (entree max). CAffectation.sortie
     *  est notNull, donc pas de « sortie IS NULL » : on trie par entree DESC. */
    private function loadCurrentAffectation(int $sejourId): ?CAffectation
    {
        $list = (new CAffectation())->loadList(['sejour_id' => "= '$sejourId'"], 'entree DESC');

        return $list ? reset($list) : null;
    }

    /** Remet un lit dans le pool des lits libres, une fois son occupant sorti. */
    private function releaseBedById(int $litId): void
    {
        foreach ($this->beds as $lit) {
            if ((int) $lit->_id === $litId) {
                $this->freeBeds[] = $lit;
                return;
            }
        }
    }

    /** Retire (et retourne) un lit du pool des lits libres ; null si capacité atteinte. */
    private function takeFreeBed(): ?CLit
    {
        return array_shift($this->freeBeds);
    }

    /** Vérifie qu'un générateur a bien produit l'objet (retour null = échec silencieux). */
    private function generated(?CMbObject $obj, string $label): CMbObject
    {
        if (!$obj || !$obj->_id) {
            throw new \RuntimeException("$label : génération échouée (voir avertissements ci-dessus)");
        }

        return $obj;
    }

    /** Consultations sur nos séjours en cours (une plage par praticien et par jour). */
    private function consultations(string $day): void
    {
        if ($this->sejours === [] || $this->praticiens === []) {
            return;
        }
        $target = $this->jitter($this->dayCfg->getRateInt('consults_per_day', 20));
        $ids    = array_keys($this->sejours);
        $plages = []; // [pratId => CPlageconsult] du jour

        for ($n = 0; $n < $target; $n++) {
            $sejour = $this->sejours[$ids[$this->rand(0, count($ids) - 1)]];
            $prat   = $sejour->loadRefPraticien();
            if (!$prat || !$prat->_id) {
                continue;
            }
            $pid = (int) $prat->_id;
            if (!isset($plages[$pid])) {
                $plage = (new CPlageconsultGenerator())
                    ->setGroupId($this->gid)->setPraticien($prat)->setDate($day)->generate();
                $plages[$pid] = $this->generated($plage, "CPlageconsult $pid $day");
            }
            $consult = (new CConsultationGenerator())
                ->setSejour($sejour)->setPlage($plages[$pid])->generate();
            $this->generated($consult, "CConsultation ($day)");
            $this->counters['consultations']++;
            if ($this->chance($this->effects['deprogrammer_consultations'])) {
                $consult->annule           = '1';
                $consult->motif_annulation = 'other';
                $this->save($consult, "déprogrammation CConsultation ($day)");
                $this->counters['deprog_consultations']++;
            }
        }
    }

    /** Salles de bloc du groupe (via les blocs opératoires du groupe).
     *  Le bootstrap (Task 3) crée des `CBlocOperatoire` mais aucune `CSalle` :
     *  sans salle le flux bloc ne peut rien poser. On pose une salle par bloc
     *  à la volée ici (repli pragmatique, hors scope du bootstrap) — au
     *  prochain appel de loadSalles() (même run ou run suivant) ces salles
     *  sont retrouvées par la requête ci-dessus et ne sont pas recréées. */
    /** @return CSalle[] */
    private function loadSalles(): array
    {
        if ($this->sallesLoaded) {
            return $this->salles;
        }
        $this->sallesLoaded = true;
        $blocs = (new CBlocOperatoire())->loadList(['group_id' => "= '$this->gid'"]);
        if (!$blocs) {
            return $this->salles = [];
        }
        $bloc_ids = array_map(static fn ($b) => $b->_id, $blocs);

        $salles = array_values(
            (new CSalle())->loadList(['bloc_id' => \Ox\Core\CSQLDataSource::prepareIn($bloc_ids)])
        );

        if ($salles === []) {
            foreach ($blocs as $bloc) {
                $salle = new CSalle();
                $salle->bloc_id = $bloc->_id;
                $salle->nom     = 'Salle 1';
                $salle->stats   = 0;
                $this->save($salle, "CSalle (bloc $bloc->_id)");
                $salles[] = $salle;
            }
        }

        return $this->salles = $salles;
    }

    /** Interventions de bloc sur nos séjours en cours (une salle/praticien tirés au sort par jour). */
    private function bloc(string $day): void
    {
        $salles = $this->loadSalles();
        if ($salles === [] || $this->sejours === [] || $this->praticiens === []) {
            return;
        }
        $target = $this->jitter($this->dayCfg->getRateInt('operations_per_day', 4));
        $ids    = array_keys($this->sejours);
        for ($n = 0; $n < $target; $n++) {
            $sejour = $this->sejours[$ids[$this->rand(0, count($ids) - 1)]];
            $prat   = $this->praticiens[$this->rand(0, count($this->praticiens) - 1)];
            $salle  = $salles[$this->rand(0, count($salles) - 1)];
            $op = (new COperationGenerator())
                ->init($sejour)->setPraticien($prat)->setSalle($salle)->setDate($day)->generate();
            $this->generated($op, "COperation ($day)");
            $this->counters['operations']++;
            if ($this->chance($this->effects['deprogrammer_operations'])) {
                $op->annulee = '1';
                $op->rques   = trim(($op->rques ?? '') . "\nDéprogrammée (crise)");
                $this->save($op, "déprogrammation COperation ($day)");
                $this->counters['deprog_operations']++;
            }
        }
    }

    /** Constantes médicales du jour sur nos séjours en cours : création directe
     *  (aucun générateur `CConstantesMedicales` en historique), `constantes_per_day`
     *  relevés horodatés dans `$day`, contextualisés sur le séjour (`context_class
     *  = CSejour`, cf. CConstantesMedicales::getProps() enum). */
    private function constantes(string $day): void
    {
        $perStay = max(0, $this->dayCfg->getRateInt('constantes_per_day', 3));
        if ($perStay === 0 || $this->sejours === []) {
            return;
        }
        foreach ($this->sejours as $id => $sejour) {
            for ($k = 0; $k < $perStay; $k++) {
                $c                = new CConstantesMedicales();
                $c->patient_id    = $sejour->patient_id;
                $c->context_class = 'CSejour';
                $c->context_id    = (int) $id;
                // CConstantesMedicales::check() refuse tout datetime > CMbDT::dateTime()
                // (real, pas l'horloge de simulation) : $day est presque toujours "futur"
                // du point de vue de l'horloge réelle (sim_clock avance au-delà de now dès
                // le bootstrap). On borne donc l'horodatage souhaité par "maintenant" réel.
                $wanted           = sprintf('%s %02d:00:00', $day, $this->rand(6, 20));
                $now              = CMbDT::dateTime();
                $c->datetime      = $wanted > $now ? $now : $wanted;
                $c->pouls         = $this->rand(55, 95);
                $c->temperature   = $this->rand(360, 385) / 10; // 36.0–38.5 °C
                // 'ta' n'est pas un champ plain : CConstantesMedicales::updatePlainFields()
                // le recalcule TOUJOURS à partir de ses `formfields` (_ta_systole/_ta_diastole)
                // et écrase silencieusement toute valeur assignée directement à `ta` (vérifié
                // en DB : `ta` restait vide malgré l'assignation directe). On renseigne donc
                // les composantes via `setFormField()` (accès dynamique, comme la classe elle-
                // même le fait sur `$this->$_formfield` dans updatePlainFields() — ces deux
                // champs ne sont ni déclarés `public` ni documentés `@property` sur le modèle,
                // donc jamais accessibles par propriété littérale sans modifier ce fichier tiers).
                $this->setFormField($c, '_ta_systole', $this->rand(11, 14));
                $this->setFormField($c, '_ta_diastole', $this->rand(6, 9));
                $this->save($c, "CConstantesMedicales séjour $id ($day)");
                $this->counters['constantes']++;
            }
        }
    }

    /** Crise enregistrée dans l'état. Un scénario introuvable n'arrête PAS la simulation :
     *  avertissement, puis activité nominale. */
    private function loadCrisis(CSimulationState $state): void
    {
        $c = $state->getCrisis();
        if ($c === null) {
            return;
        }
        try {
            $this->crisis = CCrisisScenario::fromName($c['name']);
        } catch (\InvalidArgumentException $e) {
            $this->io->warning($e->getMessage() . ' — simulation nominale.');
            return;
        }
        $this->crisisStart = $c['start'];

        $byName = [];
        foreach ((new CService())->loadList(['group_id' => "= '$this->gid'"]) as $s) {
            $byName[$s->nom] = (int) $s->_id;
        }
        if ($missing = $this->crisis->missingServices(array_keys($byName))) {
            $this->io->warning('Crise « ' . $this->crisis->getName() . ' » : services absents de cet hôpital ('
                . implode(', ', $missing) . ') — effets reportés sur tous les services.');
        }
        foreach ($this->crisis->getAdmission()['services_cibles'] as $nom) {
            if (isset($byName[$nom])) {
                $this->crisisServiceIds[] = $byName[$nom];
            }
        }
        $this->io->writeln('Crise active : ' . $this->crisis->getName() . ' depuis ' . $this->crisisStart);
    }

    /** Débits et effets du jour : nominal, ou profil × courbe de crise au jour relatif. */
    private function prepareDay(string $day): void
    {
        $this->dayCfg    = $this->cfg;
        $this->crisisRel = -1;
        $this->effects   = ['deprogrammer_operations' => 0.0, 'deprogrammer_consultations' => 0.0, 'refus_admissions' => 0.0];
        if ($this->crisis === null) {
            return;
        }
        $this->crisisRel = (int) CMbDT::daysRelative(CMbDT::date(null, $this->crisisStart), $day);
        $this->dayCfg    = $this->cfg->withRateFactors($this->crisis->factorsAt($this->crisisRel));
        $this->effects   = $this->crisis->effectsAt($this->crisisRel);
    }

    private function crisisActive(): bool
    {
        return $this->crisis !== null && $this->crisis->isActiveAt($this->crisisRel);
    }

    /** Fenêtres de capacité de la crise : état VOULU au jour relatif, appliqué sans mémoire.
     *  Dans la fenêtre : renfort ouvert / aile fermée ; hors fenêtre : renfort refermé / aile
     *  rouverte. Seuls les lits LIBRES sont fermés : un lit occupé l'est au premier jour où il se
     *  libère (la règle est réévaluée chaque jour). */
    private function capacite(string $day): void
    {
        if ($this->crisis === null) {
            return;
        }
        $changed = false;
        foreach ($this->crisis->getCapacityWindows() as $w) {
            $in = $this->crisisRel >= (int) $w['de'] && $this->crisisRel <= (int) $w['a'];
            if (isset($w['renfort_lits'])) {
                $service = $this->findService($w['renfort_lits']['service']);
                if ($service) {
                    $changed = ($in ? $this->openRenfort($service, (int) $w['renfort_lits']['nb'])
                                    : $this->closeFreeBeds($service, true)) || $changed;
                }
            }
            if (isset($w['fermer_service'])) {
                $service = $this->findService($w['fermer_service']);
                if ($service) {
                    $changed = ($in ? $this->closeFreeBeds($service, false)
                                    : $this->reopenBeds($service)) || $changed;
                }
            }
        }
        if ($changed) {
            $this->refreshBeds();
        }
    }

    private function findService(string $nom): ?CService
    {
        $service           = new CService();
        $service->group_id = $this->gid;
        $service->nom      = $nom;
        $service->loadMatchingObject();

        return $service->_id ? $service : null;
    }

    /** Chambre « Renfort » du service, créée au besoin, avec nb lits ouverts. */
    private function openRenfort(CService $service, int $nb): bool
    {
        $chambre             = new \Hospilibre\Hospi\CChambre();
        $chambre->service_id = $service->_id;
        $chambre->nom        = 'Renfort';
        $chambre->loadMatchingObject();
        if (!$chambre->_id) {
            $chambre->_name = 'Renfort';
            $this->save($chambre, "CChambre Renfort ({$service->nom})");
        }
        $changed = false;
        $lits    = array_values((new CLit())->loadList(['chambre_id' => "= '" . (int) $chambre->_id . "'"], 'lit_id'));
        for ($i = 0; $i < $nb; $i++) {
            $lit = $lits[$i] ?? null;
            if ($lit === null) {
                $lit             = new CLit();
                $lit->chambre_id = $chambre->_id;
                $lit->_name      = 'Renfort ' . ($i + 1);
                $lit->nom        = 'Renfort ' . ($i + 1);
            } elseif ($lit->annule === '0') {
                continue;
            }
            $lit->annule = '0';
            $this->save($lit, "CLit renfort ({$service->nom})");
            $this->counters['lits_ouverts']++;
            $changed = true;
        }

        return $changed;
    }

    /** Ferme les lits LIBRES du service : ceux de la chambre Renfort ($renfort) ou les autres. */
    private function closeFreeBeds(CService $service, bool $renfort): bool
    {
        $changed = false;
        foreach ($this->freeBeds as $lit) {
            if ((int) $lit->_ref_chambre->service_id !== (int) $service->_id
                || ($lit->_ref_chambre->nom === 'Renfort') !== $renfort) {
                continue;
            }
            $lit->annule = '1';
            $this->save($lit, "CLit fermé ({$service->nom})");
            $this->counters['lits_fermes']++;
            $changed = true;
        }

        return $changed;
    }

    /** Rouvre les lits fermés du service (hors chambre Renfort). */
    private function reopenBeds(CService $service): bool
    {
        $changed = false;
        $ljoin   = ['chambre' => 'chambre.chambre_id = lit.chambre_id'];
        $where   = [
            'chambre.service_id' => "= '" . (int) $service->_id . "'",
            'chambre.nom'        => "<> 'Renfort'",
            'lit.annule'         => "= '1'",
        ];
        foreach ((new CLit())->loadList($where, null, null, null, $ljoin) as $lit) {
            $lit->annule = '0';
            $this->save($lit, "CLit rouvert ({$service->nom})");
            $this->counters['lits_ouverts']++;
            $changed = true;
        }

        return $changed;
    }

    /** Admission refusée faute de lit : séjour annulé, motif no_lit (trace exploitable en BI). */
    private function refuse(string $day, string $type, ?array $admission = null): void
    {
        if ($this->praticiens === []) {
            return;
        }
        $patient = (new CPatientGenerator())->generate();
        if (!$patient || !$patient->_id) {
            return;
        }
        $sejour                   = new \Hospilibre\PlanningOp\CSejour();
        $sejour->patient_id       = $patient->_id;
        $sejour->praticien_id     = $this->praticiens[$this->rand(0, count($this->praticiens) - 1)]->_id;
        $sejour->group_id         = $this->gid;
        $sejour->type             = $type;
        $sejour->entree_prevue    = "$day 10:00:00";
        $sejour->sortie_prevue    = CMbDT::dateTime('+1 day', "$day 10:00:00");
        $sejour->annule           = '1';
        $sejour->motif_annulation = 'no_lit';
        $sejour->rques            = 'Admission refusée : aucun lit disponible';
        $sejour->provenance       = $admission['provenance'] ?? null;
        $sejour->mode_entree      = $admission['mode_entree'] ?? null;
        if ($msg = $sejour->store()) {
            $this->io->warning("Refus d'admission non enregistré ($day) : $msg");
            return;
        }
        $this->counters['refus']++;
    }

    /** Arrivée de crise : provenance et mode d'entrée PMSI du scénario (ex. 5 = urgences). */
    private function markCrisisAdmission(\Hospilibre\PlanningOp\CSejour $sejour, array $admission, string $day): void
    {
        if ($admission['provenance'] === null && $admission['mode_entree'] === null) {
            return;
        }
        $sejour->provenance  = $admission['provenance'];
        $sejour->mode_entree = $admission['mode_entree'];
        $this->save($sejour, "provenance de crise CSejour ($day)");
    }

    /** Intervention non programmée le jour même (plan blanc). */
    private function operateNow(\Hospilibre\PlanningOp\CSejour $sejour, string $day): void
    {
        $salles = $this->loadSalles();
        if ($salles === []) {
            return;
        }
        $op = (new COperationGenerator())
            ->init($sejour)->setPraticien($this->praticiens[$this->rand(0, count($this->praticiens) - 1)])
            ->setSalle($salles[$this->rand(0, count($salles) - 1)])->setDate($day)->generate();
        if ($op && $op->_id) {
            $this->counters['operations']++;
        }
    }

    private function loadWorld(): void
    {
        $this->praticiens  = $this->loadGroupPraticiens($this->gid);
        $this->patients    = array_values(
            (new \Hospilibre\Patients\CPatient())->loadList(null, null, '200')
        );

        // Séjours en cours = du groupe, sans sortie réelle.
        $sejour       = new \Hospilibre\PlanningOp\CSejour();
        $this->sejours = [];
        foreach ($sejour->loadList([
            'group_id'      => "= '$this->gid'",
            'sortie_reelle' => 'IS NULL',
        ]) as $s) {
            $this->sejours[$s->_id] = $s;
        }

        $this->refreshBeds();
        $this->loadServiceWeights();

        $this->io->writeln(sprintf(
            'État : %d lits (%d libres), %d praticiens, %d séjours en cours, %d patients.',
            count($this->beds), count($this->freeBeds),
            count($this->praticiens), count($this->sejours), count($this->patients)
        ));
    }

    /** (Re)charge les lits ouverts du groupe et recalcule les lits libres d'après l'affectation
     *  courante de chaque séjour en cours. Rappelé après tout changement de capacité (crise).
     *  NB : CAffectation.sortie est notNull (moreThan|entree) — une affectation a TOUJOURS une
     *  sortie (future au bootstrap). L'affectation courante est donc celle de plus grande
     *  `entree` (dernier placement), pas une sortie nulle. */
    private function refreshBeds(): void
    {
        $this->beds = $this->loadGroupLits($this->gid);
        $occupied   = [];
        if ($this->sejours !== []) {
            $seen = [];
            foreach ((new CAffectation())->loadList(
                ['sejour_id' => \Ox\Core\CSQLDataSource::prepareIn(array_keys($this->sejours))],
                'entree DESC'
            ) as $a) {
                if (isset($seen[$a->sejour_id])) {
                    continue; // affectation courante (entree max) déjà retenue pour ce séjour
                }
                $seen[$a->sejour_id]  = true;
                $occupied[$a->lit_id] = true;
            }
        }
        $this->freeBeds = array_values(array_filter(
            $this->beds,
            static fn ($lit) => !isset($occupied[$lit->_id])
        ));
    }

    /** Poids d'admission par service_id : nom du service = nom dans le profil typé ; 1.0 sinon. */
    private function loadServiceWeights(): void
    {
        $byName = [];
        foreach ($this->cfg->getServices() as $s) {
            $byName[$s['nom']] = $s['poids_admissions'];
        }
        $this->serviceWeights = [];
        foreach ((new CService())->loadList(['group_id' => "= '$this->gid'"]) as $service) {
            $this->serviceWeights[(int) $service->_id] = $byName[$service->nom] ?? 1.0;
        }
    }

    /** Lit libre d'un service tiré au prorata des poids d'admission (parmi les services ayant
     *  un lit libre). Profil non typé : poids égaux. */
    private function takeWeightedFreeBed(): ?CLit
    {
        $libresParService = [];
        foreach ($this->freeBeds as $lit) {
            $sid = (int) $lit->_ref_chambre->service_id;
            $libresParService[$sid] = $this->serviceWeights[$sid] ?? 1.0;
        }
        $sid = CSimulationPicker::weighted($libresParService, mt_rand() / (mt_getrandmax() + 1));

        return $sid === null ? null : $this->takeFreeBedIn([(int) $sid]);
    }

    /** Premier lit libre dans l'un des services donnés ; à défaut, n'importe quel lit libre
     *  (débordement : les services cibles saturés « prêtent » leurs voisins). */
    private function takeFreeBedIn(array $serviceIds): ?CLit
    {
        foreach ($this->freeBeds as $i => $lit) {
            if (in_array((int) $lit->_ref_chambre->service_id, $serviceIds, true)) {
                array_splice($this->freeBeds, $i, 1);
                return $lit;
            }
        }

        return $this->takeFreeBed();
    }

    /** Tirage de Bernoulli de probabilité $p. */
    private function chance(float $p): bool
    {
        return $p > 0 && mt_rand() / (mt_getrandmax() + 1) < $p;
    }

    /**
     * Praticiens (médecins/chirurgiens/...) rattachés à la fonction du groupe.
     *
     * @return \Hospilibre\Mediusers\CMediusers[]
     */
    private function loadGroupPraticiens(int $gid): array
    {
        // CMediusers::loadPraticiens() accepte bien un group_id direct, mais filtre
        // ensuite via CMediusers::filterByPerm() (ACL applicative CPermObject) :
        // constaté à l'implémentation, ce filtre élimine systématiquement le staff
        // fraîchement généré ici (l'utilisateur "test" authentifié par startForCli()
        // n'a aucun droit explicite sur les fonctions/groupe fictifs — 42 candidats
        // trouvés par la requête, 0 restant après filterByPerm()). On reproduit donc
        // la jointure de CMediusers::loadListFromType() sans son filtre ACL, comme
        // prévu en repli par le plan (users_hospilibre -> functions_hospilibre sur
        // function_id, filtré sur group_id).
        // user_type est porté par la table parente `users` (CUser), pas par
        // `users_hospilibre` (CMediusers) : on joint `users` sur user_id, comme
        // CMediusers::loadListFromType().
        $user  = new \Hospilibre\Mediusers\CMediusers();
        $ljoin = [
            'functions_hospilibre' => 'functions_hospilibre.function_id = users_hospilibre.function_id',
            'users'                => '`users`.`user_id` = `users_hospilibre`.`user_id`',
        ];
        // Filtre sur les types médicaux uniquement (Chirurgien 3, Anesthésiste 4,
        // Médecin 13, Dentiste 19) : seul un praticien médical peut être le
        // praticien_id d'un séjour — sans ce filtre, les personnels (Personnel 14,
        // infirmiers…) rattachés au groupe seraient éligibles.
        $where = [
            'functions_hospilibre.group_id' => "= '$gid'",
            'users_hospilibre.actif'        => "= '1'",
            'users.user_type'               => "IN (3, 4, 13, 19)",
        ];

        // loadList() indexe par _id (clé non contiguë) : on réindexe pour un accès
        // par position ($praticiens[$i % count($praticiens)]) dans createPatients().
        return array_values($user->loadList($where, null, null, null, $ljoin));
    }

    /**
     * Lits de la structure du groupe (services -> chambres -> lits), avec
     * `_ref_chambre` (et donc `_ref_chambre->service_id`) préchargé.
     *
     * @return \Hospilibre\Hospi\CLit[]
     */
    private function loadGroupLits(int $gid): array
    {
        $services = (new \Hospilibre\Hospi\CService())->loadList(['group_id' => "= '$gid'"]);
        if (!$services) {
            return [];
        }

        $chambre       = new \Hospilibre\Hospi\CChambre();
        $service_ids   = array_map(static fn ($s) => $s->_id, $services);
        $chambres      = $chambre->loadList(['service_id' => \Ox\Core\CSQLDataSource::prepareIn($service_ids)]);
        if (!$chambres) {
            return [];
        }

        $lit          = new \Hospilibre\Hospi\CLit();
        $chambre_ids  = array_map(static fn ($c) => $c->_id, $chambres);
        $lits         = $lit->loadList([
            'chambre_id' => \Ox\Core\CSQLDataSource::prepareIn($chambre_ids),
            'annule'     => "= '0'", // lits fermés (crise, fermeture d'aile) : hors capacité
        ]);

        foreach ($lits as $_lit) {
            $_lit->loadRefChambre();
        }

        // loadList() indexe par _id (clé non contiguë) : on réindexe pour un accès
        // par position ($lits[$i]) dans createPatients().
        return array_values($lits);
    }

    private function rand(int $min, int $max): int
    {
        return $min >= $max ? $min : mt_rand($min, $max);
    }

    /** Assigne un champ dynamique (form field non déclaré `public`/`@property`, ex.
     *  `_ta_systole`) sur un CMbObject. Indirection par paramètre (plutôt qu'une
     *  propriété littérale) : ce sont des champs virtuels gérés par des `foreach`
     *  internes au modèle (cf. `formfields` dans `CConstantesMedicales::$list_constantes`),
     *  jamais promus en propriété PHP déclarée — inaccessibles autrement sans modifier
     *  ce fichier tiers.
     *
     * @param int|float|string $value
     */
    private function setFormField(CMbObject $object, string $field, $value): void
    {
        $object->$field = $value;
    }

    /** Valeur moyenne bruitée de ±30 %, jamais négative. */
    private function jitter(int $base): int
    {
        if ($base <= 0) {
            return 0;
        }
        $delta = (int) floor($base * 0.3);

        return max(0, $base + $this->rand(-$delta, $delta));
    }

    /** Store un objet et lève une exception si échec (message store() explicite). */
    private function save(CMbObject $object, string $label): void
    {
        if ($msg = $object->store()) {
            throw new \RuntimeException("$label : $msg");
        }
    }

    private function bootstrap(string $root): void
    {
        // Identique à SimulateBootstrap/SimulateReset : enrichir l'autoloader des
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
        CApp::getInstance()->startForCli('simulate:advance');
    }
}
