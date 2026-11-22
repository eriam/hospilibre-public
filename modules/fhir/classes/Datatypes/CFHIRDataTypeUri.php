<?php
/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Datatypes;

use Ox\Core\CMbString;

/**
 * FHIR data type
 */
class CFHIRDataTypeUri extends CFHIRDataType
{
    /** @var string */
    public const NAME = 'Uri';

    /**
     * @param $value
     *
     * @return void
     */
    public function setValue($value): void
    {
        if ($value && CMbString::isUUID($value)) {
            $value = "urn:uuid:$value";
        } elseif ($value && CMbString::isOID($value)) {
            $value = "urn:oid:$value";
        }

        parent::setValue($value);
    }

    /**
     * Know if value is an OID
     *
     * @return bool
     */
    public function isOID(): bool
    {
        if (!$this->_value) {
            return false;
        }

        return str_starts_with($this->_value, 'urn:oid:') || CMbString::isOID($this->_value);
    }


    /**
     * Know if value is an UUID
     *
     * @return bool
     */
    public function isUUID(): bool
    {
        if (!$this->_value) {
            return false;
        }

        return str_starts_with($this->_value, 'urn:uuid:') || CMbString::isUUID($this->_value);
    }

    /**
     * @param string $system
     *
     * @return bool
     */
    public function isSystemMatch(string $system): bool
    {
        return preg_match("/^(?:urn:(?:uuid|oid):)?$system$/", $this->_value ?? '');
    }
}
