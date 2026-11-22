<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Composer\Semver\Comparator as SemverComparator;
use Ox\Cli\HospilibreCommand;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CSetup;
use Ox\Core\Module\CModule;
use PDO;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Installe, active et met à jour le socle de modules par défaut selon un
 * profil d'établissement, directement via CModule/CSetup (sans passer par
 * une session HTTP authentifiée).
 *
 * Remplace l'automatisation à base de curl+do_manage_module.php de
 * docker-entrypoint.sh : celle-ci dépendait d'un mot de passe admin, d'un
 * jeton de connexion dynamique et d'une session web fonctionnelle — trois
 * points de fragilité qui ont chacun causé une panne distincte en
 * production (login jamais abouti, ADMIN_UPGRADE_PASSWORD absent).
 *
 * CSetup::upgrade() s'arrête silencieusement (sans erreur) dès qu'une
 * dépendance inter-module déclarée via addDependency() n'est pas
 * satisfaite à la révision courante. Un seul passage sur tous les modules
 * ne suffit donc jamais si les dépendances sont en cascade (ex. dPhospi
 * dépend de dPplanningOp, qui dépend de dPsante400/webservices) : on boucle
 * jusqu'à ce qu'un passage complet ne fasse plus bouger aucune version.
 */
class ModulesSetup extends HospilibreCommand
{
    private const MAX_ROUNDS = 8;

    // Tronc commun à tous les profils : dossier patient, fondations techniques.
    // dPsante400 et webservices sont des dépendances transitives requises par
    // dPplanningOp/dPcabinet plus loin dans leur chaîne de révisions.
    private const COMMON_MODULES = [
        'mediusers', 'dPpatients', 'dPadmissions', 'dPfiles', 'dPcompteRendu',
        'dPboard', 'dPstock', 'messagerie', 'sample', 'dPqualite', 'dPsante400',
        'webservices',
    ];

    // Bloc opératoire / chirurgie / anesthésie / soins aigus, hospitalisation, consultations
    private const AIGU_MODULES = [
        'dPhospi', 'dPplanningOp', 'dPbloc', 'dPsalleOp', 'dPpersonnel', 'dPurgences',
        'monitoringPatient', 'soins', 'dPccam', 'dPlabo', 'bloodSalvage', 'dPcabinet',
        'dPcim10', 'dPpmsi', 'maternite', 'fhir', 'dicom', 'astreintes', 'dPfacturation',
        'provenance', 'dPressources', 'sip', 'smp', 'sms',
    ];

    // Hospitalisation et suivi sans plateau chirurgical/urgences lourdes, avec consultations
    private const SUBAIGU_MODULES = [
        'dPhospi', 'soins', 'dPpersonnel', 'dPcabinet', 'dPcim10', 'dPccam', 'dPlabo',
        'dPpmsi', 'fhir', 'dicom', 'dPfacturation', 'provenance', 'sip', 'smp', 'sms',
    ];

    // Uniquement Soins de Suite et Réadaptation, sans consultations
    private const SSR_MODULES = [
        'ssr', 'dPhospi', 'soins', 'dPpersonnel', 'dPcim10', 'dPccam', 'dPlabo',
        'fhir', 'sip', 'smp', 'sms',
    ];

    // Le profil "complet" ajoute le reste du socle validé, en plus de tous les autres.
    private const COMPLET_EXTRA = ['dPrepas'];

    private SymfonyStyle $io;

    private PDO $pdo;

    /** Racine de la copie de travail, pour vérifier la présence d'un répertoire de module. */
    private string $root = '';

    /**
     * Erreurs de revision rencontrees pendant toute l'execution, tous modules confondus.
     *
     * POURQUOI CE DECOMPTE EXISTE EN PLUS DE LA COMPARAISON DE VERSIONS : mesure sur l’instance de démonstration,
     * dPpatients a produit « Error in queries INSERT INTO `cronjob` ... » alors que sa version en
     * base EGALE sa cible. Une erreur de revision sans ecart de version est structurellement
     * invisible a un controle qui compare des versions — ni l'ancien ni le nouveau ne pouvait la
     * voir. Un module peut donc etre « a la cible » et avoir rate une partie de son travail.
     *
     * @var array<array{0: string, 1: string}>
     */
    private array $revisionErrors = [];

