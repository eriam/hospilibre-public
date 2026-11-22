<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\System\CFixturesReference;
use Hospilibre\System\CSeedRun;
use Ox\Core\Cache;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Throwable;

/**
 * Défait un jeu de données de seed, et VÉRIFIE qu'il est bien défait.
 *
 * La vérification est le point important. Une purge qui ignore le message rendu par
 * `CStoredObject::purge()` — ou qui attrape les exceptions sans les rapporter — laisse des données
 * derrière elle sans rien dire, et elles s'accumulent d'un run à l'autre jusqu'à ce qu'une
 * contrainte d'unicité fasse échouer quelque chose de sans rapport. C'est exactement ce qui s'est
 * produit pendant l'écriture de ce seed : vingt-huit établissements fantômes avaient survécu à
 * autant de purges « réussies », parce qu'une colonne manquante faisait échouer
 * `CGroups::purge()` en silence.
 *
 * Un message « noObjectToDelete » n'est PAS un échec : il signale un enfant déjà supprimé par
 * cascade en purgeant son parent, ce qui est le cas normal.
 *
 * Les exceptions sont attrapées mais RETENUES : le gestionnaire d'erreurs promeut les
 * avertissements PHP en exceptions, et une requête de référence arrière bancale ne doit pas
 * interrompre la purge du reste — elle doit être rapportée.
 */
final class SeedPurger
{
    /** Références chargées par page. Voir purgeReferences(). */
    private const PAGE = 500;

    /** @var string */
    private $fixtures_class;

    /** @var int */
    private $purged = 0;

    /** @var array<string, string> */
    private $failures = [];

    /** @var int Lignes supprimées par plages d'identifiants */
    private $bulk_deleted = 0;

    /**
     * @var callable|null Action de suppression d'une cible, injectable pour les tests.
     *
     * POURQUOI CETTE COUTURE EXISTE, ET CE QU'ELLE A COÛTÉ DE NE PAS EXISTER. La branche d'échec de
     * cette classe — « la cible refuse d'être supprimée » — était intestable : il faut pour
     * l'atteindre un objet dont `purge()` échoue, et rien ne permettait d'en fabriquer un. J'ai
     * essayé deux reproductions sur un vrai jeu de données, les deux ont RÉUSSI leur purge ; j'ai
     * donc livré le correctif de cette branche en disant qu'elle n'était pas vérifiée.
     *
     * Quelques heures plus tard, elle s'est exercée en production : une purge a buté sur la table
     * `note` substituée, et 8 324 identifiants de patients avaient déjà été supprimés. Le correctif
     * a tenu — les traces des objets en échec ont bien survécu, ce qui a rendu la reprise possible —
     * mais c'est la panne qui l'a prouvé, pas un test.
     *
     * UN SEUL POINT D'INJECTION, et c'est délibéré : rendre la classe entière substituable aurait
     * ouvert bien plus que ce qu'il faut. Seule l'action de suppression se remplace ; le
     * chargement des références, la pagination, l'ordre et le verdict restent exercés tels quels.
     */
    private $purge_target;

    /**
     * @param callable|null $purge_target fn(CStoredObject): ?string — message d'échec, ou null
     */
    public function __construct(string $fixtures_class, ?callable $purge_target = null)
    {
        $this->fixtures_class = $fixtures_class;
        $this->purge_target   = $purge_target;
    }

    /**
     * Supprime la cible et rend son message d'échec, ou null si elle est partie.
     */
    private function purgeTarget(CStoredObject $target): ?string
    {
        if ($this->purge_target !== null) {
            $msg = ($this->purge_target)($target);

            return $msg === null ? null : (string)$msg;
        }

        $msg = $target->purge();

        return ($msg === null || $msg === '') ? null : (string)$msg;
    }

    /**
     * Nombre de références tracées pour ce jeu de données.
     *
     * @throws Throwable
     */
    public function count(): int
    {
        $reference = new CFixturesReference();

        return (int)$reference->countList($this->where($reference));
    }

