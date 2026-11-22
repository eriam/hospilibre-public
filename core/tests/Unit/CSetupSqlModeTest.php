<?php

/**
 * @package Hospilibre\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Core\Tests\Unit;

use Hospilibre\System\CSetupSystem;
use Ox\Core\CSQLDataSource;
use Ox\Tests\UnitTestHospilibre;
use Throwable;

/**
 * Le mode SQL permissif des migrations ne doit PAS fuiter hors de la migration.
 *
 * `CSetup::upgrade()` rétablit un `sql_mode` vide le temps de rejouer les révisions : celles-ci ont
 * été écrites pour les réglages de MySQL 5.x, où insérer '' dans une colonne entière valait 0,
 * alors que MariaDB active STRICT_TRANS_TABLES par défaut et le refuse.
 *
 * C'est une permissivité volontairement CONFINÉE. Si elle survivait à la migration, tout le reste
 * du processus écrirait sans filet : des valeurs tronquées ou coercées passeraient en base sans que
 * rien ne le signale, et ce serait bien pire que le problème résolu. Ce test vérifie donc les deux
 * moitiés du contrat — la détente, et la restitution.
 *
 * À l'exécution normale, l'application n'a PAS besoin de ce mode : `CSQLDataSource::insertObject()`
 * omet purement et simplement les chaînes vides, et `updateObject()` les convertit en NULL
 * (`$nullifyEmptyStrings`, vrai par défaut et qu'aucun modèle ne désactive). Elle n'envoie donc
 * jamais '' dans une colonne numérique. C'est ce qui justifie de ne pas toucher au mode en dehors
 * des migrations.
 */
class CSetupSqlModeTest extends UnitTestHospilibre
{
    /**
     * @throws Throwable
     */
    public function testTheStrictModeIsRestoredAfterAMigration(): void
    {
        $ds = CSQLDataSource::get('std');

        $before = (string)$ds->loadResult('SELECT @@session.sql_mode');

        $setup = new CSetupSystem();

        $previous = $this->invokePrivateMethod($setup, 'relaxSqlMode');

        $this->assertSame(
            $before,
            (string)$previous,
            'La détente doit rendre le mode précédent, faute de quoi rien ne pourra le rétablir'
        );
        $this->assertSame(
            '',
            (string)$ds->loadResult('SELECT @@session.sql_mode'),
            'Pendant la migration, le mode doit être permissif'
        );

        $this->invokePrivateMethod($setup, 'restoreSqlMode', $previous);

        $this->assertSame(
            $before,
            (string)$ds->loadResult('SELECT @@session.sql_mode'),
            'Après la migration, le mode strict doit être rétabli : une permissivité qui survit'
            . ' laisserait passer des coercitions silencieuses dans toute la suite du processus'
        );
    }

    /**
     * Une restitution sans mode précédent connu ne doit rien casser : c'est le cas quand la source
     * de données n'a pas pu être interrogée.
     *
     * @throws Throwable
     */
    public function testRestoringANullModeIsHarmless(): void
    {
        $ds     = CSQLDataSource::get('std');
        $before = (string)$ds->loadResult('SELECT @@session.sql_mode');

        $setup = new CSetupSystem();
        $this->invokePrivateMethod($setup, 'restoreSqlMode', null);

        $this->assertSame($before, (string)$ds->loadResult('SELECT @@session.sql_mode'));
    }

    /**
     * Le test ci-dessus ne prouve quelque chose que si le serveur est effectivement strict. S'il ne
     * l'était pas, la détente serait un non-événement et le garde-fou sans objet : autant le savoir.
     *
     * @throws Throwable
     */
    public function testTheServerIsStrictSoThatTheGuardMeansSomething(): void
    {
        $ds   = CSQLDataSource::get('std');
        $mode = (string)$ds->loadResult('SELECT @@session.sql_mode');

        $this->assertStringContainsString(
            'STRICT_TRANS_TABLES',
            $mode,
            'Sans mode strict, ni la détente ni sa restitution ne signifient rien'
        );
    }
}