    protected function configure(): void
    {
        $this
            ->setName('ox-modules:setup')
            ->setDescription(
                "Installe/active/met à jour le socle de modules par défaut selon un profil d'établissement"
            )
            ->addOption(
                'profile',
                'p',
                InputOption::VALUE_REQUIRED,
                "Profil d'établissement : aigu|subaigu|ssr|complet",
                'complet'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_OPTIONAL,
                'Racine du projet',
                dirname(__DIR__, 3) . '/'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $root       = rtrim($input->getOption('path'), '/');
        $this->root = $root;
        $profile = $input->getOption('profile');

        $this->bootstrap($root);
        $this->pdo = $this->connectPdo();

        // Le profil pilote ce qui est INSTALLÉ à froid (socle curé par type
        // d'établissement). Mais au déploiement on veut aussi que TOUT module déjà
        // installé/actif soit remonté à sa dernière version, même s'il n'est pas
        // dans le profil — sinon un module actif hors-profil reste figé « pas à
        // jour » à chaque deploy. On upgrade donc l'union profil + modules actifs.
        $profileModules = $this->resolveModuleList($profile);
        $activeModules  = $this->loadActiveModuleNames();
        $modules        = array_values(array_unique(array_merge($profileModules, $activeModules)));
        $this->io->title(
            sprintf(
                "Configuration des modules — profil '%s' : %d du profil + %d actifs -> %d au total",
                $profile,
                count($profileModules),
                count($activeModules),
                count($modules)
            )
        );

        $before = null;
        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $before = $this->snapshotVersions();

            $this->upgradeCoreModules();
            foreach ($modules as $modName) {
                $this->installOrUpgradeModule($modName);
            }

            $after = $this->snapshotVersions();

            if ($before === $after) {
                $this->io->writeln("Versions des modules stabilisées après $round passage(s)");

                return $this->reportModulesBelowTarget();
            }

            $this->io->writeln("Passage $round : versions modifiées, nouveau passage nécessaire");
        }

        $this->io->warning(
            sprintf(
                "Versions des modules pas stabilisées après %d passages — dépendance non satisfaite dans ce fork ?",
                self::MAX_ROUNDS
            )
        );

        return $this->reportModulesBelowTarget();
    }

    /**
     * Le module a-t-il un fichier de setup SUR LE DISQUE ?
     *
     * Sert uniquement a distinguer « ce module n'a pas de setup, c'est normal » de « ce module a un
     * setup mais la resolution de classe a echoue ». Le disque est deliberement interroge en
     * dernier recours : c'est la seule source qui ne depende ni du classmap, ni des alias, ni d'un
     * cache — donc la seule qui puisse arbitrer quand on soupconne l'un des trois.
     */
    private function setupFileExists(string $modName): bool
    {
        $short = ucfirst(strpos($modName, 'dP') === 0 ? substr($modName, 2) : $modName);

        // $this->root, pas CAppUI::conf('root_dir') : la commande prend sa racine de l'option
        // --path, et c'est elle qui fait foi ici — interroger la configuration reintroduirait
        // justement une dependance a un etat charge, alors que tout l'interet est de ne dependre
        // que du disque.
        return glob($this->root . "/modules/{$modName}/classes/CSetup{$short}.php") !== [];
    }

