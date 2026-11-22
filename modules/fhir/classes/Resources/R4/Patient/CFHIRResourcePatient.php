<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Patient;

use DOMDocument;
use DOMElement;
use DOMNode;
use Exception;
use Ox\Core\Api\Request\RequestFilter;
use Ox\Core\CAppUI;
use Ox\Core\CMbArray;
use Ox\Core\CMbObject;
use Ox\Core\CStoredObject;
use Ox\Core\Module\CModule;
use Ox\Interop\Eai\CDomain;
use Ox\Interop\Eai\CMbOID;
use Ox\Interop\Fhir\CFHIRXPath;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Contracts\Mapping\R4\PatientMappingInterface;
use Ox\Interop\Fhir\Contracts\Resources\ResourcePatientInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeBoolean;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDate;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInteger;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientCommunication;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientContact;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Patient\CFHIRDataTypePatientLink;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAddress;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeAttachment;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeContactPoint;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeHumanName;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeReference;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Exception\CFHIRExceptionBadRequest;
use Ox\Interop\Fhir\Exception\CFHIRExceptionEmptyResultSet;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotFound;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionDelete;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionHistory;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionRead;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionSearch;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Operations\CFHIROperationIhePix;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\R4\Patient\Mapper\Patient;
use Ox\Interop\Fhir\Resources\R4\Practitioner\CFHIRResourcePractitioner;
use Ox\Interop\Fhir\Response\CFHIRResponse;
use Ox\Interop\Fhir\Serializers\CFHIRParser;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameter;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterDate;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterString;
use Ox\Interop\Fhir\Utilities\SearchParameters\SearchParameterToken;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Patients\CPatient;
use Hospilibre\Sante400\CIdSante400;

/**
 * FHIR patient resource
 */
class CFHIRResourcePatient extends CFHIRDomainResource implements ResourcePatientInterface
{
    /** @var string */
    public const VERSION_NORMATIVE = '4.0';

    /** @var string */
    public const RESOURCE_TYPE = "Patient";

    // attributes
    /** @var CFHIRDataTypeBoolean */
    public $active;

    /** @var CFHIRDataTypeHumanName[] */
    public $name;

    /** @var CFHIRDataTypeContactPoint[] */
    public $telecom;

    /** @var CFHIRDataTypeCode */
    public $gender;

    /** @var CFHIRDataTypeDate */
    public $birthDate;

    /** @var CFHIRDataTypeBoolean|CFHIRDataTypeDateTime */
    public $deceased;

    /** @var CFHIRDataTypeAddress[] */
    public $address;

    /** @var CFHIRDataTypeCodeableConcept */
    public $maritalStatus;

    /** @var CFHIRDataTypeBoolean|CFHIRDataTypeInteger */
    public $multipleBirth;

    /** @var CFHIRDataTypeAttachment[] */
    public $photo;

    /** @var CFHIRDataTypePatientContact[] */
    public $contact;

    /** @var CFHIRDataTypePatientCommunication[] */
    public $communication;

    /** @var CFHIRDataTypeReference[] */
    public $generalPractitioner;

    /** @var CFHIRDataTypeReference */
    public $managingOrganization;

    /** @var CFHIRDataTypePatientLink[] */
    public $link;

    /** @var CPatient */
    protected $object;

    /** @var PatientMappingInterface */
    protected $object_mapping;

    /**
     * return CPatient
     */
    public function getClass(): ?string
    {
        return CPatient::class;
    }

    /**
     * @return CCapabilitiesResource
     */
    public function generateCapabilities(): CCapabilitiesResource
    {
        return (parent::generateCapabilities())
            ->addInteractions(
                [
                    CFHIRInteractionCreate::NAME,
                    CFHIRInteractionRead::NAME,
                    CFHIRInteractionSearch::NAME,
                    CFHIRInteractionUpdate::NAME,
                    CFHIRInteractionDelete::NAME,
                    CFHIRInteractionHistory::NAME,
                    CFHIROperationIhePix::NAME,
                ]
            )
            ->addSearchAttributes(
                [
                    new SearchParameterString('family'),
                    new SearchParameterString('given'),
                    new SearchParameterDate('birthdate'),
                    new SearchParameterToken('email'),
                    new SearchParameterString('address'),
                    new SearchParameterString('address-city'),
                    new SearchParameterString('address-postalcode'),
                    new SearchParameterToken('gender'),
                ]
            );
    }

