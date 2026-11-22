<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\ModulesSetup;
use Ox\Core\CSetup;
use Ox\Tests\UnitTestHospilibre;
use ReflectionClass;
use Throwable;

/**
 * Distinction entre un module en retard POUR UNE RAISON ACTIONNABLE et un module bloqué par une
 * dépendance qui n'est pas distribuée avec ce fork.
 *
 * L'enjeu est le code de sortie de `ox-modules:setup`. Il a été rendu non nul pour qu'une migration
 * à moitié faite cesse de passer pour un succès ; mais s'il était rouge à chaque déploiement à
 * cause d'un module structurellement incomplet, plus personne ne le regarderait et le contrôle
 * n'aurait servi à rien. Seul un retard rattrapable doit donc faire échouer la commande.
 *
 * Le filtre sur les révisions est le point délicat, et il s'est trompé une première fois :
 * CSetup::upgrade() positionne son curseur SUR la version installée, si bien que l'étape en attente
 * est celle dont la clé ÉGALE cette version — pas la suivante.
 */
class ModulesSetupBlockersTest extends UnitTestHospilibre
{
    /**
     * @throws Throwable
     */
    public function testADependencyOnAnUndistributedModuleIsDetected(): void
    {
        $setup = $this->setupDependingOn('0.02', 'moduleQuiNExistePas');

        $this->assertSame(['moduleQuiNExistePas'], $this->blockers($setup, '0.02'));
    }

    /**
     * Une dépendance vers un module BIEN présent n'est pas un blocage structurel : le module est
     * simplement en retard, et la commande doit échouer pour qu'on s'en occupe.
     *
     * @throws Throwable
     */
    public function testADependencyOnAPresentModuleIsNotStructural(): void
    {
        $setup = $this->setupDependingOn('0.02', 'mediusers');

        $this->assertSame([], $this->blockers($setup, '0.02'));
    }

    /**
     * L'étape en attente est celle dont la clé égale la version installée. La retenir à partir de
     * la version SUIVANTE faisait rater monitoringPatient, dont la dépendance est déclarée juste
     * après `makeRevision('0.02')` alors qu'il est installé en 0.02.
     *
     * @throws Throwable
     */
    public function testThePendingStepIsTheOneKeyedByTheInstalledVersion(): void
    {
        $setup = $this->setupDependingOn('0.02', 'moduleQuiNExistePas');

        $this->assertSame(
            ['moduleQuiNExistePas'],
            $this->blockers($setup, '0.02'),
            "La dépendance de l'étape en attente doit être vue"
        );
    }

    /**
     * Une dépendance d'une révision DÉJÀ FRANCHIE n'explique pas le blocage actuel : la retenir
     * ferait passer un vrai problème pour une fatalité, et le masquerait derrière un code de sortie
     * nul.
     *
     * @throws Throwable
     */
    public function testADependencyFromAnAlreadyPassedRevisionIsIgnored(): void
    {
        $setup = $this->setupDependingOn('0.01', 'moduleQuiNExistePas');

        $this->assertSame([], $this->blockers($setup, '0.05'));
    }

    /**
     * @return string[]
     * @throws Throwable
     */
    private function blockers(CSetup $setup, string $installed): array
    {
        $command = new ModulesSetup();

        // La racine de la copie de travail sert à tester la présence d'un répertoire de module.
        $reflection = new ReflectionClass($command);
        $property   = $reflection->getProperty('root');
        $property->setAccessible(true);
        $property->setValue($command, dirname(__DIR__, 3));

        return $this->invokePrivateMethod($command, 'blockingAbsentModules', $setup, $installed);
    }

    /**
     * CSetup portant une unique dépendance, déclarée à la révision demandée.
     */
    private function setupDependingOn(string $revision, string $module): CSetup
    {
        $setup           = new CSetup();
        $setup->mod_name = 'moduleDeTest';

        $dependency           = new \stdClass();
        $dependency->module   = $module;
        $dependency->revision = '0.4';

        $setup->dependencies = [$revision => [$dependency]];

        return $setup;
    }
}