    /**
     * Compare la version installée de chaque module à la cible de son CSetup.
     *
     * Sans ce contrôle, la commande annonçait « versions stabilisées » et sortait en succès
     * alors que des modules restaient plusieurs révisions en dessous de leur cible : CSetup
     * signale ses échecs par CAppUI::setMsg(), que rien ici ne lisait, et un module qui cesse
     * d'avancer suffit à faire croire à la stabilité. Un déploiement à moitié migré passait donc
     * pour un déploiement réussi — exactement ce qu'il faut éviter sur une migration de schéma.
     */
    private function reportModulesBelowTarget(): int
    {
        $behind      = [];
        $structural  = [];
        $unevaluable = [];

        $examined = 0;

        foreach ($this->snapshotVersions() as $modName => $version) {
            $examined++;
            $setupClass = CSetup::getCSetupClass($modName);
            if (!$setupClass) {
                // Beaucoup de modules n'ont legitimement aucun CSetup : les sauter est correct.
                // Mais getCSetupClass() fait un class_exists() sur le NOM COURT, qui ne resout que
                // si CAutoloadAlias est enregistre ET si le classmap connait la classe. Un classmap
                // incomplet rend donc FALSE pour un module qui a pourtant bel et bien un setup sur
                // le disque — et le module disparaissait alors du rapport sans un mot, ce qui
                // suffisait a faire annoncer « tous les modules a leur version cible ».
                // On distingue les deux cas en regardant le disque, seule source qui ne depend
                // d'aucun cache.
                if ($this->setupFileExists($modName)) {
                    $unevaluable[] = [
                        $modName,
                        $version,
                        '?',
                        'classe de setup presente sur le disque mais non resolue (classmap ou alias incomplet)',
                    ];
                }

                continue;
            }

            try {
                /** @var CSetup $setup */
                $setup = new $setupClass();
            } catch (Throwable $e) {
                // NE PAS « continue » EN SILENCE. Un module dont la classe de setup ne se
                // construit même pas était jusqu'ici EXCLU du rapport, donc absent de $behind,
                // donc le verdict final annonçait « tous les modules à leur version cible » en
                // l'ignorant. C'est ce qui a permis au module system de rester 75 révisions en
                // arrière sur la prod pendant des jours avec un déploiement vert : la seule ligne
                // le concernant disait « rien à faire », et le verdict global confirmait.
                // Un module qu'on ne peut pas évaluer n'est pas un module à jour.
                $unevaluable[] = [$modName, $version, '?', get_class($e) . ' : ' . $e->getMessage()];

                continue;
            }

            if ($setup->mod_version === null || $version === $setup->mod_version) {
                continue;
            }

            $absent = $this->blockingAbsentModules($setup, (string)$version);

            if ($absent !== []) {
                $structural[] = [$modName, $version, $setup->mod_version, implode(', ', $absent)];
            } else {
                $behind[] = [$modName, $version, $setup->mod_version];
            }
        }

        if ($structural !== []) {
            // Signalé mais NON compté comme échec : ces modules attendent une dépendance qui n'est
            // pas distribuée avec ce fork, et aucune exécution n'y changera rien. Les faire échouer
            // rendrait le code de sortie rouge à chaque déploiement, et un signal toujours rouge
            // n'est plus un signal — c'est précisément ce que ce contrôle sert à éviter.
            $this->io->note(
                sprintf('%d module(s) bloqué(s) par une dépendance non distribuée', count($structural))
            );
            $this->io->table(['Module', 'Version installée', 'Cible', 'Dépendance absente'], $structural);
        }

        if ($unevaluable !== []) {
            // Compté comme ÉCHEC, contrairement à $structural : une dépendance non distribuée est
            // un fait connu et stable, un setup qui ne se construit pas est une anomalie dont on
            // ne sait rien. Les traiter pareil reviendrait à décider que ce qu'on ne comprend pas
            // est sans conséquence.
            $this->io->error(sprintf('%d module(s) dont le setup est INÉVALUABLE', count($unevaluable)));
            $this->io->table(['Module', 'Version installée', 'Cible', 'Erreur'], $unevaluable);
        }

        if ($this->revisionErrors !== []) {
            // ECHEC, meme si toutes les versions concordent. Une revision qui echoue n'a pas fait
            // son travail : la version enregistree dit seulement ou le setup s'est arrete, pas que
            // le schema soit dans l'etat attendu.
            $this->io->error(sprintf('%d erreur(s) de revision pendant cette execution', count($this->revisionErrors)));
            $this->io->table(['Module', 'Erreur'], $this->revisionErrors);
        }

        // LE RAPPORT MONTRE SON TRAVAIL. Sur l’instance de démonstration, « [OK] Tous les modules installables du
        // profil sont a leur version cible » a ete imprime alors que cinq modules etaient en
        // ecart, et nous n'avons jamais pu reconstituer ce que le rapport avait lu : ni le nombre
        // de modules examines, ni les motifs d'exclusion n'apparaissaient. Un verdict qui ne dit
        // pas sur quoi il porte ne peut pas etre contredit par une mesure.
        $this->io->writeln(
            sprintf(
                'Rapport : %d module(s) examine(s) · %d en ecart · %d bloque(s) par une dependance absente'
                . ' · %d inevaluable(s) · %d erreur(s) de revision',
                $examined,
                count($behind),
                count($structural),
                count($unevaluable),
                count($this->revisionErrors)
            )
        );

        if ($behind === [] && $unevaluable === [] && $this->revisionErrors === []) {
            // Le message ne dit « tous » que quand c'est vrai. Avec des modules bloqués par une
            // dépendance absente, il le dit AUTREMENT : un verdict qui affirme plus que ce qui a
            // été vérifié est la panne la plus coûteuse de ce dossier.
            if ($structural !== []) {
                $this->io->success(
                    sprintf(
                        'Modules du profil à leur version cible, sauf %d bloqué(s) par une dépendance non distribuée',
                        count($structural)
                    )
                );
            } else {
                $this->io->success('Tous les modules installables du profil sont à leur version cible');
            }

            return self::SUCCESS;
        }

        if ($behind === []) {
            return self::FAILURE;
        }


        $this->io->warning(sprintf('%d module(s) en dessous de leur version cible', count($behind)));
        $this->io->table(['Module', 'Version installée', 'Cible'], $behind);
        $this->io->writeln(
            'Relancer la commande peut suffire si une dépendance a été satisfaite entre-temps ;'
            . ' sinon, la cause est dans les messages de révision ci-dessus.'
        );

        return self::FAILURE;
    }

