<?php

/**
 * @package Mediboard\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Datatypes;

/**
 * Type de donnée FHIR « canonical » : une référence pouvant être suffixée
 * par un numéro de version sous la forme "url|version".
 */
class CFHIRDataTypeCanonical extends CFHIRDataType
{
    /** @var string */
    public const NAME = 'Canonical';

    /** @var numeric|null Version optionnelle associée à la référence */
    private $version;

    /**
     * @param mixed $value   Valeur de la référence canonique
     * @param mixed $version Version optionnelle (numérique)
     */
    public function __construct($value = null, $version = null)
    {
        parent::__construct($value);

        $this->version = $version;
    }

    /**
     * Retourne la référence, suffixée par "|version" lorsqu'une version existe.
     *
     * @return string
     */
    public function getValue()
    {
        if ($this->version === null) {
            return $this->_value;
        }

        return $this->_value . '|' . $this->version;
    }

    /**
     * @return float|int|string|null
     */
    public function getVersion()
    {
        return $this->version;
    }
}
