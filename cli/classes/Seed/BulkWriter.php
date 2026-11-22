<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Exception;
use Hospilibre\System\CSeedRun;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Throwable;

/**
 * Écriture par lots des entités de masse, ORM pour le reste.
 *
 * Le référentiel — établissement, fonctions, praticiens, services, chambres, lits, bloc, salles,
 * plages — continue de passer par l'ORM : il est peu volumineux, fortement contraint, et c'est là
 * que les règles métier comptent. Seuls les patients, séjours, affectations et interventions
 * passent par `insertMulti()`, parce qu'à 100 000 objets les 5 à 20 ms par objet de `store()`
 * représentent une demi-heure.
 *
 * IDENTIFIANTS : `insertMulti()` ne rend pas les identifiants insérés, or les enfants en ont
 * besoin (`affectation.sejour_id`). On n'exploite PAS la contiguïté des `AUTO_INCREMENT` — elle
 * dépend de `innodb_autoinc_lock_mode` et casse silencieusement. À la place, une plage est
 * réservée par table (`MAX(clé) + 1`) et les clés primaires sont attribuées explicitement : les
 * identifiants sont donc connus AVANT insertion, ce qui rend le graphe constructible et le
 * résultat déterministe.
 *
 * PRÉCONDITION : aucune écriture concurrente sur l'instance pendant l'exécution. La réservation
 * de plages l'exige. Acceptable pour un range ou une démonstration, à ne pas lancer sur une
 * instance en service.
 *
 * CE QUE CE CHEMIN NE FAIT PAS, et il faut le savoir : ni `check()`, ni les déclencheurs, ni les
 * journaux. Les champs calculés le sont tout de même, en appelant `updateFormFields()` et
 * `updatePlainFields()` comme le fait `store()` — sans quoi les colonnes dérivées (soundex d'un
 * patient, bornes dérivées d'un séjour) resteraient vides et les écrans de recherche deviendraient
 * inutilisables. Un test d'équivalence compare colonne par colonne ce que produisent les deux
 * chemins.
 */
final class BulkWriter extends AbstractSeedWriter
{
    /**
     * Classes écrites par lots. L'ordre est celui des dépendances : il sert à vider les tampons
     * sans qu'un enfant précède son parent, et — inversé — à purger par plages.
     *
     * LA LISTE EST COURTE, ET C'EST LE TEST D'ÉQUIVALENCE QUI L'A RÉDUITE. Le design prévoyait d'y
     * mettre patients, séjours, affectations et interventions ; la comparaison colonne par colonne
     * avec le chemin ORM a montré que `store()` remplit, pour chacun de ces modèles, des colonnes
     * qu'un chemin générique ne peut pas reproduire :
     *
     *  - CPatient     : `creator_id`, `creation_date`, `status` (PROV) et `source_identite_id` —
     *                   cette dernière désignant une source d'identité créée au passage. Un patient
     *                   sans source d'identité a l'air correct et n'est pas exploitable.
     *  - CSejour      : `recuse` (dérivé d'une configuration d'établissement) et `type_pec`.
     *  - COperation   : `duree_preop`.
     *
     * Ces colonnes ne sont pas décoratives — un indicateur de cotation, un type de prise en charge,
     * une durée pré-opératoire. Les remplacer par NULL produirait un jeu de données qui passe
     * l'œil et trahit le premier écran qui s'en sert. Le garde-fou du §7 du design existait
     * justement pour que ce mode ne soit pas « au jugé » : il a fait son travail, et le périmètre
     * s'est réduit à ce qu'il pouvait garantir.
     *
     * Conséquence à assumer : l'accélération est PARTIELLE. Les affectations représentent environ
     * un tiers des objets d'un profil `xl`.
     */
    public const MASS_CLASSES = ['CAffectation'];

    /** Nombre de lignes par instruction INSERT. */
    private const BATCH = 200;

    /** Vider le tampon d'une table au-delà de ce nombre de lignes retenues. */
    private const BUFFER_LIMIT = 2000;

    /** @var OrmWriter Chemin de l'ORM, pour tout ce qui n'est pas de masse */
    private $orm;

    /** @var string */
    private $fixtures_class;

    /** @var CSQLDataSource|null */
    private $ds;

    /** @var array<string, array<int, array<string, string>>> lignes en attente, par table */
    private $buffers = [];

    /** @var array<string, array{key: string, class: string, from: int, next: int}> par table */
    private $ranges = [];

    /** @var array<string, string> table => classe, pour l'ordre de vidage */
    private $tables = [];

    /** @var string|null Profil du run, pour que l'enregistrement dise quoi reproduire */
    private $profile;

    /** @var int|null */
    private $seed;

    /** @var string|null */
    private $reference_date;

    public function __construct(string $fixtures_class)
    {
        $this->fixtures_class = $fixtures_class;
        $this->orm            = new OrmWriter($fixtures_class);
    }

    public function name(): string
    {
        return SeedProfile::WRITER_BULK;
    }

    /**
     * Les entités de masse sont-elles tracées une à une ? Non, et c'est le principe.
     */
    public function tracesMassEntities(): bool
    {
        return false;
    }

    /**
     * @throws SeedException
     */
    protected function doWrite(CStoredObject $object, ?string $tag): void
    {
        if (!in_array($object->_class, self::MASS_CLASSES, true)) {
            // Le référentiel garde l'ORM : c'est ce qui rend les deux modes cohérents, la
            // traçabilité suivant le CHEMIN d'écriture et non la classe d'objet.
            $this->orm->write($object, $tag);

            return;
        }

        if ($tag !== null) {
            throw new SeedException(
                "L'objet {$object->_class} est écrit par lots et ne peut pas porter l'étiquette"
                . " '{$tag}' : une étiquette suppose une trace individuelle, que ce chemin ne"
                . ' produit pas. Les objets de masse ne sont pas censés être étiquetés.'
            );
        }

        $table = $object->_spec->table;
        $key   = $object->_spec->key;

        $object->_id = $this->reserve($table, $key, $object->_class);

        $this->tables[$table]    = $object->_class;
        $this->buffers[$table][] = $this->row($object, $key);

        if (count($this->buffers[$table]) >= self::BUFFER_LIMIT) {
            $this->flushTable($table);
        }
    }