    /**
     * @inheritdoc
     *
     * @param CStoredObject $object
     */
    final protected function map(CStoredObject $object): void
    {
        if ($this->object_mapping) {
            if (!$object->_id) {
                return;
            }

            parent::map($object);

            // active
            $this->mapActive();

            // name
            $this->mapName();

            // telecom
            $this->mapTelecom();

            // gender
            $this->mapGender();

            // birthdate
            $this->mapBirthDate();

            // deceased
            $this->mapDeceased();

            // address
            $this->mapAddress();

            // maritalStatus
            $this->mapMaritalStatus();

            // multipleBirth
            $this->mapMultipleBirth();

            // photo
            $this->mapPhoto();

            // contact
            $this->mapContact();

            // communication
            $this->mapCommunication();

            // generalPractitioner
            $this->mapGeneralPractitioner();

            // managingOrganization
            $this->mapManagingOrganization();

            // link
            $this->mapLink();
        }
    }

    /**
     * @inheritdoc
     * @throws Exception
     * @throws CFHIRException
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CPatient) {
            throw new CFHIRException("Object is not a patient");
        }

        $this->map($object);
    }

    /**
     * @param CStoredObject $object
     *
     * @return DelegatedObjectMapperInterface
     */
    protected function setMapperOld(CStoredObject $object): DelegatedObjectMapperInterface
    {
        $mapping_object = Patient::class;

        return new $mapping_object();
    }


    /**
     * Map property active
     */
    protected function mapActive(): void
    {
        $this->active = $this->object_mapping->mapActive();
    }

    /**
     * Map property name
     */
    protected function mapName(): void
    {
        $this->name = $this->object_mapping->mapName();
    }

    /**
     * Map property telecom
     */
    protected function mapTelecom(): void
    {
        $this->telecom = $this->object_mapping->mapTelecom();
    }

    /**
     * Map property gender
     */
    protected function mapGender(): void
    {
        $this->gender = $this->object_mapping->mapGender();
    }

    /**
     * Map property birthDate
     */
    protected function mapBirthDate(): void
    {
        $this->birthDate = $this->object_mapping->mapBirthDate();
    }

    /**
     * Map property deceased
     */
    protected function mapDeceased(): void
    {
        $this->deceased = $this->object_mapping->mapDeceased();
    }

    /**
     * Map property address
     */
    protected function mapAddress(): void
    {
        $this->address = $this->object_mapping->mapAddress();
    }

    /**
     * Map property maritalStatus
     */
    protected function mapMaritalStatus(): void
    {
        $this->maritalStatus = $this->object_mapping->mapMaritalStatus();
    }

    /**
     * Map property multipleBirth
     */
    protected function mapMultipleBirth(): void
    {
        $this->multipleBirth = $this->object_mapping->mapMultipleBirth();
    }

    /**
     * Map property photo
     */
    protected function mapPhoto(): void
    {
        $this->photo = $this->object_mapping->mapPhoto();
    }

    /**
     * Map property contact
     */
    protected function mapContact(): void
    {
        $this->contact = $this->object_mapping->mapContact();
    }

    /**
     * Map property communication
     */
    protected function mapCommunication(): void
    {
        $this->communication = $this->object_mapping->mapCommunication();
    }

    /**
     * Map property generalPractitioner
     *
     * @param string $resource_class
     */
    protected function mapGeneralPractitioner(): void
    {
        $this->generalPractitioner = $this->object_mapping->mapGeneralPractitioner(CFHIRResourcePractitioner::class);
    }

    /**
     * Map property managingOrganization
     */
    protected function mapManagingOrganization(): void
    {
        $this->managingOrganization = $this->object_mapping->mapManagingOrganization();
    }

    /**
     * Map property link
     * @throws Exception
     */
    protected function mapLink(): void
    {
        $this->link = $this->object_mapping->mapLink();
    }

