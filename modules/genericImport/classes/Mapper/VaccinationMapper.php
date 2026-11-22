<?php

/**
 * @package Hospilibre\GenericImport
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\GenericImport\Mapper;

use Ox\Import\Framework\Entity\EntityInterface;
use Ox\Import\Framework\Entity\Vaccination;
use Ox\Import\Framework\Mapper\AbstractMapper;
use Hospilibre\Patients\Import\OxPivotVaccination;

/**
 * Vaccination mapper for generic import
 */
class VaccinationMapper extends AbstractMapper
{
    /**
     * @inheritDoc
     */
    protected function createEntity($row): EntityInterface
    {
        $map = [
            'external_id'  => $this->getValue($row, OxPivotVaccination::FIELD_ID),
            'injection_id' => $this->getValue($row, OxPivotVaccination::FIELD_INJECTION),
            'type'         => $this->getValue($row, OxPivotVaccination::FIELD_TYPE),
        ];

        return Vaccination::fromState($map);
    }
}
