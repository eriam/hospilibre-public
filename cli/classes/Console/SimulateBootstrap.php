<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Ox\Cli\Console\Simulation\CSimulationConfig;
use Ox\Cli\Console\Simulation\CSimulationState;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Crée un hôpital fictif complet (structure + staff + patients) pour la
 * simulation. Idempotent (sentinelle CSimulationState) : re-run = skip sauf --force.
 */
class SimulateBootstrap extends HospilibreCommand
{
    private SymfonyStyle $io;

    protected function configure(): void
    {
        $this
            ->setName('simulate:bootstrap')
            ->setDescription("Crée un hôpital fictif (structure + staff + patients) pour la simulation")
            ->addOption('profile', null, InputOption::VALUE_OPTIONAL, 'Profil nommé (moyen, chu, clinique, ssr) — prioritaire sur --config')
            ->addOption('config', null, InputOption::VALUE_OPTIONAL, 'Fichier de config hôpital')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, "Nom de l'hôpital (surcharge)")
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Reseeder même si déjà bootstrappé')
            ->addOption('origin', null, InputOption::VALUE_OPTIONAL, "Origine temporelle du seed (ex. \"-30 days\") : horloge + séjours initiaux datés dans le passé, pour ensuite rattraper avec simulate:advance --until=now et bâtir un historique cohérent")
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Racine du projet', dirname(__DIR__, 3) . '/');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Même garde que simulate:advance : le graphe de classes chargé (CSejour, CMediusers…)
        // dépasse les 128M du CLI par défaut — mesuré : OOM au bootstrap du CHU à 128M (Task 0).
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit !== '-1' && (int) $limit > 0 && $this->toBytes($limit) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }

        $this->io = new SymfonyStyle($input, $output);
        $this->bootstrap(rtrim($input->getOption('path'), '/'));

        $state = new CSimulationState();
        $state->ensureTable();

        if ($state->getPhase() === 'bootstrapping') {
            $this->io->error('Semis interrompu détecté (group_id=' . $state->getGroupId()
                . ') : lancer simulate:reset avant de ressemer.');
            return self::FAILURE;
        }
        if ($state->isBootstrapped() && !$input->getOption('force')) {
            $this->io->writeln('Hôpital fictif déjà créé (group_id=' . $state->getGroupId() . '), ignoré (--force pour reseeder)');
            return self::SUCCESS;
        }

        try {
            $cfg = $this->loadConfig($input);
            $this->io->title('Bootstrap hôpital fictif — config « ' . $cfg->getName() . ' »');

            // Origine temporelle du seed : par défaut maintenant ; avec --origin, une date
            // passée (ex. "-30 days") pour que séjours initiaux ET horloge démarrent dans
            // le passé -> simulate:advance --until=now rattrape en historique cohérent.
            $originOpt = $input->getOption('origin');
            $origin    = $originOpt ? \Ox\Core\CMbDT::dateTime($originOpt) : \Ox\Core\CMbDT::dateTime();

            $group = $this->createEstablishment($cfg);
            $state->markBootstrapping((int) $group->_id, $cfg->getName());
            $this->createStructure($group, $cfg);
            $this->createStaff($group, $cfg);
            $this->createPatients($group, $cfg, $origin);
            $state->markBootstrapped((int) $group->_id, $cfg->getName());
            // Cale l'horloge de simulation sur l'origine du seed (par défaut = maintenant,
            // déjà posé par markBootstrapped ; explicite ici pour le cas --origin passé).
            $state->setClock($origin);
            $this->io->success('Établissement fictif créé (group_id=' . $group->_id . ', horloge=' . $origin . ')');
        } catch (Throwable $e) {
            $this->io->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function loadConfig(InputInterface $input): CSimulationConfig
    {
        $profile = $input->getOption('profile');
        if ($profile) {
            $cfg = CSimulationConfig::fromProfile((string) $profile);
        } else {
            $cfg = CSimulationConfig::fromFile(
                $input->getOption('config') ?: __DIR__ . '/Simulation/config/hopital_moyen.php'
            );
        }

        return $cfg->withOverrides(['nom' => $input->getOption('name')]);
    }

    private function createEstablishment(CSimulationConfig $cfg): \Hospilibre\Etablissement\CGroups
    {
        $h     = $cfg->getHospital();
        $group = new \Hospilibre\Etablissement\CGroups();
        $name  = $h['nom'] ?? 'Hôpital fictif';
        // CEntity::updatePlainFields() (appelé par store()) réécrit "text" depuis
        // "_name" via CGroups::mapEntityFrom() : c'est donc "_name" qu'il faut
        // renseigner, pas "text" directement (qui serait écrasé).
        $group->_name          = $name;
        $group->raison_sociale = $h['raison_sociale'] ?? $name;
        // "code" est notNull sans défaut (hérité de CEntity) : non couvert par le spec
        // hôpital, on le dérive du nom (slug) sauf si fourni explicitement.
        $group->code = $h['code'] ?? substr(
            preg_replace('/[^a-z0-9]+/', '_', strtolower($name)),
            0,
            80
        );
        // Champs optionnels si présents dans le spec CGroups (relevés au besoin) :
        foreach (['adresse' => 'adresse', 'cp' => 'cp', 'ville' => 'ville', 'finess' => 'finess'] as $src => $prop) {
            if (isset($h[$src]) && property_exists($group, $prop)) {
                $group->$prop = $h[$src];
            }
        }
        if ($msg = $group->store()) {
            throw new \RuntimeException("CGroups: $msg");
        }

        return $group;
    }

    private function createStructure(\Hospilibre\Etablissement\CGroups $group, CSimulationConfig $cfg): void
    {
        if ($cfg->isTyped()) {
            $this->createTypedStructure($group, $cfg);
            return;
        }

        $nbServices = $cfg->getInt('services', 4);
        $nbChambres = $cfg->getInt('chambres_par_service', 8);
        $nbLits     = $cfg->getInt('lits_par_chambre', 2);

        for ($s = 1; $s <= $nbServices; $s++) {
            $service            = new \Hospilibre\Hospi\CService();
            $service->group_id  = $group->_id;
            // CEntity déclare "_name" notNull sans défaut : il doit être renseigné. On fixe
            // aussi "nom" explicitement (même valeur) — les classes concrètes recopient bien
            // "_name" dans "nom" via leur mapEntityFrom(), mais le faire ici garde le code
            // robuste vis-à-vis de l'ordre d'appel dans updatePlainFields(). "code" est dérivé
            // automatiquement par CInternalStructure::store() (CMbString::makeInitials() sur "_name").
            $service->_name     = "Service $s";
            $service->nom       = "Service $s";
            $this->save($service, "CService $s");

            // Unité fonctionnelle : rattachée au groupe uniquement — CUniteFonctionnelle
            // n'a pas de champ "service_id" (ni propriété, ni entrée getProps()) dans ce
            // module ; le lien service/UF passe par CAffectationUniteFonctionnelle (hors
            // scope de cette tâche), pas par une FK directe.
            $uf           = new \Hospilibre\Hospi\CUniteFonctionnelle();
            $uf->group_id = $group->_id;
            $uf->code     = "UF$s";
            $uf->libelle  = "Unité fonctionnelle $s";
            $this->save($uf, "CUniteFonctionnelle $s");

            for ($c = 1; $c <= $nbChambres; $c++) {
                $chambre              = new \Hospilibre\Hospi\CChambre();
                $chambre->service_id  = $service->_id;
                $chambre->_name       = "Ch $s-$c";
                $chambre->nom         = "Ch $s-$c";
                $this->save($chambre, "CChambre $s-$c");

                for ($l = 1; $l <= $nbLits; $l++) {
                    $lit              = new \Hospilibre\Hospi\CLit();
                    $lit->chambre_id  = $chambre->_id;
                    $lit->_name       = "Lit $s-$c-$l";
                    $lit->nom         = "Lit $s-$c-$l";
                    $this->save($lit, "CLit $s-$c-$l");
                }
            }
        }

        for ($b = 1; $b <= $cfg->getInt('salles_bloc', 3); $b++) {
            $bloc           = new \Hospilibre\Bloc\CBlocOperatoire();
            $bloc->group_id = $group->_id;
            $bloc->nom      = "Bloc $b";
            $this->save($bloc, "CBlocOperatoire $b");
        }

        $this->io->writeln("Structure : $nbServices services, chambres/lits, blocs créés");
    }

    private function createStaff(\Hospilibre\Etablissement\CGroups $group, CSimulationConfig $cfg): void
    {
        if ($cfg->isTyped()) {
            $this->createTypedStaff($group, $cfg);
            return;
        }

        $gid = (int) $group->_id;

        for ($m = 0; $m < $cfg->getInt('medecins', 12); $m++) {
            (new \Hospilibre\Populate\Generators\CMediusersGenerator())
                ->setGroupId($gid)
                ->generate('Médecin');
        }

        for ($p = 0; $p < $cfg->getInt('personnels', 30); $p++) {
            (new \Hospilibre\Personnel\Generators\CPersonnelGenerator())
                ->setGroupId($gid)
                ->generate();
        }

        $this->io->writeln("Staff : {$cfg->getInt('medecins', 12)} médecins + {$cfg->getInt('personnels', 30)} personnels générés");
    }

    /**
     * Patients initiaux (identités fictives via le générateur restauré) + quelques
     * séjours EN COURS créés directement sur notre structure (pas via CSejourGenerator,
     * qui génère son propre service/lit/patient détachés) : c'est l'état de départ.
     */
    private function createPatients(\Hospilibre\Etablissement\CGroups $group, CSimulationConfig $cfg, string $origin): void
    {
        $gid = (int) $group->_id;

        // 1) Patients initiaux (identités fictives réalistes via le générateur).
        //    CPatient n'est pas rattaché à un groupe : le générateur ne prend pas de group_id.
        //    Le générateur RÉUTILISE parfois un patient existant (~20 %) : on dédoublonne
        //    par _id pour qu'un même patient ne reçoive pas deux séjours qui se chevauchent
        //    (CSejour::check() refuse la collision, ce qui interrompait le bootstrap).
        $patients = [];
        for ($p = 0; $p < $cfg->getInt('patients_initiaux', 40); $p++) {
            $patient = (new \Hospilibre\Populate\Generators\CPatientGenerator())->generate();
            if ($patient && $patient->_id) {
                $patients[$patient->_id] = $patient;
            }
        }
        $patients = array_values($patients); // réindexé pour l'accès par position ci-dessous

        // 2) Praticiens de notre staff, 3) lits de notre structure.
        $praticiens = $this->loadGroupPraticiens($gid);
        $lits       = $this->loadGroupLits($gid);

        // 4) Quelques séjours EN COURS (chevauchent maintenant) + affectation lit libre.
        //    On ne peut jamais indexer au-delà d'un tableau vide : on clampe.
        // Borné par les patients et les lits, PAS par le nombre de praticiens (Task 0 : le CHU
        // démarrait avec 45 séjours sur 230 voulus). Lits mélangés : sinon les premiers services
        // créés (ordre des _id) absorbent toute l'occupation initiale.
        $nbSejours = $cfg->initialSejours(count($patients), count($lits), count($praticiens));
        shuffle($lits);
        // Séjours en cours datés autour de l'ORIGINE (= maintenant par défaut ; une date
        // passée avec --origin) : entrée avant l'origine, sortie après -> ils chevauchent
        // bien l'horloge de départ, cohérent pour le rattrapage simulate:advance.
        $poses = 0;
        for ($i = 0; $i < $nbSejours; $i++) {
            $entree = \Ox\Core\CMbDT::dateTime('-' . random_int(1, 5) . ' days', $origin);
            $sortie = \Ox\Core\CMbDT::dateTime('+' . random_int(1, 5) . ' days', $origin);

            $sejour                = new \Hospilibre\PlanningOp\CSejour();
            $sejour->patient_id    = $patients[$i]->_id;
            $sejour->praticien_id  = $praticiens[$i % count($praticiens)]->_id;
            $sejour->group_id      = $gid;
            $sejour->type          = $cfg->getSejourType();
            // Service du séjour = service de son lit (comme CSejourGenerator côté advance) :
            // sans lui, les séjours initiaux n'ont pas de service et échappent aux requêtes BI.
            $sejour->service_id    = $lits[$i]->_ref_chambre->service_id;
            $sejour->entree_prevue = $entree;
            // Séjour EN COURS = patient admis : entrée réelle posée (sinon il apparaît « pas encore
            // entré » dans l'application et les requêtes BI, et simulate:status ne le comptait pas).
            $sejour->entree_reelle = $entree;
            $sejour->sortie_prevue = $sortie;
            // NON BLOQUANT : un échec métier résiduel (ex. collision de séjour) est ignoré
            // et le bootstrap CONTINUE — jamais d'hôpital à moitié construit ni d'orphelin
            // sans sentinelle (markBootstrapped n'est atteint que si cette boucle finit).
            if ($msg = $sejour->store()) {
                $this->io->writeln("<comment>CSejour $i ignoré : $msg</comment>");
                continue;
            }

            $lit              = $lits[$i];
            $aff              = new \Hospilibre\Hospi\CAffectation();
            $aff->sejour_id   = $sejour->_id;
            $aff->service_id  = $lit->_ref_chambre->service_id; // service du lit
            $aff->lit_id      = $lit->_id;
            $aff->entree      = $entree;
            $aff->sortie      = $sortie;
            if ($msg = $aff->store()) {
                $this->io->writeln("<comment>CAffectation $i ignorée : $msg</comment>");
                continue;
            }
            $poses++;
        }

        $this->io->writeln(count($patients) . " patients créés ; $poses séjours en cours posés");
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
        $lits         = $lit->loadList(['chambre_id' => \Ox\Core\CSQLDataSource::prepareIn($chambre_ids)]);

        foreach ($lits as $_lit) {
            $_lit->loadRefChambre();
        }

        // loadList() indexe par _id (clé non contiguë) : on réindexe pour un accès
        // par position ($lits[$i]) dans createPatients().
        return array_values($lits);
    }

    /** Services nommés du profil : un service + une UF (même code) + chambres/lits, puis les
     *  blocs (avec une salle chacun, pour que advance n'ait pas à les créer à la volée). */
    private function createTypedStructure(\Hospilibre\Etablissement\CGroups $group, CSimulationConfig $cfg): void
    {
        $nbLits = 0;
        foreach ($cfg->getServices() as $s) {
            $service           = new \Hospilibre\Hospi\CService();
            $service->group_id = $group->_id;
            $service->_name    = $s['nom'];
            $service->nom      = $s['nom'];
            $this->save($service, "CService {$s['nom']}");

            $uf           = new \Hospilibre\Hospi\CUniteFonctionnelle();
            $uf->group_id = $group->_id;
            $uf->code     = $s['code'];
            $uf->libelle  = $s['nom'];
            $this->save($uf, "CUniteFonctionnelle {$s['code']}");

            for ($c = 1; $c <= $s['chambres']; $c++) {
                $chambre             = new \Hospilibre\Hospi\CChambre();
                $chambre->service_id = $service->_id;
                $chambre->_name      = "{$s['code']} $c";
                $chambre->nom        = "{$s['code']} $c";
                $this->save($chambre, "CChambre {$s['code']} $c");

                for ($l = 1; $l <= $s['lits_par_chambre']; $l++) {
                    $lit             = new \Hospilibre\Hospi\CLit();
                    $lit->chambre_id = $chambre->_id;
                    $lit->_name      = "{$s['code']} $c-$l";
                    $lit->nom        = "{$s['code']} $c-$l";
                    $this->save($lit, "CLit {$s['code']} $c-$l");
                    $nbLits++;
                }
            }
        }

        for ($b = 1; $b <= $cfg->getBlocs(); $b++) {
            $bloc           = new \Hospilibre\Bloc\CBlocOperatoire();
            $bloc->group_id = $group->_id;
            $bloc->nom      = "Bloc $b";
            $this->save($bloc, "CBlocOperatoire $b");

            $salle          = new \Hospilibre\Bloc\CSalle();
            $salle->bloc_id = $bloc->_id;
            $salle->nom     = "Salle $b";
            $salle->stats   = 0;
            $this->save($salle, "CSalle $b");
        }

        $this->io->writeln(sprintf(
            'Structure typée : %d services, %d lits, %d blocs',
            count($cfg->getServices()), $nbLits, $cfg->getBlocs()
        ));
    }

    /** Staff du profil typé : médecins par service, spécialité tournante dans la liste du service. */
    private function createTypedStaff(\Hospilibre\Etablissement\CGroups $group, CSimulationConfig $cfg): void
    {
        $gid   = (int) $group->_id;
        $nbMed = $nbPers = 0;
        foreach ($cfg->getServices() as $s) {
            for ($m = 0; $m < $s['medecins']; $m++) {
                $spec = $s['spec_cpam'][$m % count($s['spec_cpam'])];
                (new \Hospilibre\Populate\Generators\CMediusersGenerator())
                    ->setGroupId($gid)
                    ->generate('Médecin', $spec);
                $nbMed++;
            }
            for ($p = 0; $p < $s['personnels']; $p++) {
                (new \Hospilibre\Personnel\Generators\CPersonnelGenerator())
                    ->setGroupId($gid)
                    ->generate();
                $nbPers++;
            }
        }
        $this->io->writeln("Staff : $nbMed médecins (spécialisés) + $nbPers personnels générés");
    }

    /** "512M" -> octets. */
    private function toBytes(string $value): int
    {
        $bytes = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g': return $bytes * 1024 * 1024 * 1024;
            case 'm': return $bytes * 1024 * 1024;
            case 'k': return $bytes * 1024;
        }

        return $bytes;
    }

    /** Store un objet et lève une exception si échec (message store() explicite). */
    private function save(\Ox\Core\CMbObject $object, string $label): void
    {
        if ($msg = $object->store()) {
            throw new \RuntimeException("$label : $msg");
        }
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
