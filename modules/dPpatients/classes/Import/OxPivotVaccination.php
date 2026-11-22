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
use Hospilibre\Cabinet\Vaccination\CVaccination;

/**
 * Pivot for vaccination import
 */
class OxPivotVaccination extends AbstractOxPivotImportableObject implements GenericPivotObject
{
    public const FIELD_INJECTION = 'injection';
    public const FIELD_TYPE      = 'type';

    protected const FILE_NAME = GenericImport::VACCINATION;

    protected function initFields(): void
    {
        if (!$this->importable_fields) {
            $this->importable_fields = [
                self::FIELD_ID        => $this->buildFieldId('Identifiant unique de la vaccination'),
                self::FIELD_INJECTION => $this->buildFieldInjection(),
                self::FIELD_TYPE      => $this->buildFieldType(),
            ];
        }
    }

    private function buildFieldInjection(): FieldDescription
    {
        return $this->buildFieldExternalId(
            self::FIELD_INJECTION,
            'Identifiant unique de l\'injection',
            true
        );
    }

    private function buildFieldType(): FieldDescription
    {
        return new FieldDescription(
            self::FIELD_TYPE,
            80,
            implode(", ", CVaccination::TYPES_VACCINATIONS),
            'Type de vaccin (valeur par défaut Autre)'
        );
    }
}
