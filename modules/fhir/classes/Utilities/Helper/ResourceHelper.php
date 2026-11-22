<?php

/**
 * @package Mediboard\Fhir\Objects
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\Helper;

use Ox\Core\CMbException;
use Ox\Core\CStoredObject;
use Ox\Interop\Eai\CMbOID;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Loinc\CLoinc;
use Hospilibre\Sante400\CIdSante400;

class ResourceHelper
{
    /**
     * Recherche l'identifiant Ox porté par une ressource FHIR.
     *
     * @param CFHIRDomainResource $resource
     * @param string              $class
     *
     * @return string|null
     * @throw CFHIRException
     */
    public static function getOxObjectIdentifier(CFHIRDomainResource $resource, string $class): ?string
    {
        if (!is_subclass_of($class, CStoredObject::class)) {
            throw CFHIRException::tr('ResourceHelper-msg-invalid class given', $class);
        }

        $oxRootOid = CMbOID::OX_ROOT_OID;

        foreach ($resource->identifier as $candidate) {
            if ($candidate->isSystemMatch($oxRootOid)) {
                return $candidate->value->getValue();
            }
        }

        return null;
    }

    /**
     * Indique si l'URI fournie désigne le système LOINC.
     *
     * @param CFHIRDataTypeUri $datatype_uri
     *
     * @return bool
     */
    public static function isLoincSystem(CFHIRDataTypeUri $datatype_uri): bool
    {
        $uriValue = $datatype_uri->getValue();

        if (!$uriValue) {
            return false;
        }

        if ($uriValue === CLoinc::$system_loinc) {
            return true;
        }

        return $datatype_uri->isSystemMatch(CLoinc::$oid_loinc);
    }

    /**
     * Reconstruit un idex à partir d'un identifiant FHIR.
     *
     * @param CFHIRDataTypeIdentifier $identifier
     *
     * @return CIdSante400|null
     * @throws CMbException
     */
    public static function getIdexFromIdentifier(CFHIRDataTypeIdentifier $identifier): ?CIdSante400
    {
        $rawValue = $identifier->value ? $identifier->value->getValue() : null;

        if ($rawValue === null) {
            return null;
        }

        $idexOid = CMbOID::getClassOID(CIdSante400::class);
        if (!$identifier->system->isSystemMatch($idexOid)) {
            return null;
        }

        $pipePosition = strrpos($rawValue, '|');
        if ($pipePosition === false) {
            return null;
        }

        $idex        = new CIdSante400();
        $idex->tag   = substr($rawValue, 0, $pipePosition);
        $idex->id400 = substr($rawValue, $pipePosition + 1, strlen($rawValue));

        return $idex;
    }
}
