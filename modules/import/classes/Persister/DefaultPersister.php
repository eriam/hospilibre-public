<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Framework\Persister;

use Ox\Core\CStoredObject;
use Ox\Import\Framework\Exception\PersisterException;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Files\CFile;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\CSourceIdentite;
use Hospilibre\PlanningOp\CSejour;
use Hospilibre\SalleOp\CActeCCAM;

class DefaultPersister extends AbstractPersister
{
    public function persistObject(CStoredObject $object): CStoredObject
    {
        switch (get_class($object)) {
            case CPatient::class:
                return $this->persistPatient($object);
            case CFile::class:
                return $this->persistFile($object);
            case CActeCCAM::class:
                return $this->persistActeCCAM($object);
            case CSejour::class:
                return $this->persistSejour($object);
            default:
                return parent::persistObject($object);
        }
    }

    /**
     * Disable IPP generation and set the obtention_mode to import
     */
    protected function persistPatient(CPatient $patient): CPatient
    {
        $patient->_generate_IPP   = false;
        $patient->_mode_obtention = CSourceIdentite::MODE_OBTENTION_IMPORT;

        return $this->persist($patient);
    }

    /**
     * Check if the _file_path is set
     *
     * @param CFile $file
     *
     * @return CFile
     * @throws PersisterException
     */
    public function persistFile(CFile $file): CFile
    {
        if (!$file->_file_path) {
            throw new PersisterException('PersisterException-File must have a context');
        }

        return $this->persist($file);
    }

    /**
     * Must add the acte_ccam to the consultation and store it before storing the acte itself
     *
     * @param CActeCCAM $acte
     *
     * @return CActeCCAM
     * @throws PersisterException
     */
    public function persistActeCCAM(CActeCCAM $acte): CActeCCAM
    {
        /** @var CConsultation $consult */
        $consult             = $acte->loadFwdRef('object_id', true);
        $consult->codes_ccam = ($consult->codes_ccam)
            ? implode('|', array_merge(explode('|', $consult->codes_ccam), [$acte->code_acte]))
            : $acte->code_acte;

        if ($msg = $consult->store()) {
            throw new PersisterException($msg);
        }

        return $this->persist($acte);
    }

    /**
     * @param CSejour $sejour
     *
     * @return CSejour
     * @throws PersisterException
     */
    protected function persistSejour(CSejour $sejour): CSejour
    {
        $sejour->_generate_NDA = false;

        return $this->persist($sejour);
    }
}
