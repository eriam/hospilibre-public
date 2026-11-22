<?php

/**
 * @package Ox\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Core\Tests\Unit;

use Ox\Core\CSetup;
use Ox\Tests\UnitTestHospilibre;

/**
 * UNE RÉVISION DOIT POUVOIR ÊTRE REJOUÉE.
 *
 * Ce n'est pas une précaution de confort : CSetup::upgrade() n'enregistre une révision qu'une fois
 * TOUTES ses requêtes passées. Si l'une échoue au milieu, celles d'avant restent appliquées et la
 * révision entière est rejouée au passage suivant — où un « ALTER ... CHANGE » butte sur son propre
 * travail (« Unknown column », erreur 1054). Et 1054 ne peut PAS rejoindre
 * ALREADY_IN_TARGET_STATE : c'est aussi le symptôme d'un schéma réellement incohérent. Le module
 * reste donc bloqué pour toujours, sans issue.
 *
 * Observé par un utilisateur du kit de cours sur dPhospi 0.67 (« Unknown column 'item_prestation_id' »), et
 * reproduit ici : un dPhospi bloqué à 0.67 est reparti jusqu'à sa cible 1.46 une fois la révision
 * rendue conditionnelle.
 *
 * Ce test couvre la DÉCISION — quand émettre du SQL, et lequel — en remplaçant la datasource par un
 * enregistreur. C'est la partie où une erreur serait silencieuse : un renommage émis à tort abîme
 * un schéma sain, un renommage omis à tort laisse le module bloqué.
 */
class CSetupReplaySafeTest extends UnitTestHospilibre
{
    /**
     * Un CSetup dont on contrôle l'état du schéma et dont on capture le SQL émis.
     *
     * @param string[] $existing_columns Colonnes réputées présentes sur la table
     */
    private function setupOver(array $existing_columns): object
    {
        $setup = new class extends CSetup {
            /** @var string[] */
            public $existing = [];

            /** @var string[] Requêtes émises */
            public $emitted = [];

            public function tableExists($table, $dsn = null)
            {
                return true;
            }

            public function columnExists($table, $field, $dsn = null)
            {
                return in_array($field, $this->existing, true);
            }

            public function callRename(string $table, array $renames): bool
            {
                return $this->renameColumnsIfNeeded($table, $renames);
            }

            public function callAddColumns(string $table, array $columns): bool
            {
                return $this->addColumnsIfMissing($table, $columns);
            }
        };

        $setup->existing = $existing_columns;

        // Enregistreur de requêtes : seul exec() est sollicité par les deux helpers.
        $setup->ds = new class ($setup) {
            private $owner;

            public function __construct($owner)
            {
                $this->owner = $owner;
            }

            public function exec($query)
            {
                $this->owner->emitted[] = preg_replace('/\s+/', ' ', trim($query));

                return true;
            }
        };

        return $setup;
    }

    public function testARenameIsEmittedWhenTheSourceIsStillThere(): void
    {
        $setup = $this->setupOver(['item_prestation_id']);

        $setup->callRename('item_liaison', ['item_prestation_id' => ['item_souhait_id', 'INT (11) UNSIGNED']]);

        $this->assertSame(
            ['ALTER TABLE `item_liaison` CHANGE `item_prestation_id` `item_souhait_id` INT (11) UNSIGNED'],
            $setup->emitted,
            'Sur installation fraîche, le helper doit faire exactement ce que faisait le CHANGE.'
        );
    }

    /**
     * LE CAS QUI BLOQUAIT dPhospi : le renommage a déjà eu lieu, la source n'existe plus.
     */
    public function testNoRenameIsEmittedWhenItHasAlreadyBeenDone(): void
    {
        $setup = $this->setupOver(['item_souhait_id']);

        $setup->callRename('item_liaison', ['item_prestation_id' => ['item_souhait_id', 'INT (11) UNSIGNED']]);

        $this->assertSame([], $setup->emitted, 'Rejouer un renommage déjà fait doit être un no-op.');
    }

    /**
     * Ni source ni cible : la colonne n'a jamais existé. On ne tente rien — un CHANGE échouerait,
     * et deviner laquelle créer serait une invention.
     */
    public function testNothingIsEmittedWhenNeitherSourceNorTargetExists(): void
    {
        $setup = $this->setupOver([]);

        $setup->callRename('item_liaison', ['item_prestation_id' => ['item_souhait_id', 'INT (11) UNSIGNED']]);

        $this->assertSame([], $setup->emitted);
    }

    /**
     * LE CAS QUI BLOQUAIT dPplanningOp : un ALTER multi-colonnes est ATOMIQUE, donc rejeté en bloc
     * si UNE colonne existe déjà — et l'autre n'est jamais ajoutée, alors que l'erreur 1060 est
     * traitée comme bénigne. La requête suivante de la même révision s'effondrait ensuite sur
     * « Unknown column ». Ajoutées une par une, une colonne déjà présente ne prive plus l'autre.
     */
    public function testAnExistingColumnNoLongerPreventsTheOthersFromBeingAdded(): void
    {
        $setup = $this->setupOver(['ASA']);

        $setup->callAddColumns(
            'operations',
            [
                'ASA'      => "ENUM ('1','2') DEFAULT '1'",
                'position' => "ENUM ('DD','DV')",
            ]
        );

        $this->assertSame(
            ["ALTER TABLE `operations` ADD `position` ENUM ('DD','DV')"],
            $setup->emitted,
            'ASA est sautée parce qu\'elle existe ; position doit malgré tout être ajoutée.'
        );
    }

    public function testAllMissingColumnsAreAddedOneByOne(): void
    {
        $setup = $this->setupOver([]);

        $setup->callAddColumns('operations', ['ASA' => 'INT', 'position' => 'INT']);

        $this->assertSame(
            [
                'ALTER TABLE `operations` ADD `ASA` INT',
                'ALTER TABLE `operations` ADD `position` INT',
            ],
            $setup->emitted,
            'Une requête par colonne : c\'est ce qui empêche un ajout de priver les autres.'
        );
    }

    public function testNothingIsEmittedWhenEveryColumnIsAlreadyThere(): void
    {
        $setup = $this->setupOver(['ASA', 'position']);

        $setup->callAddColumns('operations', ['ASA' => 'INT', 'position' => 'INT']);

        $this->assertSame([], $setup->emitted);
    }
}