    /**
     * Vide les tampons, dans l'ordre des dépendances, puis enregistre le run.
     *
     * @throws SeedException
     */
    public function flush(): void
    {
        foreach (self::MASS_CLASSES as $class) {
            foreach ($this->tables as $table => $_class) {
                if ($_class === $class) {
                    $this->flushTable($table);
                }
            }
        }

        $this->recordRun();
    }

    /**
     * Plages réservées, telles qu'elles seront inscrites dans le run.
     *
     * @return array<string, array{key: string, class: string, from: int, to: int}>
     */
    public function reservedRanges(): array
    {
        $ranges = [];

        foreach ($this->ranges as $table => $range) {
            if ($range['next'] <= $range['from']) {
                continue;
            }

            $ranges[$table] = [
                'key'   => $range['key'],
                'class' => $range['class'],
                'from'  => $range['from'],
                'to'    => $range['next'] - 1,
            ];
        }

        return $ranges;
    }

    /**
     * Identifiant suivant de la plage réservée pour cette table.
     *
     * @throws SeedException
     */
    private function reserve(string $table, string $key, string $class): int
    {
        if (!isset($this->ranges[$table])) {
            $max = (int)$this->ds()->loadResult("SELECT MAX(`{$key}`) FROM `{$table}`");

            $this->ranges[$table] = [
                'key'   => $key,
                'class' => $class,
                'from'  => $max + 1,
                'next'  => $max + 1,
            ];
        }

        return $this->ranges[$table]['next']++;
    }

    /**
     * Ligne à insérer, construite comme le fait l'ORM.
     *
     * Même sémantique que CSQLDataSource::insertObject() : on ignore null, les tableaux, les
     * objets, les champs de formulaire, et les chaînes VIDES — sauf la clé. Écrire NULL là où
     * l'ORM omet la colonne priverait la table de ses valeurs par défaut, ce qui est précisément
     * le genre de divergence que le test d'équivalence traque.
     *
     * @return array<string, string>
     * @throws SeedException
     */
    private function row(CStoredObject $object, string $key): array
    {
        try {
            // Mêmes calculs que store(), pour que les colonnes dérivées ne restent pas vides.
            $object->updateFormFields();
            $object->updatePlainFields();
        } catch (Throwable $t) {
            throw new SeedException(
                "Préparation de {$object->_class} impossible : " . $t->getMessage(),
                0,
                $t
            );
        }

        $row = [];
        foreach ($object->getPlainFields() as $field => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }

            if ($field[0] === '_') {
                continue;
            }

            if ($value === '' && $field !== $key) {
                continue;
            }

            $row[$field] = (string)$value;
        }

        return $row;
    }

    /**
     * @throws SeedException
     */
    private function flushTable(string $table): void
    {
        if (empty($this->buffers[$table])) {
            return;
        }

        // insertMulti() prend les colonnes de la PREMIÈRE ligne et les applique à toutes : il faut
        // donc regrouper par jeu de colonnes identique. Ce regroupement est ce qui permet de
        // reproduire l'omission des champs vides de l'ORM au lieu d'écrire NULL partout.
        $groups = [];
        foreach ($this->buffers[$table] as $row) {
            $groups[implode(',', array_keys($row))][] = $row;
        }

        foreach ($groups as $rows) {
            try {
                $this->ds()->insertMulti($table, $rows, self::BATCH);
            } catch (Exception $e) {
                throw new SeedException(
                    "Insertion par lots dans `{$table}` impossible : " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        $this->buffers[$table] = [];
    }

    /**
     * Enregistre le run : c'est la seule prise que la purge aura sur les entités de masse.
     *
     * @throws SeedException
     */
    private function recordRun(): void
    {
        $ranges = $this->reservedRanges();

        if ($ranges === []) {
            return;
        }

        $run                 = new CSeedRun();
        $run->fixtures_class = $this->fixtures_class;
        $run->profile        = $this->profile ?? 'inconnu';
        $run->seed           = $this->seed ?? 0;
        $run->reference_date = $this->reference_date ?? CMbDT::dateTime();
        $run->creation_date  = CMbDT::dateTime();
        $run->encodeRanges($ranges);

        try {
            $msg = $run->store();
        } catch (Throwable $t) {
            throw new SeedException('Enregistrement du run de seed impossible : ' . $t->getMessage(), 0, $t);
        }

        if ($msg) {
            throw new SeedException("Enregistrement du run de seed refusé : {$msg}");
        }
    }

    /**
     * Contexte du run, pour que l'enregistrement porte de quoi le rejouer.
     *
     * Renseigné par le seeder : sans profil, graine et instant de référence, la ligne de run
     * indiquerait quoi supprimer mais pas quoi reproduire.
     */
    public function describeRun(string $profile, int $seed, string $reference_date): void
    {
        $this->profile        = $profile;
        $this->seed           = $seed;
        $this->reference_date = $reference_date;
    }

    /**
     * @throws SeedException
     */
    private function ds(): CSQLDataSource
    {
        if ($this->ds === null) {
            $ds = CSQLDataSource::get('std');

            if (!$ds) {
                throw new SeedException("Source de données 'std' indisponible");
            }

            $this->ds = $ds;
        }

        return $this->ds;
    }
}
