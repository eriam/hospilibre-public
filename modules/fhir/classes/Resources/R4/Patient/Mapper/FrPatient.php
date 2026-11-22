<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Resources\R4\Patient\Mapper;

use Exception;
use Hospilibre\Patients\CCorrespondantPatient;
use Hospilibre\Patients\CIdentityProofType;
use Hospilibre\Patients\CINSPatient;
use Hospilibre\Patients\CPatient;
use Hospilibre\Patients\CPatientINSNIR;
use Hospilibre\Patients\CSourceIdentite;
use Ox\Interop\Eai\CDomain;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientContact;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCoding;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Profiles\CFHIRInteropSante;
use Ox\Interop\Fhir\Resources\R4\Organization\Profiles\InteropSante\CFHIRResourceOrganizationFR;
use Ox\Interop\Fhir\Resources\R4\Patient\Profiles\InteropSante\CFHIRResourcePatientFR;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\InteropSante\CFHIRResourcePractitionerFR;
use Ox\Interop\Fhir\Resources\R4\RelatedPerson\Profiles\InteropSante\CFHIRResourceRelatedPersonFR;
use Ox\Interop\Fhir\Resources\ResourceTrait;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Mapper FHIR du profil FrPatient (InteropSanté).
 */
class FrPatient extends Patient
{
    use ResourceTrait;

    /** @var CPatient */
    protected $object;

    /** @var CFHIRResourcePatientFR */
    protected $resource;

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapExtension(): array
    {
        // URL des extensions déclarées systématiquement, avec une valeur vide.
        $emptyValued = [
            'http://hl7.org/fhir/StructureDefinition/patient-nationality',
            'http://interopsante.org/fhir/StructureDefinition/FrPatientIdentReliability',
            'http://interopsante.org/fhir/StructureDefinition/FrPatientDeathPlace',
            'http://interopsante.org/fhir/StructureDefinition/FrPatientIdentityMethodCollection',
            'http://interopsante.org/fhir/StructureDefinition/FrPatientBirthdateUpdateIndicator',
        ];

        $extensions = [];
        foreach ($emptyValued as $url) {
            $extensions[] = CFHIRDataTypeExtension::addExtension($url, ['value' => []]);
        }

        if ($this->object->cp_naissance) {
            $birthAddress = CFHIRDataTypeAddress::build(['district' => $this->object->cp_naissance]);
            $extensions[] = CFHIRDataTypeExtension::addExtension(
                'http://hl7.org/fhir/StructureDefinition/patient-birthPlace',
                ['valueAddress' => $birthAddress]
            );
        }

        // todo gestion des extension null

        $activeSource = $this->object->loadRefSourceIdentite();
        if ($activeSource && $activeSource->_id) {
            $subExtensions = [];

            $identityCode = $this->getCodeSourceIdentity();
            if ($identityCode) {
                $subExtensions[] = CFHIRDataTypeExtension::addExtension(
                    'identityReliability',
                    ['valueCoding' => CFHIRDataTypeCoding::fromValues($identityCode)]
                );
                $subExtensions[] = CFHIRDataTypeExtension::addExtension(
                    'validationDate',
                    ['valueDate' => new CFHIRDataTypeDate($activeSource->debut)]
                );
            }

            $identityMode = $this->getModeSourceIdentity($activeSource);
            if ($identityMode) {
                $subExtensions[] = CFHIRDataTypeExtension::addExtension(
                    'validationMode',
                    ['valueCoding' => CFHIRDataTypeCoding::fromValues($identityMode)]
                );
            }

            if ($subExtensions) {
                $extensions[] = CFHIRDataTypeExtension::addExtension(
                    'http://interopsante.org/fhir/StructureDefinition/FrPatientIdentReliability',
                    ['extension' => $subExtensions]
                );
            }
        }

        return $extensions;
    }

