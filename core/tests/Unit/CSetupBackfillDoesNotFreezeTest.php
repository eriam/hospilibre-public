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
use ReflectionClass;

/**
 * SIGNALER N'EST PAS GELER.
 *
 * Mesuré sur la production : un seul backfill en échec a immobilisé QUATRE modules pendant des
 * jours. Le module system s'est arrêté à la révision 1.2.10 sur
 * « UPDATE `cronjob_log` SET `severity` = '1' WHERE `log` IS NOT NULL » -> Unknown column 'log',
 * et admin, dPdeveloppement et dPetablissement, qui exigent tous system >= 1.2.98, ont été bloqués
 * avec lui. Même forme pour dicom 1.09 sur `dicom_sender`.`create_ack_file`.
 *
 * Un backfill dont la colonne de filtre a disparu n'a rien sur quoi agir. L'erreur doit être dite —
 * elle l'est, et le rapporteur fait désormais échouer la commande — mais elle ne doit plus coûter
 * les 75 révisions suivantes.
 *
 * Ce test porte sur la RÈGLE de discrimination, pas sur une exécution réelle : rejouer une
 * migration complète ne prouverait rien de plus sur le point en cause et exigerait une base en
 * état précis.
 */
class CSetupBackfillDoesNotFreezeTest extends UnitTestHospilibre
{
    /**
     * La règle telle qu'elle est écrite dans upgrade() : code 1054 ET requête UPDATE/DELETE.
     */
    private function poursuit(int $errno, string $query): bool
    {
        return $errno === 1054 && (bool)preg_match('/^\s*(UPDATE|DELETE)\s/i', $query);
    }

    public function testABackfillWhoseFilterColumnIsGoneNoLongerStopsTheModule(): void
    {
        $this->assertTrue(
            $this->poursuit(1054, "UPDATE `cronjob_log` SET `severity` = '1' WHERE `log` IS NOT NULL"),
            "C'est la requête exacte qui a bloqué system à 1.2.10 sur la production."
        );
        $this->assertTrue(
            $this->poursuit(1054, "UPDATE `dicom_sender` SET `response` = 'postprocessor' WHERE `create_ack_file` = '1'"),
            "Et celle qui a bloqué dicom à 1.09 : même famille, autre table."
        );
        $this->assertTrue($this->poursuit(1054, "DELETE FROM `foo` WHERE `bar` = '1'"));
        $this->assertTrue(
            $this->poursuit(1054, "   update `foo` set `a` = '1' where `b` = '2'"),
            'Les requêtes du dépôt sont indentées et de casse variable.'
        );
    }

    /**
     * LA MOITIÉ QUI COMPTE AUTANT : un DDL qui ne trouve pas sa colonne signale un schéma
     * réellement incohérent. Élargir la règle au-delà des backfills reviendrait à poursuivre une
     * migration sur une base dont on sait qu'elle n'est pas dans l'état attendu.
     */
    public function testSchemaStatementsStillStopTheMigration(): void
    {
        $this->assertFalse(
            $this->poursuit(1054, "ALTER TABLE `foo` CHANGE `absente` `nouvelle` TEXT"),
            "Un ALTER qui ne trouve pas sa colonne n'est pas « déjà dans l'état cible »."
        );
        $this->assertFalse($this->poursuit(1054, "SELECT `absente` FROM `foo`"));
        $this->assertFalse($this->poursuit(1054, "INSERT INTO `foo` (`absente`) VALUES ('1')"));
    }

    /**
     * Et seule 1054 est concernée : une erreur de syntaxe ou un accès refusé sur un UPDATE reste
     * fatale, parce qu'elle ne dit rien sur l'état cible.
     */
    public function testOnlyUnknownColumnIsTreatedThisWay(): void
    {
        $this->assertFalse($this->poursuit(1064, "UPDATE `foo` SET `a` = '1' WHERE `b` = '2'"));
        $this->assertFalse($this->poursuit(1142, "UPDATE `foo` SET `a` = '1' WHERE `b` = '2'"));
        $this->assertFalse($this->poursuit(1146, "UPDATE `foo` SET `a` = '1' WHERE `b` = '2'"));
    }

    /**
     * La règle ne doit PAS être confondue avec ALREADY_IN_TARGET_STATE : ces codes signifient
     * « rien à faire, tout va bien » et sont traités en silence. 1054 dit le contraire — quelque
     * chose n'a pas été fait — et reste une erreur rapportée.
     */
    public function test1054IsNotDeclaredBenign(): void
    {
        $reflection = new ReflectionClass(CSetup::class);
        $codes      = $reflection->getConstant('ALREADY_IN_TARGET_STATE');

        $this->assertNotContains(
            1054,
            $codes,
            'Poursuivre la migration et déclarer la révision saine sont deux décisions distinctes.'
        );
    }
}
