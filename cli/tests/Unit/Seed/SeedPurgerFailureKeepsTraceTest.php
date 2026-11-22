<?php

/**
 * @package Hospilibre\Cli\Tests
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Hospilibre\Etablissement\CGroups;
use Hospilibre\System\CFixturesReference;
use Hospilibre\System\CNote;
use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SeedPurger;
use Ox\Core\CMbDT;
use Ox\Tests\UnitTestHospilibre;

/**
 * UNE PURGE EN ÉCHEC DOIT CONSERVER LA TRACE DE CE QUI A RÉSISTÉ.
 *
 * POURQUOI CE TEST EXISTE, ET CE QUE SON ABSENCE A COÛTÉ. La classe effaçait la trace même quand la
 * suppression de la cible venait d'échouer : l'objet survivait sans sa trace, et comme la purge ne
 * connaît les objets du semis QUE par leurs traces, il devenait introuvable. Le correctif a été
 * livré le 2026-10-04 en disant explicitement que cette branche n'était PAS vérifiée — deux
 * tentatives de reproduction sur un vrai jeu de données avaient réussi leur purge, et rien ne
 * permettait de fabriquer un objet qui refuse de partir.
 *
 * Quelques heures plus tard la branche s'est exercée en production : une purge a buté sur une table
 * substituée, après avoir déjà supprimé 8 324 identifiants de patients. Le correctif a tenu, les
 * traces ont survécu, la reprise est restée possible — mais c'est la panne qui l'a prouvé.
 *
 * Ce test le prouve désormais sans panne, grâce à l'action de suppression injectable de
 * `SeedPurger`. Il éprouve LE REFUS, pas le chemin heureux : c'est le refus qui protège l'instance.
 */
class SeedPurgerFailureKeepsTraceTest extends UnitTestHospilibre
{
    /** Classe de traçage propre à ce test : rien d'autre ne doit entrer dans son périmètre. */
    private const MARKER = 'test-seed-purger-failure';

    /** @var CNote[] */
    private $notes = [];

    protected function tearDown(): void
    {
        // Nettoyage par les deux bouts : les références d'abord, car elles pointent les notes en
        // cascade, puis les notes. Un test qui laisserait des traces fausserait le suivant, dont le
        // compte de référence est l'assertion même.
        $reference = new CFixturesReference();

        foreach ($reference->loadList(['fixtures_class' => "= '" . self::MARKER . "'"]) as $_reference) {
            $_reference->purge();
        }

        foreach ($this->notes as $note) {
            if ($note->_id) {
                $note->purge();
            }
        }

        $this->notes = [];

        parent::tearDown();
    }

    /**
     * LE CAS QUI A COÛTÉ : la cible refuse, et sa trace DOIT rester.
     *
     * @throws SeedException
     */
    public function testAFailedTargetKeepsItsReference(): void
    {
        $this->traceANote();

        $purger = new SeedPurger(
            self::MARKER,
            // Refus simulé : c'est exactement ce que rend `CStoredObject::purge()` quand il
            // n'aboutit pas, et ce que la table `note` substituée produisait en production.
            static fn(): string => 'refus simulé pour le test'
        );

        $this->assertSame(1, $purger->count(), 'La trace doit exister avant la purge.');
        $this->assertSame(0, $purger->purge(), 'Aucun objet ne doit être compté comme supprimé.');

        $this->assertSame(
            1,
            $purger->count(),
            "LA TRACE DOIT SURVIVRE À L'ÉCHEC : sans elle, l'objet devient introuvable et aucune"
            . ' commande ne peut plus le reprendre.'
        );

        $this->assertNotSame([], $purger->failures(), "L'échec doit être rapporté, pas avalé.");
    }

    /**
     * Et le verdict doit LEVER, en disant que la reprise est possible.
     *
     * Un échec silencieux laisserait croire à une purge réussie — c'est ce défaut-là qui avait
     * produit « 28 modules à jour » sur des modules en écart, dans un autre dossier.
     *
     * @throws SeedException
     */
    public function testTheVerdictSaysTheFailureIsRetryable(): void
    {
        $this->traceANote();

        $purger = new SeedPurger(self::MARKER, static fn(): string => 'refus simulé');
        $purger->purge();

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/conservées/');

        $purger->assertClean();
    }

    /**
     * LE PENDANT : quand la cible part, sa trace part avec elle.
     *
     * Sans cette assertion, le correctif pourrait se réduire à « ne jamais effacer les traces », ce
     * qui rendrait toute purge inachevable et serait un défaut pire que celui d'origine.
     *
     * @throws SeedException
     */
    public function testASucceededTargetLosesItsReference(): void
    {
        $this->traceANote();

        // Action rendant null : la cible est réputée partie. On ne purge pas réellement la note,
        // ce qui n'a aucune importance ici — c'est la trace qu'on observe, et tearDown() nettoie.
        $purger = new SeedPurger(self::MARKER, static fn(): ?string => null);

        $this->assertSame(1, $purger->count());
        $this->assertSame(1, $purger->purge(), 'La suppression doit être comptée.');
        $this->assertSame(0, $purger->count(), 'La trace doit partir avec sa cible.');
        $this->assertSame([], $purger->failures());
    }