    /**
     * @throws Exception
     */
    public function mapIdentifier(): array
    {
        $codeSystem  = 'http://interopsante.org/CodeSystem/fr-v2-0203';
        $identifiers = [];

        $insNir = $this->object->loadRefPatientINSNIR();
        if ($insNir) {
            if ($insNir->_is_ins_nir) {
                $coding        = CFHIRDataTypeCoding::addCoding($codeSystem, 'INS-NIR', 'NIR');
                $type          = CFHIRDataTypeCodeableConcept::addCodeable($coding);
                $identifiers[] = $this->resource->addIdentifier(
                    $insNir->ins_nir,
                    $type,
                    'official',
                    CPatientINSNIR::OID_INS_NIR
                );
            }

            if ($insNir->_is_ins_nia) {
                $coding        = CFHIRDataTypeCoding::addCoding($codeSystem, 'INS-NIA', 'NIA');
                $type          = CFHIRDataTypeCodeableConcept::addCodeable($coding);
                $identifiers[] = $this->resource->addIdentifier(
                    $insNir->is_nia,
                    $type,
                    'temp',
                    CPatientINSNIR::OID_INS_NIA
                );
            }
        }

        $insCalcule             = new CINSPatient();
        $insCalcule->patient_id = $this->object->_id;
        $insCalcule->type       = 'C';
        if ($insCalcule->loadMatchingObject()) {
            $coding        = CFHIRDataTypeCoding::addCoding($codeSystem, 'INS-C', 'INS calculé');
            $type          = CFHIRDataTypeCodeableConcept::addCodeable($coding);
            $identifiers[] = $this->resource->addIdentifier(
                $insCalcule->type,
                $type,
                'secondary',
                'urn:oid:1.2.250.1.213.1.4.2'
            );
        }

        $this->object->loadIPP();

        if ($this->object->_IPP) {
            $masterDomain = CDomain::getMasterDomainPatient();
            $system       = null;
            if ($masterDomain && ($oid = $masterDomain->OID)) {
                $system = 'urn:oid:' . $oid;
            }

            $coding        = CFHIRDataTypeCoding::addCoding($codeSystem, 'PI', 'Patient internal identifier');
            $type          = CFHIRDataTypeCodeableConcept::addCodeable($coding);
            $identifiers[] = $this->resource->addIdentifier($this->object->_IPP, $type, 'usual', $system);
        }

        return $identifiers;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function mapName(): array
    {
        $assemblyOrder = [
            'url'   => 'http://terminology.hl7.org/CodeSystem/v2-0444',
            'value' => new CFHIRDataTypeCode('F'),
        ];

        $extension = CFHIRDataTypeExtension::addExtension(
            'http://terminology.hl7.org/CodeSystem/v2-0444',
            ['valueCoding' => 'F']
        );

        //TODO Passer en CFHIRDataTypeFrHumanName
        $names = CFHIRDataTypeHumanName::addName(
            $this->object->nom_jeune_fille,
            $this->object->prenom,
            'official',
            $this->object->prenoms
        );

        if ($this->object->prenom_usuel) {
            //TODO Passer en CFHIRDataTypeFrHumanName
            $names = CFHIRDataTypeHumanName::addName(
                $this->object->nom,
                $this->object->prenom_usuel,
                'usual',
                null,
                $names
            );
        }

        return $names;
    }

    public function mapTelecom(): array
    {
        //TODO Passer en CFHIRDataTypeFrAddress
        return [];
    }

    /**
     */
    public function mapAddress(): array
    {
        //TODO Passer en CFHIRDataTypeFrAddress
        return [];
    }

    /**
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    public function mapContact(): array
    {
        $contacts = [];

        /** @var CCorrespondantPatient[] $correspondants */
        $correspondants = $this->object->loadRefsCorrespondantsPatient();

        foreach ($correspondants as $correspondant) {
            $contact = CFHIRDataTypePatientContact::build(
                [
                    'relationship' => $this->getContactRelationship($correspondant),
                    'name'         => $this->getContactName($correspondant),
                    'telecom'      => $this->getContactTelecom($correspondant),
                    'address'      => $this->getContactAddress($correspondant),
                    'gender'       => new CFHIRDataTypeCode($this->resource->formatGender($correspondant->sex)),
                    'organization' => null,
                    'period'       => null,
                ]
            );

            $contact->extension = $this->getContactExtension($correspondant);

            $contacts[] = $contact;
        }

        return $contacts;
    }

    public function mapGeneralPractitioner(string $resource_class = CFHIRResourcePractitionerFR::class): array
    {
        return parent::mapGeneralPractitioner($resource_class);
    }

    public function mapManagingOrganization(string $resource_class = CFHIRResourceOrganizationFR::class
    ): ?CFHIRDataTypeReference {
        return parent::mapManagingOrganization($resource_class);
    }

    /**
     * @return CFHIRDataTypeExtension[]
     * @throws ReflectionException
     * @throws InvalidArgumentException
     * @throws Exception
     */
    protected function getContactExtension(CCorrespondantPatient $correspondant_patient): array
    {
        $identifier = CFHIRDataTypeIdentifier::build(
            ['value' => new CFHIRDataTypeString($correspondant_patient->getUuid())]
        );

        return [
            CFHIRDataTypeExtension::addExtension(
                'http://interopsante.org/fhir/StructureDefinition/FrPatientContactIdentifier',
                ['value' => $identifier]
            ),
        ];
    }

