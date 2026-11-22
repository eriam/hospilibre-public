<?php

/**
 * @package Hospilibre\System
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\System;

use Ox\Core\CMbObjectSpec;
use Ox\Core\CStoredObject;

/**
 * Trace d'un run de seed écrit par lots.
 *
 * POURQUOI CETTE TABLE EXISTE : le chemin d'écriture par lots ne trace pas les objets de masse
 * un par un dans CFixturesReference. À 100 000 objets, cette traçabilité ligne à ligne coûterait
 * autant que les données elles-mêmes, et la purge objet par objet serait impraticable. Le run est
 * donc identifié par UN enregistrement portant les plages d'identifiants réservées par table, et
 * la purge se fait par `DELETE ... WHERE id BETWEEN`, table par table, dans l'ordre inverse des
 * dépendances.
 *
 * Conséquence assumée : un run écrit par lots ne peut PAS être défait par
 * `ox-fixtures:load --only-purge`, qui ne connaît que CFixturesReference. La purge doit passer par
 * `ox-seed:hospi --purge`, et le chemin fixtures refuse explicitement plutôt que de laisser des
 * orphelins.
 *
 * Le référentiel (établissement, services, lits, bloc), lui, reste écrit par l'ORM et tracé
 * normalement : il est peu volumineux et c'est là que les règles métier comptent.
 */
class CSeedRun extends CStoredObject
{
    /** @var int Clé primaire */
    public $seed_run_id;

    /** @var string Classe émettrice, la même que celle portée par les CFixturesReference du run */
    public $fixtures_class;

    /** @var string Nom du profil d'échelle */
    public $profile;

    /** @var int Graine du générateur */
    public $seed;

    /** @var string Instant de référence du jeu de données */
    public $reference_date;

    /**
     * @var string Plages d'identifiants réservées, en JSON : {"table": {"key": "…", "from": n,
     *             "to": n}, …}. Un JSON plutôt qu'une table fille : ces plages n'ont de sens
     *             qu'ensemble, ne sont jamais interrogées champ par champ, et leur nombre est
     *             celui des tables de masse — quatre.
     */
    public $ranges;

    /** @var string */
    public $creation_date;

    /**
     * @inheritdoc
     */
    public function getSpec(): CMbObjectSpec
    {
        $spec = parent::getSpec();

        $spec->table = 'seed_run';
        $spec->key   = 'seed_run_id';

        return $spec;
    }

    /**
     * @inheritdoc
     */
    public function getProps(): array
    {
        $props = parent::getProps();

        $props['fixtures_class'] = 'str notNull maxLength|255';
        $props['profile']        = 'str notNull maxLength|32';
        $props['seed']           = 'num notNull';
        $props['reference_date'] = 'dateTime notNull';
        $props['ranges']         = 'text notNull';
        $props['creation_date']  = 'dateTime notNull';

        return $props;
    }

    /**
     * Plages décodées.
     *
     * @return array<string, array{key: string, from: int, to: int}>
     */
    public function decodeRanges(): array
    {
        $decoded = json_decode((string)$this->ranges, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array{key: string, from: int, to: int}> $ranges
     */
    public function encodeRanges(array $ranges): void
    {
        $this->ranges = json_encode($ranges, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @inheritdoc
     */
    public function __toString(): string
    {
        return "seed {$this->profile}/{$this->seed} du {$this->creation_date}";
    }
}
