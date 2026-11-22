<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\InstallExternals;
use Ox\Tests\UnitTestHospilibre;
use ReflectionMethod;

/**
 * UN DUMP DE DICTIONNAIRE NE DOIT JAMAIS S'EXÉCUTER SUR LA BASE DE L'APPLICATION.
 *
 * Le dump CIM-10 livré dans le dépôt contient 15 DROP TABLE et 15 CREATE TABLE, dont une table
 * nommée `note`. Sur ces quinze noms, un seul collisionne avec une table de l'application :
 * précisément `note`, celle de CNote, qui porte object_id. L'importer dans la base applicative
 * exécute donc « DROP TABLE note » et détruit la table des notes — notes mortes, et suppression
 * d'objets cassée sur les chemins qui remontent les références arrière.
 *
 * Mesuré sur la production le 2026-10-03 : sa table `note` ne porte plus que SID et MID, et huit
 * tables du dump vivent dans sa base applicative depuis le 2026-07-25. Plus de deux mois sans que
 * personne le voie.
 *
 * Et la chaîne est armée sur toute instance déployée : --declare écrit la datasource, puis
 * includes/config_all.php réaligne toute datasource secondaire sur DB_NAME — donc sur la base de
 * l'application. Le nom de base séparé qu'avait écrit --declare est écrasé au chargement.
 */
class InstallExternalsApplicationDatabaseGuardTest extends UnitTestHospilibre
{
    private function collides(string $target, string $app, string $target_host, string $app_host): bool
    {
        $method = new ReflectionMethod(InstallExternals::class, 'collidesWithApplication');
        $method->setAccessible(true);

        return (bool)$method->invoke(null, $target, $app, $target_host, $app_host);
    }

    /**
     * LE CAS RÉEL : config_all.php a réaligné le dictionnaire sur la base de l'application.
     */
    public function testSameDatabaseOnSameHostIsRefused(): void
    {
        $this->assertTrue(
            $this->collides('hospilibre', 'hospilibre', 'db', 'db'),
            "C'est l'état mesuré sur la production : « db cim10 dbname » vaut la base de l'application."
        );
    }

    /**
     * Le cas sain, et celui que --declare voulait produire : une base dédiée.
     */
    public function testADedicatedDatabaseIsAllowed(): void
    {
        $this->assertFalse($this->collides('cim10', 'hospilibre', 'db', 'db'));
    }

    /**
     * L'HÔTE COMPTE. Un même nom de base sur deux serveurs distincts désigne deux bases
     * différentes : refuser là serait refuser un import parfaitement sûr.
     */
    public function testTheSameNameOnAnotherHostIsNotACollision(): void
    {
        $this->assertFalse($this->collides('hospilibre', 'hospilibre', 'db-externe', 'db'));
    }

    /**
     * UN HÔTE CIBLE VIDE VAUT COLLISION : la datasource hérite de l'hôte par défaut, qui est celui
     * de l'application. Le coût d'un refus à tort est un import à relancer ; celui d'un import à
     * tort est la table des notes détruite.
     */
    public function testAnEmptyTargetHostIsTreatedAsACollision(): void
    {
        $this->assertTrue($this->collides('hospilibre', 'hospilibre', '', 'db'));
    }

    /**
     * On n'invente pas un refus sur une information manquante : sans nom de base applicative, la
     * collision n'est pas établie, et cette absence est signalée ailleurs.
     */
    public function testNothingIsRefusedWhenEitherNameIsUnknown(): void
    {
        $this->assertFalse($this->collides('hospilibre', '', 'db', 'db'));
        $this->assertFalse($this->collides('', 'hospilibre', 'db', 'db'));
    }
}