    /**
     * @param array       $data
     * @param string      $limit
     *
     * @param string|null $offset
     *
     * @return array
     * @throws Exception
     */
    protected function specificSearch(?array $data, string $limit, ?string $offset = null): array
    {
        // Identifiers
        if ($this->getParameter('identifier')) {
            $identifiers       = [];
            $patient_to_return = null;
            /** @var SearchParameter $param_identifier */
            foreach ($this->getParameter('identifier') as $param_identifier) {
                if (!str_starts_with($param_identifier->getValue(), "urn:oid:")) {
                    continue;
                }

                [$system, $value] = explode("|", substr($param_identifier->getValue(), 8));

                if ($value) {
                    $patient_to_return = $this->findPatientByIdentifier($system, $value);
                } elseif ($system) {
                    $identifiers[] = $system;
                }
            }

            if ($patient_to_return) {
                $patient_to_return->_returned_oids = $identifiers;

                return [[$patient_to_return], 1];
            }
        }

        /** @var CPatient $object */
        $object   = $this->getObject();
        $ljoin    = [];
        $group_by = null;
        if (CModule::getActive('appFine')) {
            $where = $this->getWhereAppFine($object, $where, $ljoin, $group_by);
        } else {
            $where = $this->getWhere($object, $ljoin);
        }

        $list  = $object->loadList($where, "patient_id", $limit, $group_by, $ljoin);
        $total = $object->countList($where, $group_by, $ljoin);

        return [$list, $total];
    }

    /**
     * Perform a search query based on the current object data
     *
     * @return CFHIRResponse
     * @throws CFHIRExceptionBadRequest
     * @throws CFHIRExceptionEmptyResultSet
     * @throws CFHIRExceptionNotFound|Exception
     */
    public function interactionSearchAppFine(): CFHIRResponse
    {
        $data = [];
        /** @var CPatient $object */
        $object = $this->getObject();

        $limit = isset($data["_count"]) ? (int)$data["_count"][0][1] : null;
        if ($limit) {
            // Limite imposée Î° 50
            $limit = min($limit, 50);
        } else {
            $limit = 10;
        }

        $offset = isset($data["_offset"]) ? (int)$data["_offset"][0][1] : null;

        // _id
        if (isset($data["_id"]) && count($data["_id"])) {
            $object->load($data["_id"][0][1]);

            $object = $this->outWithPerm($object);

            if ($object) {
                /*return [
                    $object,
                ];*/
            }

            throw new CFHIRExceptionNotFound("Could not find patient #" . $data["_id"][0][1]);
        }

        $where = [];
        $ljoin = [];
        $group_by = null;

        $this->getWhereAppFine($object, $where, $ljoin, $group_by);

        $list  = $object->loadList(
            $where,
            "patient_id",
            $offset ? "$offset, $limit" : $limit,
            'patients.patient_id',
            $ljoin
        );
        $total = $object->countList($where, null, $ljoin);

        $result = [
            "list"     => $list,
            "total"    => $total,
            "step"     => $limit,
            "offset"   => $offset,
            "paginate" => $total > $this->getLimit(),
        ];

        return $this->interaction->handleResult($this, $result);
    }

    /**
     * Finds a patient by his identifier
     *
     * @param string $system Identifying system (OID)
     * @param string $value  Identifier value
     *
     * @return CMbObject
     * @throws CFHIRExceptionBadRequest
     * @throws CFHIRExceptionEmptyResultSet
     * @throws Exception
     */
    public function findPatientByIdentifier(string $system, string $value): CMbObject
    {
        $domain      = new CDomain();
        $domain->OID = $system;
        $domain->loadMatchingObject();

        if (!$domain->_id) {
            throw new CFHIRExceptionBadRequest("sourceIdentifier Assigning Authority not found");
        }

        $idex = CIdSante400::getMatch("CPatient", $domain->tag, $value);

        if (!$idex->_id) {
            throw new CFHIRExceptionEmptyResultSet("Unknown Patient identified by '$system|$value'");
        }

        return $idex->loadTargetObject();
    }

