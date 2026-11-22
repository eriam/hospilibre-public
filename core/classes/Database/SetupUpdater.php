<?php

/**
 * @package Ox\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre. Réécriture propre : le fichier OXOL de même nom a été retiré par
 * f7456b5ae4 (« Phase 2 (lot 5l) : retrait en masse de 6 fichiers OXOL du module core ») sans que
 * ses trois appelants le soient, laissant `ox-db:update` mort depuis le 2026-07-10. Rien n'est
 * repris de l'implémentation retirée : cette classe est bâtie sur les API publiques GPL de
 * CModule et CSetup, et sa seule ambition est de satisfaire le contrat que ses appelants
 * expriment.
 */

namespace Ox\Core\Database;

use ErrorException;
use Ox\Core\Autoload\IShortNameAutoloadable;
use Ox\Core\CAppUI;
use Ox\Core\CSetup;
use Ox\Core\CSQLDataSource;
use Ox\Core\Module\CModule;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Met les modules installés à la version que leur setup annonce.
 *
 * POURQUOI CETTE CLASSE N'EST PAS UN SECOND MOTEUR D'UPGRADE. Le travail réel — lire la révision
 * courante, la comparer à la cible, rejouer les révisions manquantes — vit déjà dans
 * CModule::upgradeAll() et CSetup::upgrade(). Réimplémenter cette mécanique ici donnerait deux
 * chemins d'upgrade divergents, c'est-à-dire exactement la situation qui a produit la panne
 * d'origine : une commande qu'on croyait équivalente à `ox-modules:setup` et qui ne l'était plus.
 * Cette classe ORCHESTRE donc, elle ne migre pas.
 *
 * CE QU'ELLE AJOUTE À upgradeAll(), et pourquoi c'est nécessaire :
 *
 * 1. LA CONVERGENCE. CSetup::upgrade() s'arrête sur toute dépendance inter-module non satisfaite.
 *    Un seul passage laisse donc en rade tout module dont une dépendance est montée APRÈS lui, et
 *    l'ordre dépend de l'ordre de chargement, pas du graphe. Il faut répéter les passages jusqu'à
 *    ce que plus aucune version ne bouge. La même leçon est écrite dans ModulesSetup, qui tourne au
 *    boot ; elle valait d'être écrite deux fois plutôt que supposée ici.
 *
 * 2. LA TOLÉRANCE AUX AVERTISSEMENTS PHP. Le gestionnaire d'erreurs global promeut tout
 *    avertissement en ErrorException. Un warning incident — typiquement émis par le journal
 *    d'action utilisateur pendant un store() — transformerait sinon un upgrade de schéma réussi en
 *    échec. Les vraies erreurs restent propagées.
 *
 * 3. L'ÉTAT RENDU. Les appelants ont besoin de savoir QUI est en retard et de combien, pas
 *    seulement si l'ensemble a réussi : c'est ce que rendent getStatus() et getModules().
 */
class SetupUpdater implements IShortNameAutoloadable
{
    /**
     * Nombre maximal de passages de convergence.
     *
     * Borne de sécurité, pas un réglage : la boucle s'arrête d'elle-même dès qu'un passage ne
     * change plus aucune version. Elle n'existe que pour qu'un cycle de dépendances — deux modules
     * s'attendant l'un l'autre — se termine en rapport de retard plutôt qu'en boucle infinie.
     */
    private const MAX_ROUNDS = 8;

    /** @var SymfonyStyle|null Sortie console, absente en contexte web */
    private $io;

    /** @var bool Installer les modules disponibles mais absents de la table `modules` */
    private $install_mode = false;

    /** @var bool Ne rien écrire : se contenter de rendre l'état */
    private $dry_run = false;

    /** @var array<int, array{name: string, current: string, expected: string}> Modules en retard */
    private $status = [];

    /** @var string[] Noms des modules considérés */
    private $modules = [];

    /**
     * Passe en mode ligne de commande : les messages de progression deviennent visibles.
     *
     * Sans io, la classe reste silencieuse — c'est le cas du contrôleur web
     * (SetupController::updateAllModules), qui n'a pas de flux à écrire.
     */
    public function enableCommandLineMode(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }

    public function setInstallMode(?bool $install): self
    {
        $this->install_mode = (bool)$install;

        return $this;
    }

    public function setDryRunMode(?bool $dry_run): self
    {
        $this->dry_run = (bool)$dry_run;

        return $this;
    }

