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
class OxPivotConstante extends AbstractOxPivotImportableObject implements GenericPivotObject
{
    public const FIELD_PRATICIEN = 'praticien';
    public const FIELD_PATIENT   = 'patient';
    public const FIELD_DATE      = 'date';
    public const FIELD_TAILLE    = 'taille';
    public const FIELD_POIDS     = 'poids';

    protected const FILE_NAME = GenericImport::CONSTANTE;

    protected function initFields(): void
    {
        if (!$this->importable_fields) {
            $this->importable_fields = [
                self::FIELD_ID        => $this->buildFieldId('Identifiant unique de la constante'),
                self::FIELD_PRATICIEN => $this->buildFieldExternalId(
                    self::FIELD_PRATICIEN,
                    'Identifiant unique du médecin responsable de la saisie de la constante'
                ),
                self::FIELD_PATIENT   => $this->buildFieldExternalId(
                    self::FIELD_PATIENT,
                    'Identifiant unique du patient',
                    true
                ),
                self::FIELD_DATE      => $this->buildFieldDate(
                    self::FIELD_DATE,
                    'Date et heure de saisie de la constante'
                ),
                self::FIELD_TAILLE    => $this->buildFieldTaille(),
                self::FIELD_POIDS     => $this->buildFieldPoids(),
            ];
        }
    }

    private function buildFieldTaille(): FieldDescription
    {
        return new FieldDescription(
            self::FIELD_TAILLE,
            5,
            FieldDescription::FIELD_TYPE_INT,
            'Taille du patient en centimÎ¸tres (cm)'
        );
    }

    private function buildFieldPoids(): FieldDescription
    {
        return new FieldDescription(
            self::FIELD_TAILLE,
            10,
            FieldDescription::FIELD_TYPE_FLOAT,
            'Poids du patient en kilogrammes (Kg)'
        );
    }
}