    /**
     * Makes a WHERE clause from data
     *
     * @param CPatient $patient Patient to make the query for
     * @param array    $where   WHERE clause
     * @param array    $ljoin   LEFT JOIN clause
     *
     * @return array
     * @throws \Ox\Core\Api\Exceptions\ApiRequestException
     */
    protected function getWhere(CStoredObject $patient, array &$ljoin): array
    {
        $where = [];
        $ds    = $patient->getDS();
        if (CAppUI::isCabinet()) {
            $function_id          = CMediusers::get()->function_id;
            $where["function_id"] = "= '$function_id'";
        }

        if ($family_param = $this->getParameter('family')) {
            $whereOr   = [];
            $whereOr[] = $family_param->getSql('nom', $ds);
            $whereOr[] = $family_param->getSql('nom_jeune_fille', $ds);

            $where[] = '(' . implode(" OR ", $whereOr) . ")";
        }

        /** @var SearchParameter[] $prenoms_params */
        if ($prenoms_params = $this->getParameters('given')) {
            /** @var SearchParameter $prenom_params */
            $prenom_params = CMbArray::extract($prenoms_params, 0);
            $where[]       = $prenom_params->getSql('prenom', $ds);

            foreach ($prenoms_params as $search_parameter) {
                $where[] = $search_parameter->getSql('prenoms', $ds);
            }
        }

        // Birthdate
        if ($parameter_birthday = $this->getParameter('birthdate')) {
            $where[] = $parameter_birthday->getSql('naissance', $ds);
        }

        // Gender
        if ($gender_parameter = $this->getParameter('gender')) {
            $genger  = $gender_parameter->getValue() === "female" ? 'f' : 'm';
            $where[] = $gender_parameter->getSql('sexe', $ds, $genger);
        }

        // City
        if ($city_parameter = $this->getParameter('address-city')) {
            $where[] = $city_parameter->getSql('ville', $ds);
        }

        // Postal code
        if ($postal_parameter = $this->getParameter('address-postalcode')) {
            $where[] = $postal_parameter->getSql('cp', $ds);
        }

        // Address
        if ($address_parameter = $this->getParameter('address')) {
            $where[] = $address_parameter->getSql('adresse', $ds);
        }

        // Identifiers
        if (isset($patient->_returned_oids)) {
            $i = 1;
            foreach ($patient->_returned_oids as $_oid) {
                $domain      = new CDomain();
                $domain->OID = $_oid;
                if (!$domain->loadMatchingObject()) {
                    continue;
                }

                $ljoin[20 + $i] = "id_sante400 AS id$i ON id$i.object_id = patients.patient_id";
                $where[]        = $ds->prepare("id$i.tag = %", $domain->tag);

                $i++;
            }
        }

        return $where;
    }

    /**
     * Makes a WHERE clause from data
     *
     * @param CPatient $patient Patient to make the query for
     * @param array    $where   WHERE clause
     * @param array    $ljoin   LEFT JOIN clause
     *
     * @return array
     */
    private function getWhereAppFine(CPatient $patient, &$where, &$ljoin, &$group_by)
    {
        $ds = $patient->getDS();

        // Last name
        if ($family_param = $this->getParameter('family')) {
            $where[] = $family_param->getSql('nom', $ds);
        }

        // First name
        if ($given_param = $this->getParameter('given')) {
            $where[] = $given_param->getSql('prenom', $ds);
        }

        // Birthdate
        if ($parameter_birthday = $this->getParameter('birthdate')) {
            $where[] = $parameter_birthday->getSql('naissance', $ds);
        }

        // email ==> username
        if ($email_param = $this->getParameter('email')) {
            $email_param->setOperator(RequestFilter::FILTER_EQUAL);
            $where[] = $email_param->getSql('users.user_username', $ds);
        }

        $ljoin["patient_user"] = " patients.patient_id = patient_user.patient_id";
        $ljoin["users"]        = " patient_user.user_id = users.user_id";
        $group_by              = 'patient_user.patient_id';

        return $where;
    }

    static function getPatientsFromXML(DOMDocument $dom)
    {
        $xpath = new CFHIRXPath($dom);

        $patients = [];
        switch ($dom->documentElement->nodeName) {
            case "Patient":
                $patient                                 = $dom->documentElement;
                $mbPatient                               = new CPatient();
                $mbPatient                               = self::mapPatientFromXML($mbPatient, $patient);
                $patients[$mbPatient->_fhir_resource_id] = $mbPatient;
                break;
            case "Bundle":
                $entries = $xpath->query("//fhir:entry");
                foreach ($entries as $_entry) {
                    $patient = $xpath->queryUniqueNode("fhir:resource/fhir:Patient", $_entry);

                    $mbPatient                               = new CPatient();
                    $mbPatient                               = self::mapPatientFromXML($mbPatient, $patient);
                    $patients[$mbPatient->_fhir_resource_id] = $mbPatient;
                }
                break;
            default:
        }

        return $patients;
    }

