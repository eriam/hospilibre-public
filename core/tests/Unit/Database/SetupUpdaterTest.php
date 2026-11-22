<?php

/**
 * @package Ox\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Core\Tests\Unit\Database;

use Ox\Core\Database\SetupUpdater;
use Ox\Tests\UnitTestHospilibre;

/**
 * Le relevé de retard, qui est la seule partie de SetupUpdater testable sans base.
 *
 * ET C'EST LA PLUS IMPORTANTE À TESTER, parce qu'une erreur y est silencieuse dans le sens
 * rassurant : un module en retard omis du rapport fait sortir `ox-db:update` en succès et conclure
 * que la base est à jour. C'est précisément ce genre de mensonge qui a laissé la panne d'origine
 * n'apparaître qu'au semis, huit minutes plus tard.
 *
 * La mécanique d'upgrade elle-même n'est pas retestée ici : elle vit dans CModule::upgradeAll() et
 * CSetup::upgrade(), qui ont leurs propres couvertures, et SetupUpdater ne fait que les orchestrer.
 */
class SetupUpdaterTest extends UnitTestHospilibre
{
    /**
     * Expose la méthode pure, sans toucher à la base.
     */
    private function updater(): object
    {
        return new class extends SetupUpdater {
            /**
             * @param array<string, string> $current
             * @param array<string, string> $targets
             *
             * @return array<int, array<string, string>>
             */
            public function exposeComputeStatus(array $current, array $targets): array
            {
                return $this->computeStatus($current, $targets);
            }
        };
    }

    public function testAModuleAtItsTargetIsNotReported(): void
    {
        $status = $this->updater()->exposeComputeStatus(
            ['system' => '1.5.8'],
            ['system' => '1.5.8']
        );

        $this->assertSame([], $status, 'Un module à jour ne doit pas figurer dans le relevé de retard.');
    }

    public function testAModuleBelowItsTargetIsReportedWithBothVersions(): void
    {
        $status = $this->updater()->exposeComputeStatus(
            ['dPpatients' => '3.12'],
            ['dPpatients' => '4.92']
        );

        $this->assertSame(
            [['name' => 'dPpatients', 'current' => '3.12', 'expected' => '4.92']],
            $status,
            "Les clés name/current/expected sont le contrat attendu par DBUpdate, qui les rend"
            . ' telles quelles dans son tableau.'
        );
    }

    /**
     * Le piège de la comparaison de versions : un tri de chaînes place « 3.9 » après « 3.10 ».
     */
    public function testVersionsAreComparedAsVersionsAndNotAsStrings(): void
    {
        $status = $this->updater()->exposeComputeStatus(
            ['dPbloc' => '0.9'],
            ['dPbloc' => '0.15']
        );

        $this->assertCount(
            1,
            $status,
            '0.9 est BIEN en retard sur 0.15 : comparé comme chaîne, « 0.9 » passerait pour plus récent.'
        );
    }

    /**
     * Sans ça, --dry-run tairait exactement les modules qu'--install doit poser.
     */
    public function testAnAvailableButNeverInstalledModuleIsReported(): void
    {
        $status = $this->updater()->exposeComputeStatus(
            [],
            ['dPbloc' => '0.15']
        );

        $this->assertSame(
            [['name' => 'dPbloc', 'current' => '—', 'expected' => '0.15']],
            $status,
            'Un module disponible et jamais installé est en retard, et doit se voir.'
        );
    }

    /**
     * Une base plus récente que le code est une anomalie réelle, mais ce n'est pas un RETARD : la
     * signaler comme tel ferait sortir la commande en échec sur quelque chose qu'elle ne répare
     * pas, et ferait boucler un déploiement sans fin.
     */
    public function testAModuleAheadOfItsTargetIsNotReportedAsLate(): void
    {
        $status = $this->updater()->exposeComputeStatus(
            ['system' => '2.0'],
            ['system' => '1.5.8']
        );

        $this->assertSame([], $status);
    }

    /**
     * Un module sans classe de setup n'a pas de cible : il n'apparaît pas dans les cibles, donc
     * jamais dans le relevé — même s'il est présent en base.
     */
    public function testAModuleWithoutATargetIsIgnored(): void
    {
        $status = $this->updater()->exposeComputeStatus(
            ['moduleSansSetup' => '0.1'],
            []
        );

        $this->assertSame([], $status);
    }
}
