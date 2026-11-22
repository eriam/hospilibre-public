<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\DocumentReference\Profiles\ANS;

use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Delegated\DelegatedObjectMapperInterface;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeCodeableConcept;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Profiles\CFHIRANS;
use Ox\Interop\Fhir\Resources\R4\DocumentReference\CFHIRResourceDocumentReference;
use Ox\Interop\Fhir\Resources\R4\DocumentReference\Mapper\ANS\DocumentReferenceCdLDocumentItem;
use Ox\Interop\Fhir\Resources\R4\DocumentReference\Mapper\ANS\DocumentReferenceCdLNote;
use Ox\Interop\InteropResources\valueset\CANSValueSet;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\Files\CFile;
use Hospilibre\System\CNote;

/**
 * FIHR document reference resource
 */
class CFHIRResourceDocumentReferenceCdL extends CFHIRResourceDocumentReference
{
    // constants
    /** @var string */
    public const PROFILE_TYPE = 'CdL_DocumentReferenceCdL';

    /** @var string */
    public const PROFILE_CLASS = CFHIRANS::class;

    /** @var null not used in this profile */
    public $docStatus;

    /** @var null not used in this profile */
    public $authenticator;

    /** @var null not used in this profile */
    public $custodian;

    /** @var CCompteRendu|CFile|CNote */
    public $object;

    protected function setMapperOld(CStoredObject $object): ?DelegatedObjectMapperInterface
    {
        switch (get_class($object)) {
            case CFile::class:
            case CCompteRendu::class:
                $object_mapper = DocumentReferenceCdLDocumentItem::class;
                break;

            case CNote::class:
                $object_mapper = DocumentReferenceCdLNote::class;
                break;
            default:
                throw new CFHIRExceptionNotSupported('Mapping object not supported');
        }

        return new $object_mapper();
    }

    /**
     * @return string|null
     */
    public function getClass(): ?string
    {
        return CCompteRendu::class;
    }

    protected function mapDocStatus(): void
    {
        // Forbidden for this profile
    }

    protected function mapAuthenticator(): void
    {
        // Forbidden for this profile
    }

    protected function mapCustodian(): void
    {
        // Forbidden for this profile
    }

    protected function mapSecurityLabel(): void
    {
        // on garde que le premier
        if ($securities = $this->object_mapping->mapSecurityLabel()) {
            $this->securityLabel = reset($securities);
        }
    }

    /**
     * @param CFHIRDataTypeCodeableConcept $type
     */
    public function setType(string $code): void
    {
        $values               = CANSValueSet::loadEntries('typeNoteCdL', $code);
        $values['codeSystem'] = "https://mos.esante.gouv.fr/NOS/TRE_R234-TypeNote/FHIR/TRE-R234-TypeNote";

        $this->type = CFHIRDataTypeCodeableConcept::fromValues($values);
    }
}