    /**
     * UNE EXCEPTION EST UN ÉCHEC, pas une disparition.
     *
     * C'est la forme qu'a prise la panne de production : `ErrorException` sur une colonne absente.
     * Si elle était comptée comme « cible déjà supprimée », la trace partirait et on retomberait
     * exactement dans le défaut.
     *
     * @throws SeedException
     */
    public function testAThrowingTargetAlsoKeepsItsReference(): void
    {
        $this->traceANote();

        $purger = new SeedPurger(
            self::MARKER,
            static function (): ?string {
                throw new SeedException("Unknown column 'note.object_id' in 'WHERE'");
            }
        );

        $purger->purge();

        $this->assertSame(1, $purger->count(), 'Une exception ne doit pas faire disparaître la trace.');
        $this->assertNotSame([], $purger->failures());
    }

    /**
     * Écrit une note et sa trace, et rend la trace.
     *
     * UNE NOTE PARCE QU'ELLE EST LA CIBLE LA MOINS COÛTEUSE À FABRIQUER, et elle s'accroche à
     * l'établissement courant.
     *
     * ⚠ PAS À UN IDENTIFIANT INVENTÉ : `CNote::object_id` est un « ref notNull » que `check()`
     * VALIDE — un objet inexistant fait refuser l'écriture (« Objet référencé de type 'CNote'
     * introuvable »). Ma première version pointait CNote-1 et les quatre tests échouaient à la
     * fabrication, avant même d'éprouver quoi que ce soit.
     *
     * L'établissement n'est jamais supprimé par ce test : les actions injectées ne purgent rien
     * réellement, et `tearDown()` ne retire que les notes qu'il a créées.
     *
     * @throws SeedException
     */
    private function traceANote(): CFixturesReference
    {
        $group = CGroups::loadCurrent();

        if (!$group->_id) {
            $this->markTestSkipped("Aucun établissement courant : la cible du test n'est pas fabricable.");
        }

        $note               = new CNote();
        $note->object_class = $group->_class;
        $note->object_id    = $group->_id;
        $note->libelle      = 'Note du test de purge';
        $note->date         = CMbDT::dateTime();
        $note->degre        = 'low';
        $note->public       = '1';

        $msg = $note->store();

        if ($msg) {
            throw new SeedException("Note du test non écrite : {$msg}");
        }

        $this->notes[] = $note;

        $reference                 = new CFixturesReference();
        $reference->fixtures_class = self::MARKER;
        $reference->object_class   = $note->_class;
        $reference->object_id      = $note->_id;

        $msg = $reference->store();

        if ($msg) {
            throw new SeedException("Trace du test non écrite : {$msg}");
        }

        return $reference;
    }

    /**
     * ⚠ LE SCÉNARIO RÉEL, que mes trois premiers tests NE REPRODUISAIENT PAS.
     *
     * Ils injectaient une action qui échoue SANS toucher à la trace, et concluaient « la trace
     * survit ». Mais `CStoredObject::purge()` supprime les RÉFÉRENCES ARRIÈRE avant d'appeler
     * `delete()`, et la trace EST une référence arrière de sa cible : dans la vraie panne, elle est
     * déjà effacée quand l'échec se produit. S'abstenir de la supprimer ne la ramenait donc pas.
     *
     * Constaté en production le 2026-10-05 : « 3 objet(s) non supprimé(s) » ET « 0 référence(s)
     * restante(s) », puis « aucun jeu de données antérieur à purger » sur une base qui portait
     * encore des dizaines d'objets du semis.
     *
     * Ce test imite l'ORM : l'action injectée EFFACE la trace, puis échoue. C'est la seule forme
     * qui éprouve le rétablissement.
     *
     * @throws SeedException
     */
    public function testAFailureThatAlreadyDeletedTheTraceGetsItRestored(): void
    {
        $reference = $this->traceANote();
        $kept_id   = $reference->_id;

        $purger = new SeedPurger(
            self::MARKER,
            static function () use ($kept_id): string {
                // Exactement ce que fait purge() : il efface les références arrière — dont la
                // trace — AVANT de buter sur delete().
                $doomed      = new CFixturesReference();
                $doomed->load($kept_id);
                $doomed->purge();

                return 'refus simulé après effacement de la trace';
            }
        );

        $this->assertSame(1, $purger->count(), 'La trace doit exister avant la purge.');
        $this->assertSame(0, $purger->purge(), 'Aucun objet ne doit être compté comme supprimé.');

        $this->assertSame(
            1,
            $purger->count(),
            'LA TRACE DOIT ÊTRE RÉTABLIE : sans elle, l\'objet survivant devient introuvable et'
            . ' aucune commande ne peut plus le reprendre. C\'est le défaut constaté en production.'
        );

        $this->assertNotSame([], $purger->failures());
    }

    /**
     * Et le rétablissement ne doit pas créer de DOUBLON quand la trace est encore là.
     *
     * Deux traces pour un objet le feraient traiter deux fois par la purge suivante, et le second
     * passage rapporterait un échec sur un objet déjà parti — un faux positif dans le verdict.
     *
     * @throws SeedException
     */
    public function testRestoringDoesNotDuplicateAnExistingTrace(): void
    {
        $this->traceANote();

        $purger = new SeedPurger(self::MARKER, static fn(): string => 'refus sans toucher à la trace');
        $purger->purge();

        $this->assertSame(1, $purger->count(), 'Une seule trace, pas deux.');
    }
}