    /**
     * Met à jour ce qui peut l'être, puis rend l'état.
     *
     * L'ORDRE COMPTE. L'installation précède la convergence, parce qu'un module installé au même
     * passage peut être la dépendance qui débloque un autre. Et le relevé d'état vient en DERNIER,
     * après la convergence, sinon il rapporterait un retard que les passages suivants ont comblé.
     */
    public function runAll(): self
    {
        $this->modules = $this->availableModuleNames();

        if ($this->install_mode && !$this->dry_run) {
            $this->installMissingModules();
        }

        if (!$this->dry_run) {
            $this->converge();
        }

        $this->status = $this->computeStatus($this->currentVersions(), $this->targetVersions());

        return $this;
    }

    /**
     * Point d'entrée du contrôleur web, qui ne lit pas de valeur de retour.
     *
     * @throws Throwable
     */
    public function run(): void
    {
        $this->runAll();
    }

    /**
     * Modules en retard sur leur cible, un par entrée.
     *
     * Les clés 'name', 'current' et 'expected' sont le CONTRAT attendu par DBUpdate, qui les rend
     * telles quelles dans un tableau à trois colonnes et trie sur 'name'.
     *
     * @return array<int, array{name: string, current: string, expected: string}>
     */
    public function getStatus(): array
    {
        return $this->status;
    }

    /**
     * @return string[] Noms de tous les modules considérés, à jour ou non
     */
    public function getModules(): array
    {
        return $this->modules;
    }

    /**
     * Répète les passages d'upgrade jusqu'à stabilisation des versions.
     *
     * On compare les versions AVANT et APRÈS chaque passage : tant qu'une bouge, une dépendance
     * vient peut-être d'être satisfaite et un passage de plus peut débloquer quelqu'un.
     */
    private function converge(): void
    {
        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $before = $this->currentVersions();

            $this->upgradeAllTolerantly();

            $after = $this->currentVersions();

            if ($after === $before) {
                $this->note(sprintf('Versions stabilisées après %d passage(s).', $round));

                return;
            }

            $this->note(sprintf('Passage %d : versions modifiées, nouveau passage nécessaire.', $round));
        }

