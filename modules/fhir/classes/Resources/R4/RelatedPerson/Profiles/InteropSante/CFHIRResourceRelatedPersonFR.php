<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\RelatedPerson\Profiles\InteropSante;

use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Patient\Profiles\InteropSante\CFHIRResourcePatientFR;
use Ox\Interop\Fhir\Resources\R4\RelatedPerson\CFHIRResourceRelatedPerson;
use Hospilibre\Patients\CCorrespondantPatient;

/**
 * FHIR RelatedPerson ressource
 */
class CFHIRResourceRelatedPersonFR extends CFHIRResourceRelatedPerson
{
    /** @var string  */
    public const PROFILE_TYPE = "FrRelatedPerson";

    /** @var string  */
    public const PROFILE_CLASS = CFHIRInteropSante::class;

    /**
     * Map property patient
     */
    protected function mapPatient(): void
    {
        $patient       = $this->object->loadRefPatient();
        $this->patient = $this->addReference(CFHIRResourcePatientFR::class, $patient);
    }

    protected function mapRelationship(): void
    {
        $codeable = [];
        if ($coding = $this->getRolePerson()) {
            $codeable[] = $coding;
        }

        if ($coding = self::getRelatedPerson($this->object)) {
            $codeable[] = $coding;
        }

        $this->relationship = $codeable;
    }

    private function getRolePerson(): ?CFHIRDataTypeCodeableConcept
    {
        // todo mapping personne confiance ...

        return null;
        return CFHIRDataTypeCodeableConcept::fromValues(
            [
                "code"        => $code,
                "codeSystem"  => "https://mos.esante.gouv.fr/NOS/TRE_R260-HL7RoleClass/FHIR/TRE-R260-HL7RoleClass",
                "displayName" => $display,
            ]
        );
    }

    public static function getRelatedPerson(CCorrespondantPatient $correspondant_patient): ?CFHIRDataTypeCodeableConcept
    {
        $relation = $correspondant_patient->parente;
        switch ($relation) {
            case 'mere':
                $code    = 'MTH';
                $display = 'MÎ¸re';
                break;
            case 'pere':
                $code    = 'FTH';
                $display = 'PÎ¸re';
                break;
            case 'grand_parent':
                $code    = 'GRPRN';
                $display = 'Grand-parent';
                break;
            case 'enfant':
                if ($correspondant_patient->sex === 'm') {
                    $code    = 'SONC';
                    $display = 'Fils';
                } elseif ($correspondant_patient->sex === 'f') {
                    $code = 'DAUC';
                    $display = "Fille";
                } else {
                    return null;
                }
                break;
            default:
                return null;
        }

        return CFHIRDataTypeCodeableConcept::fromValues(
            [
                "code"        => $code,
                "codeSystem"  => "https://mos.esante.gouv.fr/NOS/TRE_R216-HL7RoleCode/FHIR/TRE-R216-HL7RoleCode",
                "displayName" => $display,
            ]
        );
    }
}