    /**
     * Purge le jeu de données et rend le nombre d'objets effectivement supprimés.
     *
     * L'ordre est décroissant d'identifiant de référence : les enfants d'abord, sans quoi la
     * suppression d'un parent buterait sur ses dépendants.
     *
     * @throws Throwable
     */
    public function purge(): int
    {
        // Refus explicite plutôt que purge partielle : les entités de masse d'un run écrit par lots
        // ne sont pas tracées une à une, donc cette voie les laisserait orphelines — un
        // établissement supprimé et dix mille séjours qui le désignent encore.
        if ($this->hasBulkRun()) {
            throw new SeedException(
                "Ce jeu de données comporte un run écrit par lots, que cette purge ne sait pas"
                . " défaire (les entités de masse n'y sont pas tracées objet par objet)."
                . ' Employer « ox-seed:hospi --purge », qui supprime aussi les plages'
                . " d'identifiants réservées."
            );
        }

        return $this->purgeReferences();
    }

    /**
     * Purge complète : plages d'identifiants des runs par lots, puis références de l'ORM.
     *
     * L'ordre importe. Les entités de masse partent d'abord, sinon la suppression du référentiel
     * laisserait des séjours désignant un établissement disparu — et un jeu de données à moitié
     * défait est plus nuisible qu'un jeu de données intact.
     *
     * @throws Throwable
     */
    public function purgeAll(): int
    {
        $this->bulk_deleted = 0;

        foreach ($this->bulkRuns() as $run) {
            $this->purgeRanges($run);
            $run->purge();
        }

        return $this->purgeReferences();
    }

    /** Lignes supprimées par plages d'identifiants lors du dernier purgeAll(). */
    public function bulkDeleted(): int
    {
        return $this->bulk_deleted;
    }

    /**
     * @return CSeedRun[]
     * @throws Throwable
     */
    public function bulkRuns(): array
    {
        $run = new CSeedRun();

        return $run->loadList(
            ['fixtures_class' => $run->getDS()->prepareLike($this->fixtures_class)],
            'seed_run_id DESC'
        );
    }

    /**
     * @throws Throwable
     */
    public function hasBulkRun(): bool
    {
        return $this->bulkRuns() !== [];
    }

    /**
     * Supprime les entités de masse d'un run par leurs plages d'identifiants.
     *
     * Ordre INVERSE des dépendances : les enfants avant les parents. À cette échelle, un
     * « DELETE ... WHERE id BETWEEN » par table est la seule voie praticable — la purge objet par
     * objet demanderait des heures pour 100 000 objets.
     *
     * @throws Throwable
     */
    private function purgeRanges(CSeedRun $run): void
    {
        $ranges = $run->decodeRanges();
        $ds     = CSQLDataSource::get('std');

        foreach (array_reverse(BulkWriter::MASS_CLASSES) as $class) {
            foreach ($ranges as $table => $range) {
                if (!isset($range['key'], $range['from'], $range['to'])) {
                    continue;
                }

                if (($range['class'] ?? null) !== $class) {
                    continue;
                }

                $query = $ds->prepare(
                    "DELETE FROM `{$table}` WHERE `{$range['key']}` BETWEEN %1 AND %2",
                    $range['from'],
                    $range['to']
                );

                $ds->exec($query);
                $this->bulk_deleted += (int)$ds->affectedRows();
            }
        }
    }

