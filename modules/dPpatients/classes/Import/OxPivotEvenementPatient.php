<?php

/**
 * @package Hospilibre\Patients
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Patients\Import;

use Ox\Import\GenericImport\AbstractOxPivotImportableObject;
use Ox\Import\GenericImport\FieldDescription;
use Ox\Import\GenericImport\GenericImport;
use Ox\Import\GenericImport\GenericPivotObject;

/**
 * Description
 */
class OxPivotEvenementPatient extends AbstractOxPivotImportableObject implements GenericPivotObject
{
    public const FIELD_PRATICIEN   = 'praticien';
    public const FIELD_PATIENT     = 'patient';
    public const FIELD_DATE        = 'date';
    public const FIELD_LIBELLE     = 'libelle';
    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_TYPE        = 'type';

    protected const FILE_NAME = GenericImport::EVENEMENT;

    protected function initFields(): void
    {
        if (!$this->importable_fields) {
            $this->importable_fields = [
                self::FIELD_ID          => $this->buildFieldId('Identifiant unique de l\'événement'),
                self::FIELD_PRATICIEN   => $this->buildFieldExternalId(
                    self::FIELD_PRATICIEN,
                    'Identifiant unique du médecin responsable de l\'événement',
                    true
                ),
                self::FIELD_PATIENT     => $this->buildFieldExternalId(
                    self::FIELD_PATIENT,
                    'Identifiant unique du patient',
                    true
                ),
                self::FIELD_DATE        => $this->buildFieldDate(self::FIELD_DATE, 'Date de l\'événement', true),
                self::FIELD_LIBELLE     => $this->buildFieldLibelle(),
                self::FIELD_DESCRIPTION => $this->buildFieldLongText(
                    self::FIELD_DESCRIPTION,
                    'Description de l\'événement'
                ),
                self::FIELD_TYPE        => $this->buildFieldType(),
            ];
        }
    }

    private function buildFieldLibelle(): FieldDescription
    {
        return new FieldDescription(
            self::FIELD_LIBELLE,
            255,
            FieldDescription::FIELD_TYPE_STRING,
            'Libellé de l\'événement',
            true
        );
    }

    private function buildFieldType(): FieldDescription
    {
        return new FieldDescription(
            self::FIELD_TYPE,
            80,
            'sejour = événement de type séjour, intervention = événement de type intervention, evt = événement non spécifique',
            'Type de l\'événement'
        );
    }
}
