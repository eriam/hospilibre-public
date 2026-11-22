<?php

/**
 * @package Mediboard\Fhir\Objects
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\Helper;

use Exception;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Resources\R4\Patient\CFHIRResourcePatient;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\CPatientINSNIR;

class PatientHelper
{
    /** @var string */
    public const INS_NIR = CPatientINSNIR::OID_INS_NIR;
    /** @var string */
    public const INS_NIA = CPatientINSNIR::OID_INS_NIA;
    /** @var string */
    public const INS_TEST = CPatientINSNIR::OID_INS_NIR_TEST;

    /**
     * Récupère l'INS du type demandé dans une ressource FHIR patient.
     *
     * @param CFHIRResourcePatient $resource_patient
     * @param string               $ins_type
     *
     * @return string|null
     * @throw CFHIRException|Exception
     */
    public static function getINS(CFHIRResourcePatient $resource_patient, string $ins_type = self::INS_NIR): ?string
    {
        $types_autorises = [self::INS_NIR, self::INS_NIA, self::INS_TEST];

        if (!in_array($ins_type, $types_autorises)) {
            throw CFHIRException::tr('PatientHelper-msg-type INS invalid', $ins_type);
        }

        return self::extractIdentifierValue($resource_patient, $ins_type);
    }

    /**
     * Récupère l'IPP correspondant à l'OID fourni dans une ressource FHIR patient.
     *
     * @param CFHIRResourcePatient $resource_patient
     * @param string               $ipp_oid
     *
     * @return string|null
     */
    public static function getIPP(CFHIRResourcePatient $resource_patient, string $ipp_oid): ?string
    {
        return self::extractIdentifierValue($resource_patient, $ipp_oid);
    }

    /**
     * Parcourt les identifiants et renvoie la valeur du premier dont le système correspond.
     *
     * @param CFHIRResourcePatient $resource_patient
     * @param string               $system
     *
     * @return string|null
     */
    private static function extractIdentifierValue(CFHIRResourcePatient $resource_patient, string $system): ?string
    {
        foreach ($resource_patient->identifier as $identifier) {
            if ($identifier->isSystemMatch($system)) {
                return $identifier->value->getValue();
            }
        }

        return null;
    }

    /**
     * Mappe les informations principales d'une ressource FHIR vers un objet CPatient.
     *
     * @param CFHIRResourcePatient $resource_patient
     *
     * @return CPatient|null
     * @throws Exception
     */
    public static function primaryMapping(CFHIRResourcePatient $resource_patient): ?CPatient
    {
        $patient = new CPatient();

        // Date de naissance
        $patient->naissance = $resource_patient->birthDate
            ? $resource_patient->birthDate->getDate()
            : null;

        $official_name = self::getOfficialName($resource_patient);
        if ($official_name) {
            // Nom de famille
            if ($official_name->family && !$official_name->family->isNull()) {
                $patient->nom = $official_name->family->getValue();
            }

            // Prénoms
            if ($official_name->given) {
                foreach ($official_name->given as $index => $given) {
                    if ($index > 3) {
                        break;
                    }

                    $field = $index === 0 ? 'prenom' : ('_prenom_' . ($index + 1));

                    $patient->{$field} = $given->getValue();
                }
            }
        }

        // Décès
        $patient->deces = $resource_patient->deceased instanceof CFHIRDataTypeDateTime
            ? $resource_patient->deceased->getValue()
            : null;

        // Sexe
        $patient->sexe = self::mapGender($resource_patient->gender);

        return $patient;
    }

    /**
     * Renvoie le nom officiel présent dans la ressource, s'il existe.
     *
     * @param CFHIRResourcePatient $resource_patient
     *
     * @return CFHIRDataTypeHumanName|null
     */
    public static function getOfficialName(CFHIRResourcePatient $resource_patient): ?CFHIRDataTypeHumanName
    {
        foreach ($resource_patient->name ?? [] as $name) {
            if ($name->use && $name->use->getValue() === 'official') {
                return $name;
            }
        }

        return null;
    }

    /**
     * Traduit le code de genre FHIR vers le code de sexe interne.
     *
     * @param CFHIRDataTypeCode|null $code
     *
     * @return string|null
     */
    public static function mapGender(?CFHIRDataTypeCode $code): ?string
    {
        if (!$code || !$code->getValue()) {
            return null;
        }

        switch ($code->getValue()) {
            case 'male':
                return 'm';

            case 'female':
                return 'f';

            case 'unknown':
            default:
                return 'i';
        }
    }
}