    /**
     * Modules dont ce setup a besoin pour progresser, et qui ne sont pas distribués.
     *
     * Seules les dépendances de l'étape EN ATTENTE et des suivantes sont examinées : une dépendance
     * d'une révision déjà franchie n'explique pas le blocage actuel, et la prendre en compte ferait
     * passer un vrai problème pour une fatalité.
     *
     * L'étape en attente est celle dont la clé ÉGALE la version installée, et non la suivante :
     * CSetup::upgrade() positionne son curseur sur la version installée puis traite
     * `$this->dependencies[$currRevision]`. Les déclarations qui suivent un
     * `makeRevision('0.02')` conduisent donc de 0.02 à la version d'après — c'est le décalage qui
     * m'avait d'abord fait rater monitoringPatient.
     *
     * @return string[]
     */
    private function blockingAbsentModules(CSetup $setup, string $installed): array
    {
        $absent = [];

        foreach ($setup->dependencies as $revision => $dependencies) {
            if (SemverComparator::lessThan((string)$revision, $installed)) {
                continue;
            }

            foreach ($dependencies as $dependency) {
                if (!is_dir($this->root . '/modules/' . $dependency->module)) {
                    $absent[$dependency->module] = true;
                }
            }
        }

        return array_keys($absent);
    }

    /**
     * Vide et restitue les messages laissés par CSetup pour le module courant.
     *
     * CSetup rapporte ses erreurs de révision par CAppUI::setMsg() et non par exception : sans
     * cette purge, un « Error in queries … for revision … » n'apparaissait nulle part et le
     * module restait silencieusement bloqué.
     *
     * @return string[]
     */
    private function drainSetupMessages(string $modName = ''): array
    {
        $messages = [];

        foreach ((array)(CAppUI::$instance->messages ?? []) as $type => $msgs) {
            // UI_MSG_OK n'apporte rien ici : seuls les avertissements et les erreurs comptent.
            if ((int)$type === UI_MSG_OK) {
                continue;
            }

            foreach ((array)$msgs as $msg => $count) {
                $clean      = trim(strip_tags(str_replace(['<br />', "\n"], ' ', (string)$msg)));
                $messages[] = $clean;

                // Une erreur de revision est RETENUE, pas seulement imprimee. L'imprimer suffisait
                // tant qu'on supposait qu'elle se traduirait par un ecart de version ; dPpatients
                // montre que ce n'est pas vrai. Le code de sortie doit donc en dependre
                // directement.
                if ((int)$type === UI_MSG_ERROR) {
                    $this->revisionErrors[] = [$modName, $clean];
                }
            }
        }

        CAppUI::$instance->messages = [];

        return $messages;
    }

    /**
     * Le build Docker utilise `composer install --no-scripts` (buildClassMap
     * plante sans DB), donc les namespaces PSR-4 des modules ne sont jamais
     * fusionnés dans l'autoloader généré — il faut les enregistrer à la main
     * avant de démarrer l'application, comme le fait CComposerScript::preAutoloadDump()
     * lors d'un composer install complet.
     *
     * Le reste du bootstrap passe par CApp::startForCli(), le point d'entrée
     * officiel pour les commandes CLI : il appelle notamment
     * CAutoloadAlias::register(), sans quoi CSetup::getCSetupClass() (qui fait
     * un class_exists("CSetupHospi") sur le nom court, sans namespace) ne
     * résout jamais aucune classe et tous les modules sont ignorés en silence.
     */
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

