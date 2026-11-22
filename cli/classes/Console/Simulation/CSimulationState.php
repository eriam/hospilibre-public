<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console\Simulation;

use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;

/**
 * État de la simulation (datasource std) : sentinelle de bootstrap (l'hôpital
 * fictif a-t-il été créé, sous quel group_id) + horloge de simulation (date
 * courante simulée, alimentée par le Plan 2). Une seule ligne (id=1).
 */
class CSimulationState
{
    private const TABLE = 'simulation_state';

    public function ensureTable(): void
    {
        $ds = CSQLDataSource::get('std');
        $ds->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` ('
            . '`id` TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,'
            . '`group_id` INT UNSIGNED NULL,'
            . '`config_name` VARCHAR(64) NULL,'
            . '`bootstrapped_at` DATETIME NULL,'
            . '`sim_clock` DATETIME NULL,'
            . '`phase` VARCHAR(16) NULL,'
            . '`crisis_name` VARCHAR(64) NULL,'
            . '`crisis_start` DATETIME NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        // Tables créées par une version antérieure (.32, dev) : colonnes ajoutées en place.
        // MariaDB >= 10.0 accepte ADD COLUMN IF NOT EXISTS.
        foreach (['`phase` VARCHAR(16) NULL', '`crisis_name` VARCHAR(64) NULL', '`crisis_start` DATETIME NULL'] as $col) {
            $ds->exec('ALTER TABLE `' . self::TABLE . '` ADD COLUMN IF NOT EXISTS ' . $col);
        }
    }

    public function isBootstrapped(): bool
    {
        return $this->getPhase() === 'seeded';
    }

    /** null = aucun semis ; 'bootstrapping' = semis commencé non terminé ; 'seeded' = semis complet.
     *  Une ligne d'avant l'ajout de la colonne (phase NULL) est un semis complet. */
    public function getPhase(): ?string
    {
        $ds  = CSQLDataSource::get('std');
        $row = $ds->loadHash('SELECT `group_id`, `phase` FROM `' . self::TABLE . '` WHERE `id` = 1');
        if (!$row || $row['group_id'] === null) {
            return null;
        }

        return $row['phase'] ?: 'seeded';
    }

    public function getConfigName(): ?string
    {
        $ds  = CSQLDataSource::get('std');
        $val = $ds->loadResult('SELECT `config_name` FROM `' . self::TABLE . '` WHERE `id` = 1');

        return $val === null || $val === false ? null : (string) $val;
    }

    /** Pose la sentinelle DÈS la création de l'établissement : un semis interrompu laisse un
     *  group_id que simulate:reset sait purger, au lieu d'un orphelin invisible. */
    public function markBootstrapping(int $groupId, string $configName): void
    {
        $ds = CSQLDataSource::get('std');
        $ds->exec(
            'REPLACE INTO `' . self::TABLE . '` (`id`,`group_id`,`config_name`,`phase`) '
            . 'VALUES (1, ' . (int) $groupId . ", '" . $ds->escape($configName) . "', 'bootstrapping')"
        );
    }

    public function setCrisis(string $name, string $start): void
    {
        $ds = CSQLDataSource::get('std');
        $ds->exec(
            'UPDATE `' . self::TABLE . "` SET `crisis_name` = '" . $ds->escape($name)
            . "', `crisis_start` = '" . $ds->escape($start) . "' WHERE `id` = 1"
        );
    }

    public function clearCrisis(): void
    {
        CSQLDataSource::get('std')->exec(
            'UPDATE `' . self::TABLE . '` SET `crisis_name` = NULL, `crisis_start` = NULL WHERE `id` = 1'
        );
    }

    /** @return array{name:string, start:string}|null */
    public function getCrisis(): ?array
    {
        $ds  = CSQLDataSource::get('std');
        $row = $ds->loadHash('SELECT `crisis_name`, `crisis_start` FROM `' . self::TABLE . '` WHERE `id` = 1');
        if (!$row || !$row['crisis_name'] || !$row['crisis_start']) {
            return null;
        }

        return ['name' => (string) $row['crisis_name'], 'start' => (string) $row['crisis_start']];
    }

    public function getGroupId(): ?int
    {
        $ds  = CSQLDataSource::get('std');
        $val = $ds->loadResult('SELECT `group_id` FROM `' . self::TABLE . '` WHERE `id` = 1');

        return $val === null || $val === false ? null : (int) $val;
    }

    public function getClock(): ?string
    {
        $ds  = CSQLDataSource::get('std');
        $val = $ds->loadResult('SELECT `sim_clock` FROM `' . self::TABLE . '` WHERE `id` = 1');

        return $val === null || $val === false ? null : (string) $val;
    }

    public function setClock(string $datetime): void
    {
        $ds = CSQLDataSource::get('std');
        $ds->exec(
            'UPDATE `' . self::TABLE . '` SET `sim_clock` = '
            . "'" . $ds->escape($datetime) . "' WHERE `id` = 1"
        );
    }

    public function getBootstrappedAt(): ?string
    {
        $ds  = CSQLDataSource::get('std');
        $val = $ds->loadResult('SELECT `bootstrapped_at` FROM `' . self::TABLE . '` WHERE `id` = 1');

        return $val === null || $val === false ? null : (string) $val;
    }

    public function markBootstrapped(int $groupId, string $configName): void
    {
        // $configName vient du registre/config en dur, $groupId est un entier.
        // On horodate via CMbDT::dateTime() (fuseau PHP applicatif) et NON SQL NOW()
        // (fuseau serveur MySQL, souvent UTC) : sinon l'horloge de bootstrap est dans
        // un fuseau différent des dates d'événements (entree/sortie séjours…) et de la
        // cible d'avance (--until=now, elle aussi CMbDT) — d'où un décalage permanent.
        $ds  = CSQLDataSource::get('std');
        $now = CMbDT::dateTime();
        $ds->exec(
            'REPLACE INTO `' . self::TABLE . '` (`id`,`group_id`,`config_name`,`bootstrapped_at`,`sim_clock`,`phase`) '
            . "VALUES (1, " . (int) $groupId . ", '" . $ds->escape($configName)
            . "', '" . $ds->escape($now) . "', '" . $ds->escape($now) . "', 'seeded')"
        );
    }

    public function clear(): void
    {
        CSQLDataSource::get('std')->exec('DELETE FROM `' . self::TABLE . '` WHERE `id` = 1');
    }
}