    /**
     * Get node patient in response for external url
     *
     * @param string $response response
     *
     * @return DOMNode
     * @throws CFHIRException
     */
    static function getNodePatient($response)
    {
        // Réponse en JSON
        if (strpos($response, "<") === false) {
            $response = CFHIRParser::parse($response, 'xml');
        }

        $dom          = $response->getDom();
        $patient_node = $dom->documentElement;
        if (!$patient_node || $patient_node->nodeName !== "Patient") {
            throw  new CFHIRException(
                "Impossible to retrieve patient with external url provide in DocumentReference.subject"
            );
        }

        return $patient_node;
    }

    /**
     * Check if patient is known in MB with identifiers
     *
     * @param CPatient $patient patient
     *
     * @return CPatient
     * @throws CFHIRException
     */
    static function patientIsKnow(CPatient $patient)
    {
        $identifiers = $mb_patient = null;
        foreach ($patient->_identifiers as $_identifier) {
            if ($mb_patient) {
                continue;
            }
            $oid   = str_replace("urn:oid:", "", CMbArray::get($_identifier, "system"));
            $value = CMbArray::get($_identifier, "value");

            if (!$value || !$oid) {
                continue;
            }

            // TODO : Ajouter dans le loadMatching actor_id, actor_class pour savoir quel expéditeur nous envoie la requete et récupérer le bon domain
            $domain      = new CDomain();
            $domain->OID = $oid;
            $domain->loadMatchingObject();

            if (!$domain->_id) {
                continue;
            }

            $idex               = new CIdSante400();
            $idex->object_class = "CPatient";
            $idex->id400        = $value;
            $idex->tag          = $domain->tag;
            $idex->loadMatchingObject();

            // Patient retrouvé par son IPP
            if ($idex->_id) {
                $mb_patient = $idex->loadTargetObject();

                if ($mb_patient && $mb_patient->_id && $mb_patient->_class == "CPatient") {
                    /** @var CPatient $mb_patient */
                    $mb_patient->loadIPP();

                    return $mb_patient;
                }
            }

            $identifiers = $identifiers . "$oid|$value ,";
        }

        // Recherche du patient par nom/prenom/naissance
        if (!$mb_patient) {
            $patient_found            = new CPatient();
            $patient_found->nom       = $patient->nom;
            $patient_found->prenom    = $patient->prenom;
            $patient_found->naissance = $patient->naissance;

            $count_patients = $patient_found->countMatchingList();

            if ($count_patients == 0 || $count_patients > 1) {
                throw new CFHIRException(
                    "Patient '$patient_found->nom $patient_found->prenom' born '$patient_found->naissance' matchs with any or multiple patients."
                );
            }

            $patient_found->loadMatchingObject();

            if ($patient_found->_id) {
                return $patient_found;
            }
        }

        $identifiers = rtrim($identifiers, ",");

        throw new CFHIRException(
            "DocumentReference.subject Error : Patient not found with list identifiers : $identifiers and with first name/last name/birth date."
        );
    }

