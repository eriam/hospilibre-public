<?php

/**
 * @package Hospilibre\Patients\Updators
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Patients\Updators;

use Exception;
use Ox\Core\CSetup;
use Ox\Core\CSQLDataSource;
use Hospilibre\Patients\CIdentityProofType;

class IdentityProofTypeUpdator
{
    /** @var CSetup $setup */
    private $setup;
    /** @var CSQLDataSource */
    private $ds;
    /** @var array */
    private $types_id = [];

    /** @var array */
    public static $types = [
        'PASSEPORT'          => ['label' => 'Passeport', 'id' => 1],
        'ID_CARD'            => ['label' => 'Carte d\'identitÎ“Â©', 'id' => 2],
        'BIRTH_ACT'          => ['label' => 'Acte de naissance', 'id' => 3],
        'FAMILY_RECORD_BOOK' => ['label' => 'Livret de famille', 'id' => 4],
        'RESIDENT_PERMIT'    => ['label' => 'Titre / Carte de sÎ“Â©jour', 'id' => 5],
    ];

    /** @var array */
    public static $old_types_mapper = [
        'passeport'                   => 'PASSEPORT',
        'carte_identite'              => 'ID_CARD',
        'carte_identite_electronique' => 'ID_CARD',
        'acte_naissance'              => 'BIRTH_ACT',
        'livret_famille'              => 'FAMILY_RECORD_BOOK',
        'carte_sejour'                => 'RESIDENT_PERMIT',
    ];

    public function __construct(CSetup $setup)
    {
        $this->setup = $setup;
        $this->ds    = $setup->ds;
    }

    /**
     * Orchestre les trois etapes de la mise a jour.
     */
    public function makeUpdate(): void
    {
        $this->insertTypes();
        $this->updateIdentitySources();
        $this->updateStatusPatient();
    }

    /**
     * Insere en base les types de justificatif d'identite par defaut.
     */
    private function insertTypes(): void
    {
        $trustLevel = CIdentityProofType::TRUST_LEVEL_HIGH;

        $rows = [];
        foreach (static::$types as $typeCode => $typeData) {
            $rows[] = sprintf(
                "(%d, \"%s\", '%s', '%s', '0')",
                $typeData['id'],
                $typeData['label'],
                $typeCode,
                $trustLevel
            );
        }

        $insertQuery = "INSERT INTO `identity_proof_types`
            (`identity_proof_type_id`, `label`, `code`, `trust_level`, `editable`) VALUES \n"
            . implode(",\n", $rows) . ';';

        $this->setup->addQuery($insertQuery);
    }

    /**
     * Fait correspondre les anciens libelles de justificatif aux nouveaux identifiants.
     */
    private function updateIdentitySources(): void
    {
        foreach (static::$old_types_mapper as $legacyType => $typeCode) {
            if (!array_key_exists($typeCode, static::$types)) {
                continue;
            }

            $newTypeId = static::$types[$typeCode]['id'];
            $this->setup->addQuery(
                "UPDATE `source_identite` SET `identity_proof_type_id` = {$newTypeId}"
                . " WHERE `type_justificatif` = '{$legacyType}';"
            );
        }
    }

    /**
     * Repositionne en provisoire les patients dont la source d'identite provient de l'interop.
     */
    private function updateStatusPatient(): void
    {
        $this->setup->addQuery(
            "UPDATE `patients` LEFT JOIN `source_identite`
                ON `source_identite`.`source_identite_id` = `patients`.`source_identite_id` SET `status` = 'PROV'
                WHERE `source_identite`.`mode_obtention` = 'interop'
                AND `source_identite`.`type_justificatif` IN ('absence_justificatif', 'doc_asile');"
        );
    }
}
