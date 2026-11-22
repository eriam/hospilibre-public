<?php

/**
 * @package Hospilibre\Patients
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Patients;

use Exception;
use Ox\Core\CMbException;
use Ox\Core\CMbObject;
use Ox\Core\CMbObjectSpec;
use Hospilibre\Etablissement\CGroups;

/**
 * Type de justificatif d'identité rattaché à un établissement.
 */
class CIdentityProofType extends CMbObject
{
    public const TRUST_LEVEL_HIGH         = '3';
    public const TRUST_LEVEL_MEDIUM       = '2';
    public const TRUST_LEVEL_LOW          = '1';
    public const PROOF_IDENTITY_NONE_CODE = 'ABS_INTEROP';

    public const PROOF_ACTE_NAISSANCE = "BIRTH_ACT";

    public const PROOF_CARTE_ID = "ID_CARD";

    public const PROOF_LIVRET_FAMILLE   = "FAMILY_RECORD_BOOK";

    public const PROOF_PASSEPORT        = "PASSEPORT";

    public const PROOF_PERMIT_RESIDENCE = "RESIDENT_PERMIT";

    /** @var int Clef primaire */
    public $identity_proof_type_id;
    /** @var string */
    public $label;
    /** @var string */
    public $code;
    /** @var string */
    public $trust_level;
    /** @var string */
    public $active;
    /** @var string */
    public $editable;
    /** @var string */
    public $validate_identity;
    /** @var int */
    public $group_id;

    /**
     * Liste des niveaux de confiance possibles.
     */
    public static function getTrustLevels(): array
    {
        return [self::TRUST_LEVEL_HIGH, self::TRUST_LEVEL_MEDIUM, self::TRUST_LEVEL_LOW];
    }

    /**
     * @return CIdentityProofType[]
     *
     * @throws Exception
     */
    public static function getEditableTypes(): array
    {
        $currentGroup = CGroups::get();

        $conditions = [
            'editable' => " = '1'",
            "`group_id` = {$currentGroup->_id} OR `group_id` IS NULL",
        ];

        $rows = (new self())->loadList($conditions, 'trust_level DESC, label ASC');

        return $rows ?: [];
    }

    /**
     * @return CIdentityProofType[]
     *
     * @throws Exception
     */
    public static function getActiveTypes(): array
    {
        $currentGroup = CGroups::get();

        $conditions = [
            'active' => " = '1'",
            "`group_id` = {$currentGroup->_id} OR `group_id` IS NULL",
        ];

        $rows = (new self())->loadList($conditions, 'trust_level DESC, editable ASC, label ASC');

        return $rows ?: [];
    }

    /**
     * @return CIdentityProofType[]
     *
     * @throws CMbException
     */
    public static function getActiveTypesByTrustLevel(string $trust_level): array
    {
        if (!in_array($trust_level, self::getTrustLevels())) {
            throw new CMbException('CIdentityProofType-error-invalid_trust_level');
        }

        $currentGroup = CGroups::get();

        $conditions = [
            'trust_level' => " = {$trust_level}",
            "`group_id` = {$currentGroup->_id} OR `group_id` IS NULL",
        ];

        $rows = (new self())->loadList($conditions, 'trust_level DESC, editable ASC, label ASC');

        return $rows ?: [];
    }

    /**
     * Recherche filtrée par code, libellé et niveau de confiance, paginée.
     */
    public static function filter(
        string $code = null,
        string $label = null,
        string $trust_level = null,
        string $active = '1',
        int $start = 0
    ): array {
        $rows = (new self())->loadList(
            self::getQuery($code, $label, $trust_level, $active),
            'trust_level DESC, editable ASC, label ASC',
            "{$start}, 20"
        );

        return $rows ?: [];
    }

    private static function getQuery(
        string $code = null,
        string $label = null,
        string $trust_level = null,
        string $active = '1'
    ): array {
        $spec = (new self())->getSpec();
        $spec->init();

        $conditions = [];

        if ($code) {
            $conditions[] = $spec->ds->prepareLikeMulti($code, 'code');
        }

        if ($label) {
            $conditions[] = $spec->ds->prepareLikeMulti($label, 'label');
        }

        if ($trust_level && in_array($trust_level, self::getTrustLevels())) {
            $conditions[] = "`trust_level` = '{$trust_level}'";
        }

        if (is_string($active) && in_array($active, ['1', '0'])) {
            $conditions[] = "`active` = '{$active}'";
        }

        $conditions[] = "`group_id` IS NULL OR `group_id` = " . CGroups::get()->_id;

        return $conditions;
    }

    public static function count(
        string $code = null,
        string $label = null,
        string $trust_level = null,
        string $active = '1'
    ): int {
        $total = (new self())->countList(self::getQuery($code, $label, $trust_level, $active));

        return $total ?? 0;
    }

    /**
     * Charge un type de justificatif à partir de son code.
     *
     * @param string $code Code du type de justificatif
     *
     * @return self
     */
    public static function get(string $code): self
    {
        $type       = new self();
        $type->code = $code;
        $type->loadMatchingObject();

        return $type;
    }

    /**
     * @inheritdoc
     */
    public function getSpec(): CMbObjectSpec
    {
        $spec = parent::getSpec();

        $spec->table = 'identity_proof_types';
        $spec->key   = 'identity_proof_type_id';

        return $spec;
    }

    /**
     * @inheritdoc
     */
    public function getProps(): array
    {
        $props = parent::getProps();

        $props['label']             = 'str notNull seekable';
        $props['code']              = 'str notNull seekable';
        $props['trust_level']       = 'enum list|' . implode('|', self::getTrustLevels()) . ' notNull';
        $props['active']            = 'bool default|1';
        $props['validate_identity'] = 'bool default|0';
        $props['editable']          = 'bool default|1';
        $props['group_id']          = 'ref class|CGroups back|identity_proof_types';

        return $props;
    }

    public function store(): ?string
    {
        // À la création, rattacher au groupe courant si aucun groupe n'est fixé.
        if (!$this->_id && !$this->group_id) {
            $this->group_id = CGroups::get()->_id;
        }

        // Un type non modifiable conserve son code d'origine.
        if (!$this->editable && $this->fieldModified('code')) {
            $this->code = $this->_old->code;
        }

        // La validation d'identité n'est possible qu'au niveau de confiance élevé.
        if ($this->trust_level !== self::TRUST_LEVEL_HIGH) {
            $this->validate_identity = '0';
        }

        return parent::store();
    }

    public function updateFormFields(): void
    {
        parent::updateFormFields();

        $this->_view = $this->label;
    }

    public function isEditable(): bool
    {
        return $this->editable === '1';
    }

    public function isActive(): bool
    {
        return $this->active === '1';
    }

    public function isValidate(): bool
    {
        return $this->validate_identity === '1';
    }
}
