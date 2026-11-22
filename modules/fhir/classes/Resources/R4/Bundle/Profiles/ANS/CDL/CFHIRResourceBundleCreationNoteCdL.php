<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Bundle\Profiles\ANS\CDL;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Profiles\CFHIRANS;
use Ox\Interop\Fhir\Resources\R4\Bundle\CFHIRResourceBundle;
use Ox\Interop\Fhir\Resources\R4\DocumentReference\CFHIRResourceDocumentReference;
use Ox\Interop\Fhir\Resources\R4\Organization\Profiles\InteropSante\CFHIRResourceOrganizationFR;
use Ox\Interop\Fhir\Resources\R4\Patient\Profiles\InteropSante\CFHIRResourcePatientFR;
use Ox\Interop\Fhir\Resources\R4\Practitioner\Profiles\InteropSante\CFHIRResourcePractitionerFR;
use Ox\Interop\Fhir\Resources\R4\PractitionerRole\CFHIRResourcePractitionerRole;
use Ox\Interop\Fhir\Resources\R4\RelatedPerson\CFHIRResourceRelatedPerson;

class CFHIRResourceBundleCreationNoteCdL extends CFHIRResourceBundle
{
    /** @var string */
    public const PROFILE_TYPE = "CdL_BundleCreationNoteCdL";

    /** @var string */
    public const PROFILE_CLASS = CFHIRANS::class;

    /** @var CFHIRResourceDocumentReference */ // todo CFHIRResourceDocumentReferenceCdL
    private $document_reference;

    /** @var CFHIRResourcePatientFR */
    private $patient;

    /** @var CFHIRResourcePractitionerFR */
    private $practitioner;

    /** @var CFHIRResourcePractitionerRole[] */ // todo RASS
    private $practitionerRole;

    /** @var CFHIRResourceOrganizationFR */
    private $organization;

    /** @var CFHIRResourceRelatedPerson */ // todo implementer le FRRelatedPerson de Interop sante
    private $relatedPerson;

    // not implemented CFHIRResourceDevice
    //private $device;

    protected function map(CStoredObject $object): void
    {
        // Document reference
        if ($this->document_reference) {
            $this->addResource($this->document_reference);
        }

        // Practitioner role
        if ($this->practitionerRole) {
            foreach ($this->practitionerRole as $practitioner_role) {
                $this->addResource($practitioner_role);
            }
        }

        // Practitioner
        if ($this->practitioner) {
            $this->addResource($this->practitioner);
        }

        // Patient
        if ($this->patient) {
            $this->addResource($this->patient);
        }

        // Related person
        if ($this->relatedPerson) {
            $this->addResource($this->relatedPerson);
        }

        // Organization
        if ($this->organization) {
            $this->addResource($this->organization);
        }

        parent::map($object);

        $this->type = new CFHIRDataTypeCode('collection');
    }

    /**
     * @param CFHIRResourceDocumentReference $document_reference
     *
     * @return CFHIRResourceBundleCreationNoteCdL
     */
    public function setDocumentReference(CFHIRResourceDocumentReference $document_reference
    ): CFHIRResourceBundleCreationNoteCdL {
        $this->document_reference = $document_reference;

        return $this;
    }

    /**
     * @param CFHIRResourcePatientFR $patient
     *
     * @return CFHIRResourceBundleCreationNoteCdL
     */
    public function setPatient(CFHIRResourcePatientFR $patient): CFHIRResourceBundleCreationNoteCdL
    {
        $this->patient = $patient;

        return $this;
    }

    /**
     * @param CFHIRResourcePractitionerFR $practitioner
     *
     * @return CFHIRResourceBundleCreationNoteCdL
     */
    public function setPractitioner(CFHIRResourcePractitionerFR $practitioner): CFHIRResourceBundleCreationNoteCdL
    {
        $this->practitioner = $practitioner;

        return $this;
    }

    /**
     * @param CFHIRResourcePractitionerRole $practitionerRole
     *
     * @return CFHIRResourceBundleCreationNoteCdL
     */
    public function addPractitionerRole(CFHIRResourcePractitionerRole $practitionerRole
    ): CFHIRResourceBundleCreationNoteCdL {
        $this->practitionerRole[] = $practitionerRole;

        return $this;
    }

    /**
     * @param CFHIRResourceOrganizationFR $organization
     *
     * @return CFHIRResourceBundleCreationNoteCdL
     */
    public function setOrganization(CFHIRResourceOrganizationFR $organization): CFHIRResourceBundleCreationNoteCdL
    {
        $this->organization = $organization;

        return $this;
    }

    /**
     * @param CFHIRResourceRelatedPerson $relatedPerson
     *
     * @return CFHIRResourceBundleCreationNoteCdL
     */
    public function setRelatedPerson(CFHIRResourceRelatedPerson $relatedPerson): CFHIRResourceBundleCreationNoteCdL
    {
        $this->relatedPerson = $relatedPerson;

        return $this;
    }
}
