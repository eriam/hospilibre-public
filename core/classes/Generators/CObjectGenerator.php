<?php
/**
 * @package Hospilibre\Populate
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réimplémentation GPL (clean-room, reconstruite à partir du contrat observable :
 * ses ~60 sous-classes `*Generator` et le test CObjectGeneratorTest, sans copier
 * la source OXOL retirée). Base abstraite des générateurs d'objets de peuplement
 * (fixtures de démonstration/test).
 */

namespace Ox\Core\Generators;

use Ox\Core\CAppUI;
use Ox\Core\CMbObject;
use Ox\Core\CSQLDataSource;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\System\CFirstNameAssociativeSex;

/**
 * Base abstraite d'un générateur d'objet de peuplement.
 *
 * Contrat attendu par les sous-classes et le test :
 *   - statiques : $mb_class (FQCN du modèle produit), $dependances (modèles à
 *     générer au préalable), $ds (datasource, défaut "std")
 *   - `$object` est pré-instancié (new static::$mb_class) avant generate()
 *   - `generate()` : la sous-classe renseigne + store() $this->object, le retourne
 *   - helpers : getMaxCount(), getRandomObject(), getRandomNames(), getRandomCP(),
 *     trace()
 */
abstract class CObjectGenerator
{
    /** Trace : un objet existant a été réutilisé (chargé) plutôt que créé. */
    public const TRACE_LOAD = 'load';

    /** Trace : un nouvel objet a été créé et stocké. */
    public const TRACE_STORE = 'store';

    /** Nombre d'objets au-delà duquel on réutilise l'existant au lieu d'en créer. */
    protected const DEFAULT_MAX_COUNT = 10;

    /** @var string FQCN du modèle CMbObject produit (surchargé par la sous-classe). */
    public static $mb_class;

    /** @var string[] Modèles dont ce générateur dépend (générés au préalable). */
    public static $dependances = [];

    /** @var string Nom de la datasource. */
    public static $ds = 'std';

    /** @var CMbObject L'objet généré. */
    protected $object;

    /** @var bool Forcer la création d'un nouvel objet (pas de réutilisation). */
    protected $force = false;

    /** @var int|null Type éventuel, interprété par la sous-classe. */
    protected $type;

    /** @var int|null Établissement de rattachement. */
    protected $group_id;

    /** @var string[] Journal des traces (TRACE_*). */
    protected $trace = [];

    public function __construct()
    {
        // Établissement courant par défaut (les générateurs rattachent souvent
        // leur objet au groupe en cours).
        $group          = CGroups::loadCurrent();
        $this->group_id = ($group && $group->_id) ? $group->_id : (CAppUI::$instance->user_group ?? null);

        $class = static::$mb_class;
        if ($class) {
            $this->object = new $class();

            // Pré-rattache l'objet à l'établissement courant s'il porte un
            // champ group_id notNull : les generate() des sous-classes ne
            // renseignent que les champs métier et comptent sur la base pour
            // le contexte d'établissement.
            $props = $this->object->getProps();
            if (isset($props['group_id'])) {
                $this->object->group_id = $this->group_id;
            }
        }
    }

    /**
     * Génère (ou réutilise) l'objet. La sous-classe renseigne $this->object,
     * appelle store(), et le retourne.
     *
     * @return CMbObject
     */
    abstract public function generate();

    /**
     * @return CMbObject L'objet généré (non null après generate()).
     */
    public function getObject()
    {
        return $this->object;
    }

    /**
     * @return string[] Les traces accumulées.
     */
    public function getTrace(): array
    {
        return $this->trace;
    }

    /**
     * Force la création plutôt que la réutilisation.
     *
     * @return $this
     */
    public function setForce(bool $force = true): self
    {
        $this->force = $force;

        return $this;
    }

    /**
     * @return $this
     */
    public function setType($type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return $this
     */
    public function setGroupId($group_id): self
    {
        $this->group_id = $group_id;

        return $this;
    }

    /**
     * Seuil d'objets du modèle au-delà duquel getRandomObject() réutilise
     * l'existant. Surchargeable par la sous-classe.
     *
     * @return int
     */
    protected function getMaxCount(): int
    {
        return static::DEFAULT_MAX_COUNT;
    }

    /**
     * Retourne un objet existant aléatoire du modèle si leur nombre atteint
     * déjà $max_count (afin de ne pas gonfler indéfiniment la base) ; sinon
     * null pour signaler à la sous-classe qu'elle doit en créer un nouveau.
     *
     * @param int         $max_count Seuil de réutilisation
     * @param string|null $where     Clause WHERE additionnelle (SQL brut)
     *
     * @return CMbObject|null
     */
    protected function getRandomObject(int $max_count, ?string $where = null)
    {
        $class = static::$mb_class;
        /** @var CMbObject $model */
        $model = new $class();

        $ds        = CSQLDataSource::get(static::$ds);
        $table     = $model->_spec->table;
        $key       = $model->_spec->key;
        $where_sql = $where ? " WHERE $where" : '';

        $count = (int)$ds->loadResult("SELECT COUNT(`$key`) FROM `$table`$where_sql");
        if ($count < $max_count) {
            return null;
        }

        // Décalage aléatoire dans l'ensemble existant.
        $offset = random_int(0, max(0, $count - 1));
        $id     = $ds->loadResult("SELECT `$key` FROM `$table`$where_sql LIMIT $offset, 1");
        if (!$id) {
            return null;
        }

        $model->load($id);

        return $model->_id ? $model : null;
    }

    /**
     * Retourne un tableau de prénoms aléatoires (objets CFirstNameAssociativeSex
     * exposant ->firstname et ->sex).
     *
     * @param int $count Nombre de prénoms
     *
     * @return CFirstNameAssociativeSex[]
     */
    protected function getRandomNames(int $count = 1): array
    {
        $name  = new CFirstNameAssociativeSex();
        $ds    = CSQLDataSource::get(static::$ds);
        $table = $name->_spec->table;
        $key   = $name->_spec->key;

        $total = (int)$ds->loadResult("SELECT COUNT(`$key`) FROM `$table`");
        $names = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $obj = new CFirstNameAssociativeSex();
            if ($total > 0) {
                $offset = random_int(0, $total - 1);
                $id     = $ds->loadResult("SELECT `$key` FROM `$table` LIMIT $offset, 1");
                if ($id) {
                    $obj->load($id);
                }
            }
            if (!$obj->_id) {
                // Repli : prénom synthétique si la table de référence est vide.
                $obj->firstname = 'Test' . random_int(1, 100000);
            }
            $names[] = $obj;
        }

        return $names;
    }

    /**
     * @return string Code postal aléatoire (5 chiffres).
     */
    protected function getRandomCP(): string
    {
        return str_pad((string)random_int(1000, 95999), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Enregistre une trace de génération (TRACE_LOAD / TRACE_STORE).
     *
     * @param string $type
     *
     * @return void
     */
    protected function trace(string $type): void
    {
        $this->trace[] = $type;
    }
}