    /**
     * @inheritdoc
     *
     * @param CPatient $object
     *
     * @throws \Exception
     */
    static function mapPatientFromXML(CPatient $object, DOMNode $node)
    {
        $xpath = new CFHIRXPath($node->ownerDocument);

        $object->_fhir_resource_id = $xpath->getAttributeValue("fhir:id", $node);

        $names           = $xpath->query("fhir:name", $node);
        $family_official = $given_official = $family_usual = $given_usual = null;
        foreach ($names as $_name) {
            if ($xpath->getAttributeValue("fhir:use", $_name) === 'official') {
                $family_official = $xpath->getAttributeValue("fhir:family", $_name);
                $given_official  = $xpath->getAttributeValue("fhir:given", $_name);
            }
            if ($xpath->getAttributeValue("fhir:use", $_name) === 'usual') {
                $family_usual = $xpath->getAttributeValue("fhir:family", $_name);
                $given_usual  = $xpath->getAttributeValue("fhir:given", $_name);
            }
        }

        $gender = $xpath->getAttributeValue("fhir:gender", $node) === "female" ? "f" : "m";

        $birthDate = $xpath->getAttributeValue("fhir:birthDate", $node);

        $telecoms = $xpath->query("fhir:telecom", $node);
        $phone    = null;
        $email    = null;
        foreach ($telecoms as $_telecom) {
            switch ($xpath->getAttributeValue("fhir:system", $_telecom)) {
                case "phone":
                    $phone = $xpath->getAttributeValue("fhir:value", $_telecom);
                    break;
                case "email":
                    $email = $xpath->getAttributeValue("fhir:value", $_telecom);
                    break;
                default:
            }
        }

        $address   = $address_line = $postalCode = $city = null;
        $addresses = $xpath->query("fhir:address", $node);
        $address   = null;
        if ($addresses->length >= 1) {
            $address = $addresses->item(0);
        }
        if ($address) {
            $address_lines = $xpath->query("fhir:line", $address);

            $address_line = "";
            /** @var DOMElement $_address_line */
            foreach ($address_lines as $_address_line) {
                $address_line = $address_line . " " . $_address_line->getAttribute("value");
            }

            $postalCode = $xpath->getAttributeValue("fhir:postalCode", $address);
            $city       = $xpath->getAttributeValue("fhir:city", $address);
        }

        $identifiers         = $xpath->query("fhir:identifier", $node);
        $patient_identifiers = [];

        $master_domain = CDomain::getMasterDomainPatient();
        foreach ($identifiers as $_identifier) {
            $system = $xpath->getAttributeValue("fhir:system", $_identifier);
            $value  = $xpath->getAttributeValue("fhir:value", $_identifier);

            $patient_identifiers[] = [
                "system" => $system,
                "value"  => $value,
            ];

            // IPP
            // Suppression du urn:oid: dans le $system si c'est présent
            $system = str_replace("urn:oid:", "", $system);
            if ($master_domain->OID == $system) {
                $object->_IPP = $value;
            }
        }

        $object->nom_jeune_fille = $family_official;
        $object->nom             = $family_usual;
        $object->prenom          = $given_official;
        $object->prenom_usuel    = $given_usual;
        $object->sexe            = $gender;
        $object->naissance       = $birthDate;
        $object->tel             = $phone;
        $object->email           = $email;
        $object->adresse         = $address_line ?: null;
        $object->cp              = $postalCode ?: null;
        $object->ville           = $city ?: null;
        $object->_identifiers    = $patient_identifiers;
        $object->civilite        = "guess";

        $object->updateFormFields();

        return $object;
    }

    /**
     * Check if patient is active on AppFine
     *
     * @param CPatient $object
     * @param DOMNode  $node
     *
     * @return array
     * @throws \Exception
     */
    static function getPatients(DOMDocument $dom)
    {
        $xpath = new CFHIRXPath($dom);

        switch ($dom->documentElement->nodeName) {
            case "Patient":
                return self::getActiveField($dom->documentElement);
            case "Bundle":
                $result = [];

                $entries = $xpath->query("//fhir:entry");
                foreach ($entries as $_entry) {
                    $patient        = $xpath->queryUniqueNode("fhir:resource/fhir:Patient", $_entry);
                    $result_patient = self::getActiveField($patient);

                    if (!CMbArray::get($result_patient, "active")) {
                        return $result_patient;
                    }

                    $result = $result_patient;
                }
                break;
            default:
        }

        return $result;
    }

    /**
     * Get active field in response FHIR
     *
     * @param DOMNode $node node
     *
     * @return array
     */
    public static function getActiveField(DOMNode $node): array
    {
        $xpath = new CFHIRXPath($node->ownerDocument);

        // active
        $active = $xpath->getAttributeValue("fhir:active", $node);

        // try to find intern identifier (with code_system from OX)
        $ox_oid      = CMbOID::getOxOIDRoot();
        $identifiers = $xpath->query('fhir:identifier', $node);
        $patient_id  = null;
        foreach ($identifiers as $identifier) {
            $id_resource = $xpath->getAttributeValue('fhir:value', $identifier);
            $code_system = $xpath->getAttributeValue("fhir:system", $identifier);
            // (urn:oid:)?ox_oid
            if (preg_match("/^(?:urn:oid:)?$ox_oid/", $code_system) && $id_resource) {
                $patient_id = $id_resource;
                break;
            }
        }

        // fallback keep id resource
        if (!$patient_id) {
            $patient_id = $xpath->getAttributeValue("fhir:id", $node);
        }

        return ["patient_id" => $patient_id, "active" => $active && $active == "true" ? true : false];
    }
}