    /**
     * Purge les objets tracés, PAR PAGES.
     *
     * Par pages, et non en une fois. `loadList()` sans limite hydrate toutes les références puis
     * chaque cible avec ses références arrière : à quatorze mille objets tracés, le processus
     * dépassait la limite de 1 Go et mourait sur un « Allowed memory size exhausted » au milieu de
     * la purge — laissant un jeu de données à moitié défait, c'est-à-dire le pire des deux états.
     *
     * Chaque page repart de la PREMIÈRE ligne restante, puisque la précédente vient d'être
     * supprimée. Pas de décalage à gérer, et rien ne peut être sauté.
     *
     * L'ordre reste décroissant d'identifiant de référence : les enfants d'abord, sans quoi la
     * suppression d'un parent buterait sur ses dépendants.
     *
     * @throws Throwable
     */
    private function purgeReferences(): int
    {
        $this->purged   = 0;
        $this->failures = [];

        $reference = new CFixturesReference();

        // UN CURSEUR, ET NON UN RECHARGEMENT DEPUIS LE DÉBUT. L'ancienne boucle rechargeait la
        // première page à chaque tour, en comptant sur la disparition des références pour avancer.
        // C'était tenable tant que TOUTE référence traitée partait — y compris celle d'un objet qui
        // avait refusé d'être supprimé —, mais c'est précisément ce qu'on corrige ici : une
        // référence qui survit à son échec bloquerait alors la page et empêcherait la purge des
        // suivantes. Un curseur descendant avance toujours, visite chaque référence exactement une
        // fois, et conserve l'ordre inverse de création dont les cascades dépendent.
        $cursor = null;

        while (true) {
            $where = $this->where($reference);

            if ($cursor !== null) {
                $where['fixtures_reference_id'] = '< ' . (int)$cursor;
            }

            $references = $reference->loadList($where, 'fixtures_reference_id DESC', self::PAGE);

            if ($references === []) {
                break;
            }

            foreach ($references as $_reference) {
                $cursor = (int)$_reference->fixtures_reference_id;

                $this->purgeOne($_reference);
            }

            // `purge()` charge la cible et ses références arrière, et `CStoredObject::load()` les
            // enregistre dans le cache de processus, que rien n'expulse. Paginer sans vider ne
            // repousserait que le moment de la panne.
            Cache::flushInner();
        }

        return $this->purged;
    }

    /**
     * @throws Throwable
     */
    private function purgeOne(CFixturesReference $reference): void
    {
        $label = $reference->object_class . '-' . $reference->object_id;

        try {
            $target = $reference->loadTarget();
        } catch (Throwable $t) {
            // Cible déjà supprimée par cascade : cas normal.
            $this->purgeReference($reference);

            return;
        }

        try {
            $msg = $this->purgeTarget($target);

            if ($msg && strpos(strip_tags((string)$msg), 'noObjectToDelete') === false) {
                $this->failures[$label] = strip_tags((string)$msg);
                $this->restoreReference($reference);

                return;
            }

            $this->purged++;
        } catch (Throwable $t) {
            $this->failures[$label] = get_class($t) . ' : ' . $t->getMessage();
            $this->restoreReference($reference);

            return;
        }

        $this->purgeReference($reference);
    }

    /**
     * @return array<string, string> objet => raison de l'échec
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Lève si quelque chose a survécu à la purge.
     *
     * @throws SeedException
     * @throws Throwable
     */
    public function assertClean(): void
    {
        $remaining  = $this->count();
        $bulk_runs  = count($this->bulkRuns());

        if ($this->failures === [] && $remaining === 0 && $bulk_runs === 0) {
            return;
        }

        $detail = [];
        foreach (array_slice($this->failures, 0, 10, true) as $label => $reason) {
            $detail[] = $label . ' : ' . preg_replace('/\s+/', ' ', $reason);
        }

        throw new SeedException(
            "La purge du jeu de données '{$this->fixtures_class}' a échoué : {$remaining} référence(s)"
            . " restante(s), {$bulk_runs} run(s) par lots restant(s), "
            . count($this->failures) . ' objet(s) non supprimé(s).'
            . ($detail === [] ? '' : "\n  " . implode("\n  ", $detail))
            // CE QUE L'EXPLOITANT PEUT FAIRE, et il faut le dire : les traces des objets qui ont
            // résisté sont CONSERVÉES, donc la purge est rejouable. Avant la correction, elles
            // partaient avec l'échec et les survivants devenaient introuvables — le message
            // annonçait alors un échec sans issue, puisque aucune commande ne pouvait plus les
            // atteindre.
            . "\n  Les traces des objets en échec sont conservées : lever la cause, puis rejouer"
            . ' « ox-seed:hospi --purge » reprend ce qui a résisté.'
        );
    }

    /**
     * @return array<string, string>
     */
    private function where(CFixturesReference $reference): array
    {
        return ['fixtures_class' => $reference->getDS()->prepareLike($this->fixtures_class)];
    }