        // CComposerScript::$is_running N'EST PAS activé ici, contrairement à la génération
        // des includes par l'entrypoint. Ce drapeau fait court-circuiter
        // CModelObject::getBackProps(), qui renvoie alors un tableau VIDE — et le résultat
        // est mémoïsé par classe dans CModelObject::$backProps dès la première
        // instanciation. Tout le reste de la commande travaille ensuite sans références
        // arrière : les écritures de CSetup/CModule qui journalisent tombent sur
        // « Undefined array key "user_action_datas" » (CModelObject::makeBackSpec) et le
        // setup du module s'arrête. À ce stade du démarrage conteneur, classmap.php et
        // classref.php sont déjà générés : le drapeau n'a plus de raison d'être.
        CApp::getInstance()->startForCli('ox-modules:setup');
    }

    private function connectPdo(): PDO
    {
        global $dPconfig;

        return new PDO(
            'mysql:host=' . $dPconfig['db']['std']['dbhost'] . ';dbname=' . $dPconfig['db']['std']['dbname'],
            $dPconfig['db']['std']['dbuser'],
            $dPconfig['db']['std']['dbpass']
        );
    }

    private function resolveModuleList(string $profile): array
    {
        $profiles = [
            'aigu'    => array_merge(self::COMMON_MODULES, self::AIGU_MODULES),
            'subaigu' => array_merge(self::COMMON_MODULES, self::SUBAIGU_MODULES),
            'ssr'     => array_merge(self::COMMON_MODULES, self::SSR_MODULES),
            'complet' => array_merge(
                self::COMMON_MODULES,
                self::AIGU_MODULES,
                self::SUBAIGU_MODULES,
                self::SSR_MODULES,
                self::COMPLET_EXTRA
            ),
        ];

        if (!isset($profiles[$profile])) {
            $this->io->warning("Profil '$profile' inconnu (attendu : aigu|subaigu|ssr|complet), utilisation de 'complet'");
            $profile = 'complet';
        }

        return array_values(array_unique($profiles[$profile]));
    }

    /**
     * Noms de tous les modules actuellement actifs en base. Sert à garantir qu'au
     * déploiement, tout module déjà installé/actif est remonté à sa dernière
     * version — pas seulement ceux du profil.
     *
     * @return string[]
     */
    private function loadActiveModuleNames(): array
    {
        $stmt = $this->pdo->query('SELECT mod_name FROM modules WHERE mod_active = 1');

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Équivalent de cmd=upgrade-core : met à jour tous les modules mod_type=core.
     */
    private function upgradeCoreModules(): void
    {
        $module            = new CModule();
        $module->mod_type  = 'core';

        /** @var CModule[] $coreModules */
        $coreModules = $module->loadMatchingList();

        foreach ($coreModules as $coreModule) {
            $this->runSetupUpgrade($coreModule);
        }
    }

    private function installOrUpgradeModule(string $modName): void
    {
        $state = $this->getModuleState($modName);

        $module = new CModule();
        if ($state) {
            $module->load($state['mod_id']);
            $module->checkModuleFiles();
        } else {
            $module->mod_name    = $modName;
            $module->mod_version = '0.0';
        }

        if (!$this->runSetupUpgrade($module)) {
            return;
        }

        $state = $this->getModuleState($modName);
        if (!$state) {
            $this->io->note("Module $modName introuvable après installation, activation ignorée");

            return;
        }

        if (!$state['mod_active'] || !$state['mod_ui_active']) {
            $activated       = new CModule();
            $activated->load($state['mod_id']);
            $activated->mod_active    = 1;
            $activated->mod_ui_active = 1;
            $activated->store();
        }
    }

    private function runSetupUpgrade(CModule $module): bool
    {
        $setupClass = CSetup::getCSetupClass($module->mod_name);
        if (!$setupClass) {
            $this->io->note("Pas de classe CSetup pour $module->mod_name, ignoré");

            return false;
        }

        // Pendant l'upgrade CLI, un warning PHP incident — typiquement émis par le
        // logging d'action utilisateur pendant un store() (ex. "Undefined array key
        // user_action_datas") — ne doit pas transformer un upgrade de schéma réussi en
        // échec : ces warnings sont non-fatals en contexte web, ils ne le sont ici que
        // parce que le handler global les convertit en exceptions. On les laisse passer
        // le temps de l'upgrade ; les vraies erreurs (Error, exceptions SQL/métier)
        // restent propagées et gérées par le catch ci-dessous.
        set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
            if (in_array($no, [E_WARNING, E_NOTICE, E_DEPRECATED, E_STRICT, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED], true)) {
                return true;
            }

            throw new \ErrorException($str, 0, $no, $file, $line);
        });

        try {
            /** @var CSetup $setup */
            $setup = new $setupClass();
            $setup->upgrade($module);

            foreach ($this->drainSetupMessages($module->mod_name) as $message) {
                $this->io->writeln(sprintf('  <comment>%s</comment> : %s', $module->mod_name, $message));
            }

            return true;
        } catch (Throwable $e) {
            $this->io->error(sprintf('%s : %s', $module->mod_name, $e->getMessage()));
            $this->drainSetupMessages($module->mod_name);

            return false;
        } finally {
            restore_error_handler();
        }
    }

    private function getModuleState(string $modName): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mod_id, mod_version, mod_active, mod_ui_active FROM modules WHERE mod_name = ?'
        );
        $stmt->execute([$modName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function snapshotVersions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT mod_name, mod_version FROM modules WHERE mod_active = 1 ORDER BY mod_name'
        );

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