        $this->note(
            sprintf(
                'Arrêt après %d passages sans stabilisation : voir les modules en retard ci-dessous.',
                self::MAX_ROUNDS
            )
        );
    }

    /**
     * CModule::upgradeAll(), à l'abri des avertissements PHP incidents.
     *
     * Cf. le point 2 de l'en-tête de classe : sans ce filtre, un warning sans rapport avec le
     * schéma suffit à faire remonter une exception et à abandonner tous les modules restants.
     */
    private function upgradeAllTolerantly(): void
    {
        $this->upgradeTolerantly(
            static function (): void {
                CModule::upgradeAll();
            },
            'Passage d\'upgrade'
        );
    }

    /**
     * Exécute un travail d'upgrade à l'abri des avertissements PHP, et sans qu'un échec emporte la
     * suite.
     *
     * Un module qui casse ne doit pas emporter les autres : il ressortira en retard dans
     * getStatus(), ce qui est une information utilisable, là où une exception remontée jusqu'à la
     * commande ne dit rien de l'état atteint.
     */
    private function upgradeTolerantly(callable $work, string $label): void
    {
        set_error_handler(
            static function (int $no, string $str, string $file = '', int $line = 0): bool {
                if (
                    in_array(
                        $no,
                        [E_WARNING, E_NOTICE, E_DEPRECATED, E_STRICT, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED],
                        true
                    )
                ) {
                    return true;
                }

                throw new ErrorException($str, 0, $no, $file, $line);
            }
        );

        try {
            $work();
        } catch (Throwable $t) {
            $this->note(sprintf('%s : %s', $label, $t->getMessage()));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Installe les modules disponibles sur le disque mais absents de la table `modules`.
     *
     * C'est le sens de l'option `--install` de `ox-db:update` : « Install every available module ».
     *
     * ON NE POSE PAS LA LIGNE SOI-MÊME. Un premier jet de cette méthode construisait le CModule et
     * appelait store() : tous les modules étaient refusés sur « CModule-mod_type : ne peut pas
     * avoir une valeur nulle », parce que le type est porté par la classe de SETUP
     * (CSetup::$mod_type) et non deviné. C'est donc à $setup->upgrade() de créer le module, comme
     * le fait déjà ModulesSetup : il renseigne le type, enregistre la ligne et rejoue les
     * révisions depuis 0.0 d'un seul mouvement. Rien de la logique de migration n'est dupliqué
     * ici.
     */
    private function installMissingModules(): void
    {
        $installed = $this->currentVersions();

        foreach ($this->modules as $name) {
            if (array_key_exists($name, $installed)) {
                continue;
            }

            $setup_class = CSetup::getCSetupClass($name);

            if (!$setup_class) {
                continue;
            }

            $module              = new CModule();
            $module->mod_name    = $name;
            $module->mod_version = '0.0';

            $this->upgradeTolerantly(
                static function () use ($setup_class, $module): void {
                    /** @var CSetup $setup */
                    $setup = new $setup_class();
                    $setup->upgrade($module);
                },
                sprintf('Installation de %s', $name)
            );
        }
    }

    /**
     * Versions actuellement enregistrées, lues en base et NON dans le cache des modules.
     *
     * Le cache est chargé une fois pour la durée du processus : s'y fier ferait comparer l'état
     * d'avant-passage à lui-même, et la boucle de convergence se croirait stabilisée au premier
     * tour.
     *
     * @return array<string, string> mod_name => mod_version
     */
    private function currentVersions(): array
    {
        $ds = CSQLDataSource::get('std');

        if (!$ds) {
            return [];
        }

        return $ds->loadHashList('SELECT `mod_name`, `mod_version` FROM `modules` ORDER BY `mod_name`') ?: [];
    }

    /**
     * Révision cible de chaque module considéré, lue dans sa classe de setup.
     *
     * @return array<string, string> mod_name => révision cible
     */
    private function targetVersions(): array
    {
        $targets = [];

        foreach ($this->modules as $name) {
            $setup_class = CSetup::getCSetupClass($name);

            if (!$setup_class) {
                continue;
            }

            /** @var CSetup $setup */
            $setup = new $setup_class();

            if ($setup->mod_version !== null) {
                $targets[$name] = (string)$setup->mod_version;
            }
        }

        return $targets;
    }

    /**
     * Compare l'installé à la cible.
     *
     * Méthode PURE, et c'est délibéré : c'est la seule partie dont le comportement se teste sans
     * base de données, et c'est aussi celle dont une erreur se verrait le moins — un module en
     * retard omis du rapport ferait conclure à tort que la mise à jour est complète.
     *
     * Un module SANS cible connue est ignoré : il n'a pas de classe de setup, donc pas de révision
     * à atteindre. Un module dont la version installée DÉPASSE la cible n'est pas en retard non
     * plus ; c'est une base plus récente que le code, anomalie réelle mais qui n'est pas ce que
     * cette commande répare.
     *
     * @param array<string, string> $current
     * @param array<string, string> $targets
     *
     * @return array<int, array{name: string, current: string, expected: string}>
     */
    protected function computeStatus(array $current, array $targets): array
    {
        $status = [];

        foreach ($targets as $name => $expected) {
            $installed = $current[$name] ?? null;

            if ($installed === null) {
                // Disponible, jamais installé : en retard sur tout, et c'est bien un retard à
                // signaler — sans quoi `--dry-run` tairait les modules qu'`--install` poserait.
                $status[] = ['name' => $name, 'current' => '—', 'expected' => $expected];

                continue;
            }

            if (version_compare($installed, $expected, '<')) {
                $status[] = ['name' => $name, 'current' => $installed, 'expected' => $expected];
            }
        }

        return $status;
    }

    /**
     * Noms des modules disponibles : ceux qui portent une classe de setup.
     *
     * Lus sur le DISQUE et non dans la table `modules`, parce que `--install` doit précisément
     * pouvoir poser un module que la base ne connaît pas encore.
     *
     * @return string[]
     */
    private function availableModuleNames(): array
    {
        $root = CAppUI::conf('root_dir') ?: dirname(__DIR__, 3);
        $dirs = glob(rtrim($root, '/') . '/modules/*', GLOB_ONLYDIR) ?: [];

        $names = [];

        foreach ($dirs as $dir) {
            $name = basename($dir);

            if (CSetup::getCSetupClass($name)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    private function note(string $message): void
    {
        if ($this->io !== null) {
            $this->io->writeln('  ' . $message);
        }
    }
}