    /**
     * @param CCorrespondantPatient $_correspondant_patient
     *
     * @return CFHIRDataTypeCodeableConcept[]
     */
    protected function getContactRelationship(CCorrespondantPatient $_correspondant_patient): array
    {
        $rolePersonSystem  = 'urn:oid:2.16.840.1.113883.5.110';
        $rolePersonCode    = 'ECON';
        $rolePersonDisplay = 'Entit  contacter en cas d\'urgence';

        $relatedPersonSystem = 'urn:oid:2.16.840.1.113883.5.111';

        // Cas prioritaire : une RelatedPerson dédiée existe déjà pour ce correspondant.
        $relatedPerson = CFHIRResourceRelatedPersonFR::getRelatedPerson($_correspondant_patient);
        if ($relatedPerson) {
            return [$relatedPerson];
        }

        // Correspondance parenté interne -> code HL7 v3 RoleCode.
        switch ($_correspondant_patient->parente) {
            case 'mere':
                $relatedPersonCode    = 'MTH';
                $relatedPersonDisplay = 'Mre';
                break;

            case 'pere':
                $relatedPersonCode    = 'FTH';
                $relatedPersonDisplay = 'Pre';
                break;

            case 'grand_parent':
                $relatedPersonCode    = 'GRPRN';
                $relatedPersonDisplay = 'Grand-parent';
                break;

            case 'frere':
                $relatedPersonCode    = 'BRO';
                $relatedPersonDisplay = 'Frre';
                break;

            case 'soeur':
                $relatedPersonCode    = 'SIS';
                $relatedPersonDisplay = 'Soeur';
                break;

            case 'petits_enfants':
                $relatedPersonCode    = 'GRNDCHILD';
                $relatedPersonDisplay = 'Petit-enfant';
                break;

            //TODO Vers quel code mapper ? HUSB Epoux, WIFE Epouse, SPS Epoux ou pouse
            case 'epoux':
                $relatedPersonCode    = 'SPS';
                $relatedPersonDisplay = 'Epoux ou pouse';
                break;

            case 'enfant':
                $relatedPersonCode    = 'CHILD';
                $relatedPersonDisplay = 'Enfant';
                break;

            case 'enfant_adoptif':
                $relatedPersonCode    = 'CHLDADOPT';
                $relatedPersonDisplay = 'Enfant adopt';
                break;

            //TODO Vers quel code mapper ? CHLDINLAW Gendre ou belle-fille
            case 'beau_fils':
                $relatedPersonCode    = '';
                $relatedPersonDisplay = '';
                break;

            case 'conjoint':
                $relatedPersonCode    = 'SIGOTHR';
                $relatedPersonDisplay = 'Conjoint';
                break;

            default:
                $relatedPersonCode    = 'FRND';
                $relatedPersonDisplay = 'Autre proche';
                break;
        }

        $relatedPersonCoding = CFHIRDataTypeCoding::addCoding(
            $relatedPersonSystem,
            $relatedPersonCode,
            $relatedPersonDisplay
        );

        $relationships   = [];
        $relationships[] = CFHIRDataTypeCodeableConcept::addCodeable($relatedPersonCoding, $relatedPersonDisplay);

        if (!$relationships) {
            $rolePersonCoding = CFHIRDataTypeCoding::addCoding(
                $relatedPersonSystem,
                $relatedPersonCode,
                $relatedPersonDisplay
            );

            $relationships[] = CFHIRDataTypeCodeableConcept::addCodeable($rolePersonCoding, $rolePersonDisplay);
        }

        return $relationships;
    }

    /**
     * @param CCorrespondantPatient $_correspondant_patient
     *
     * @return CFHIRDataTypeContactPoint[]
     * @throws ReflectionException
     * @throws InvalidArgumentException
     */
    protected function getContactTelecom(CCorrespondantPatient $_correspondant_patient): array
    {
        // TODO Passer Î° CFHIRDataTypeFrContactPoint
        return parent::getContactTelecom($_correspondant_patient);
    }

    protected function getCodeSourceIdentity(): array
    {
        if ($this->object->status === 'QUAL') {
            $code    = 'VALI';
            $display = 'Identité validée';
        } else {
            $code    = 'PROV';
            $display = 'Identité provisoire';
        }

        return [
            'code'        => $code,
            'codeSystem'  => 'http://interopsante.org/fhir/CodeSystem/fr-v2-0445',
            'displayName' => $display,
        ];
    }

    protected function getModeSourceIdentity(CSourceIdentite $source_identite_active): array
    {
        $proofType = $source_identite_active->loadRefIdentityProofType();
        $system    = '';

        switch ($proofType->code) {
            case CIdentityProofType::PROOF_ACTE_NAISSANCE:
                $code    = 'AN';
                $display = "Extrait d'acte de naissance";
                break;

            case CIdentityProofType::PROOF_CARTE_ID:
                $code    = 'CN';
                $display = "Carte nationale d'identité";
                break;

            case CIdentityProofType::PROOF_LIVRET_FAMILLE:
                $code    = 'LE';
                $display = 'Livret de famille';
                break;

            case CIdentityProofType::PROOF_PASSEPORT:
                $code    = 'PA';
                $display = 'Passeport';
                break;

            default:
                return [];
        }

        return [
            'code'        => $code,
            'codeSystem'  => $system,
            'displayName' => $display,
        ];
    }

    public function getProfiles(): array
    {
        return [CFHIRInteropSante::class];
    }
}
