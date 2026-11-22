<?php

/**
 * @package Hospilibre\PlanningOp
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\PlanningOp\Tests\Fixtures;

use Exception;
use Ox\Cli\Seed\HospiSeeder;
use Ox\Cli\Seed\ModuleGuard;
use Ox\Cli\Seed\OrmWriter;
use Ox\Cli\Seed\SeedContext;
use Ox\Cli\Seed\SeedProfile;
use Ox\Cli\Seed\SeedPurger;
use Ox\Cli\Seed\SeedRandom;
use Ox\Tests\Fixtures\Fixtures;
use Ox\Tests\Fixtures\FixturesException;
use Ox\Tests\Fixtures\GroupFixturesInterface;
use Throwable;

/**
 * Enveloppe du seed d'hospitalisation pour `ox-fixtures:load --groups=hospi-seed`.
 *
 * @description Etablissement complet avec sejours, affectations et interventions
 *
 * Elle ne contient AUCUNE logique métier : elle délègue aux mêmes builders que la commande
 * `ox-seed:hospi`. Deux consommateurs, un seul moteur — dupliquer la construction entre les deux
 * garantirait qu'ils divergent.
 *
 * Profil `small` et graine FIXE : une fixture doit produire le même jeu de données à chaque
 * exécution, sans quoi la composition avec les autres fixtures et les tests qui en dépendent
 * deviendraient instables. Pour une autre échelle, c'est la commande qu'il faut employer.
 *
 * L'instant de référence, lui, reste l'heure courante : la promesse d'une fixture est de peupler
 * une instance VIVANTE (des séjours en cours, des admissions à venir), pas de rejouer une date
 * figée. Les comptes et les étiquettes sont reproductibles ; les dates suivent le jour où la
 * fixture tourne.
 *
 * La purge est celle héritée de Fixtures : elle s'appuie sur les lignes CFixturesReference
 * portant cette classe, ce que produit l'OrmWriter ci-dessous.
 *
 * À SAVOIR sur l'affichage de `ox-fixtures:load` : la colonne « refs » du chargement affiche 0
 * pour cette fixture. Le chargeur compte les références passées par Fixtures::addReference(), or
 * l'OrmWriter écrit directement ses lignes CFixturesReference. Les objets SONT bien tracés — la
 * ligne de purge, elle, en dénombre bien un millier — c'est le compteur du chargeur qui ne les
 * voit pas.
 */
class HospiSeedFixtures extends Fixtures implements GroupFixturesInterface
{
    public const GROUP = 'hospi-seed';

    /**
     * Graine figée. La changer change TOUTES les données produites par cette fixture.
     */
    public const SEED = 20260925;

    /**
     * Priorité basse : ce jeu de données est volumineux et ne sert de socle à aucune autre
     * fixture. Il doit donc passer après elles.
     */
    public static function getGroup(): array
    {
        return [self::GROUP, 10];
    }

    /**
     * @throws FixturesException
     * @throws Exception
     */
    public function load(): void
    {
        $inactive = ModuleGuard::inactiveModules();

        if ($inactive !== []) {
            // markSkipped plutôt qu'une exception : un module manquant n'est pas une faute de la
            // fixture, et la suite du chargement doit se poursuivre.
            $this->markSkipped(
                'Modules inactifs, seed impossible : ' . implode(', ', $inactive)
            );
        }

        $context = new SeedContext(
            SeedProfile::named(SeedProfile::SMALL),
            new SeedRandom(self::SEED),
            new OrmWriter(static::class)
        );

        (new HospiSeeder($context))->run();
    }

    /**
     * Purge vérifiée, par le même chemin que « ox-seed:hospi --purge ».
     *
     * L'implémentation héritée ignore le message rendu par purge() : un échec y passait
     * inaperçu et laissait le jeu de données en place. SeedPurger, lui, contrôle son résultat, et
     * REFUSE de purger un run écrit par lots par cette voie — les entités de masse n'y étant pas
     * tracées objet par objet, elle les laisserait orphelines.
     *
     * @throws Throwable
     */
    public function purge(): void
    {
        $purger = new SeedPurger(static::class);
        $purger->purge();
        $purger->assertClean();
    }
}
