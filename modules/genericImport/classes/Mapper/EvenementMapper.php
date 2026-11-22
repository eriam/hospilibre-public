<?php

/**
 * @package Hospilibre\GenericImport
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\GenericImport\Mapper;

use Ox\Import\Framework\Entity\EntityInterface;
use Ox\Import\Framework\Entity\EvenementPatient;
use Ox\Import\Framework\Mapper\AbstractMapper;
use Hospilibre\Patients\Import\OxPivotEvenementPatient;

/**
 * Description
 */
class EvenementMapper extends AbstractMapper
{
    /**
     * @inheritDoc
     */
    protected function createEntity($row): EntityInterface
    {
        $map = [
            'external_id'  => $this->getValue($row, OxPivotEvenementPatient::FIELD_ID),
            'date'         => $this->getValue($row, OxPivotEvenementPatient::FIELD_DATE)
                ? $this->convertToDateTime($row[OxPivotEvenementPatient::FIELD_DATE]) : null,
            'libelle'      => $this->getValue($row, OxPivotEvenementPatient::FIELD_LIBELLE),
            'description'  => $this->getValue($row, OxPivotEvenementPatient::FIELD_DESCRIPTION),
            'praticien_id' => $this->getValue($row, OxPivotEvenementPatient::FIELD_PRATICIEN),
            'patient_id'   => $this->getValue($row, OxPivotEvenementPatient::FIELD_PATIENT),
            'type'         => $this->getValue($row, OxPivotEvenementPatient::FIELD_TYPE),

        ];

        return EvenementPatient::fromState($map);
    }
}