    /**
     * Remet la trace en place quand la cible a résisté — car l'ORM l'a déjà effacée.
     *
     * ⚠ POURQUOI « NE PAS SUPPRIMER LA TRACE » NE SUFFISAIT PAS, et c'est la correction d'une
     * affirmation que j'ai faite à tort. `CStoredObject::purge()` supprime les RÉFÉRENCES ARRIÈRE
     * AVANT d'appeler `delete()` (core/classes/CStoredObject.php:4298 puis :4319), et
     * `CFixturesReference::object_id` déclare « back|fixtures_references » : la trace EST une
     * référence arrière de sa cible. Quand `delete()` échoue, la trace est donc DÉJÀ partie, et
     * s'abstenir de la supprimer ne la ramène pas.
     *
     * Constaté le 2026-10-05 sur un vrai échec : la purge a rapporté « 3 objet(s) non supprimé(s) »
     * ET « 0 référence(s) restante(s) » — les deux à la fois, ce qui dit tout. La purge suivante a
     * alors annoncé « aucun jeu de données antérieur à purger » sur une base portant encore des
     * dizaines de comptes du semis.
     *
     * ⚠ ET MES TESTS NE L'ONT PAS VU, parce qu'ils injectent une action qui échoue SANS toucher aux
     * références arrière. Ils éprouvaient une simulation de la panne, pas la panne. C'est la forme
     * d'erreur la plus coûteuse de ce dossier : un contrôle qui réussit sur un scénario qui ne se
     * produit pas.
     *
     * LA RÉÉCRITURE EST UN PIS-ALLER ASSUMÉ. La réponse propre serait transactionnelle, mais le
     * socle n'expose aucune transaction (vérifié : ni CSQLDataSource ni CPDOMySQLDataSource). Une
     * trace réécrite porte un nouvel identifiant et une nouvelle place dans l'ordre de purge — sans
     * conséquence, puisque l'ordre ne sert qu'aux cascades et que cet objet-là n'est pas parti.
     */
    private function restoreReference(CFixturesReference $reference): void
    {
        try {
            $fresh                 = new CFixturesReference();
            $fresh->fixtures_class = $reference->fixtures_class;
            $fresh->object_class   = $reference->object_class;
            $fresh->object_id      = $reference->object_id;
            $fresh->tag            = $reference->tag;

            // Déjà là : l'échec est survenu avant que l'ORM n'atteigne la trace. Rien à faire, et
            // surtout pas un doublon — la purge suivante le traiterait deux fois.
            if ($fresh->loadMatchingObject()) {
                return;
            }

            $fresh->store();
        } catch (Throwable $t) {
            // Une trace non rétablie rend l'objet introuvable, ce qui est le défaut qu'on corrige —
            // mais échouer ici ne doit pas interrompre la purge du reste. assertClean() rapportera
            // l'objet en échec de toute façon.
        }
    }

    /**
     * Efface la trace, UNIQUEMENT quand la cible est réellement partie.
     *
     * ⚠ C'EST LA CORRECTION D'UN DÉFAUT QUI RENDAIT UNE INSTANCE IRRÉPARABLE PAR COMMANDE.
     * `purgeOne()` appelait cette méthode INCONDITIONNELLEMENT, y compris lorsque la suppression de
     * la cible venait d'échouer. L'objet survivait donc sans sa trace, et comme la purge ne connaît
     * les objets du semis QUE par leurs traces, il devenait introuvable : « aucun jeu de données
     * antérieur à purger » sur une base qui en portait encore, tandis que le semis suivant refusait
     * de démarrer pour collision de noms d'utilisateur. Constaté le 2026-10-04 — il a fallu
     * supprimer cinquante-six comptes en SQL à la main pour rendre l'instance utilisable.
     *
     * La trace est désormais ce qui garantit qu'un échec reste RATTRAPABLE : un simple
     * « ox-seed:hospi --purge » rejoué reprend ce qui a résisté, une fois la cause levée.
     */
    private function purgeReference(CFixturesReference $reference): void
    {
        try {
            $reference->purge();
        } catch (Throwable $t) {
            // La ligne de référence a pu partir en cascade avec sa cible : sans importance.
        }
    }
}
