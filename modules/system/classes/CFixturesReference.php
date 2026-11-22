<?php

/**
 * @package Hospilibre\System
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre. La classe amont (Ox\Erp\SourceCode\CFixturesReference) n'est
 * pas distribuée avec le fork ; le contrat est ici reconstruit à partir de ses seuls
 * usages (Ox\Tests\Fixtures\Fixtures, Ox\Tests\TestHospilibre et leurs tests).
 */

namespace Hospilibre\System;

use Exception;
use Ox\Core\CMbModelNotFoundException;
use Ox\Core\CMbObjectSpec;
use Ox\Core\CStoredObject;

/**
 * Trace d'un objet créé par une fixture.
 *
 * Deux rôles, et c'est ce qui explique la forme de la table :
 *  - permettre la purge ciblée d'une fixture (tous les objets qu'elle a créés, sans
 *    connaître leur classe à l'avance) — d'où la méta-référence sur object_class ;
 *  - rendre un objet de fixture retrouvable par étiquette stable depuis un test
 *    (Ox\Tests\TestHospilibre::getObjectFromFixturesReference) — d'où le tag.
 *
 * object_class accepte le nom court ('CPatient') comme le nom pleinement qualifié : les
 * deux formes circulent dans le code appelant, et loadFwdRef() les résout indifféremment
 * grâce à l'aliasing d'autoload (CAutoloadAlias, enregistré au boot).
 */
class CFixturesReference extends CStoredObject
{
    /** @var int Clé primaire */
    public $fixtures_reference_id;

    /** @var string Classe de fixture émettrice (nom pleinement qualifié) */
    public $fixtures_class;

    /** @var string Classe de l'objet créé */
    public $object_class;

    /** @var int Identifiant de l'objet créé */
    public $object_id;

    /** @var string Étiquette stable, facultative */
    public $tag;

    /** @var CStoredObject */
    public $_ref_object;

    /**
     * @inheritdoc
     */
    public function getSpec(): CMbObjectSpec
    {
        $spec = parent::getSpec();

        $spec->table = 'fixtures_reference';
        $spec->key   = 'fixtures_reference_id';

        return $spec;
    }

    /**
     * @inheritdoc
     */
    public function getProps(): array
    {
        $props = parent::getProps();

        $props['fixtures_class'] = 'str notNull maxLength|255';
        $props['object_class']   = 'str notNull maxLength|255';
        $props['object_id']      = 'ref notNull cascade class|CStoredObject meta|object_class'
            . ' back|fixtures_references';
        $props['tag']            = 'str maxLength|255';

        return $props;
    }

    /**
     * @inheritdoc
     */
    public function store(): ?string
    {
        if (!$this->fixtures_class || !$this->object_class || !$this->object_id) {
            return 'common-error-Missing parameter';
        }

        return parent::store();
    }

    /**
     * Charge l'objet tracé par cette référence.
     *
     * Lève plutôt que de rendre null : Ox\Tests\Fixtures\Fixtures::purge() distingue
     * « cible déjà purgée » (Exception attrapée, on passe) de « cible présente » par le
     * seul fait que cet appel échoue ou non. Rendre null ferait remonter un TypeError
     * dans le delete() qui suit — une Error, donc hors du catch (Exception) appelant.
     *
     * @throws CMbModelNotFoundException|Exception
     */
    public function loadTarget(): CStoredObject
    {
        $target = $this->loadFwdRef('object_id', true);

        if (!$target || !$target->_id) {
            throw new CMbModelNotFoundException(
                "Unable to load fixtures target {$this->object_class}-{$this->object_id}"
            );
        }

        return $this->_ref_object = $target;
    }

    /**
     * @inheritdoc
     */
    public function __toString(): string
    {
        return $this->object_class . '-' . $this->object_id . ($this->tag ? " ({$this->tag})" : '');
    }
}
