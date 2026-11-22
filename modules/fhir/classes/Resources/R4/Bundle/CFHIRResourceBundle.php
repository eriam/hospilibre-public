<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\Bundle;

use Ox\Core\CMbSecurity;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Contracts\Resources\ResourceBundleInterface;
use Ox\Interop\Fhir\Controllers\CFHIRController;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUnsignedInt;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle\CFHIRDataTypeBundleEntry;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle\CFHIRDataTypeBundleLink;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeSignature;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRExceptionNotSupported;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionCreate;
use Ox\Interop\Fhir\Interactions\CFHIRInteractionUpdate;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Utilities\CCapabilitiesResource;

/**
 * FIHR patient resource
 */
class CFHIRResourceBundle extends CFHIRResource implements ResourceBundleInterface
{
    /** @var string */
    public const TYPE_BATCH = "batch";
    /** @var string */
    public const TYPE_TRANSACTION = "transaction";
    /** @var string */
    public const TYPE_DOCUMENT = "document";
    /** @var string */
    public const TYPE_COLLECTION = "collection";
    /** @var string */
    public const TYPE_SEARCHSET = "searchset";
    /** @var string */
    public const TYPE_HISTORY = "history";
    /** @var string */
    public const TYPE_BATCH_RESPONSE = "batch-response";
    /** @var string */
    public const TYPE_TRANSACTION_RESPONSE = "transaction-response";
    /** @var string */
    public const TYPE_MESSAGE = "message";

    // constants
    /** @var string */
    public const RESOURCE_TYPE = 'Bundle';

    /** @var string */
    public const VERSION_NORMATIVE = '4.0';

    // attributes
    /** @var CFHIRDataTypeIdentifier */
    public $identifier;

    /** @var CFHIRDataTypeCode */
    public $type;

    /** @var CFHIRDataTypeInstant */
    public $timestamp;

    /** @var CFHIRDataTypeUnsignedInt */
    public $total;

    /** @var CFHIRDataTypeBundleLink[] */
    public $link = [];

    /** @var CFHIRDataTypeBundleEntry[] */
    public $entry = [];

    /** @var CFHIRDataTypeSignature */
    public $signature;

    /**
     * @inheritdoc
     * @deprecated todo remove
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        // type
        $this->type = new CFHIRDataTypeCode($event->type);
    }

    /**
     * @return CFHIRDataTypeIdentifier
     */
    public function getIdentifier(): CFHIRDataTypeIdentifier
    {
        return $this->identifier;
    }

    /**
     * @return CFHIRDataTypeCode
     */
    public function getType(): ?CFHIRDataTypeCode
    {
        return $this->type;
    }

    /**
     * @return CFHIRDataTypeInstant
     */
    public function getTimestamp(): CFHIRDataTypeInstant
    {
        return $this->timestamp;
    }

    /**
     * @return CFHIRDataTypeUnsignedInt
     */
    public function getTotal(): CFHIRDataTypeUnsignedInt
    {
        return $this->total;
    }

    /**
     * @return CFHIRDataTypeBundleLink[]
     */
    public function getLink(): array
    {
        return $this->link;
    }

    /**
     * @return CFHIRDataTypeBundleEntry[]
     */
    public function getEntry(): array
    {
        return $this->entry;
    }

    /**
     * @return CFHIRDataTypeSignature
     */
    public function getSignature(): CFHIRDataTypeSignature
    {
        return $this->signature;
    }

    /**
     * @param CFHIRDataTypeIdentifier $identifier
     *
     * @return CFHIRResourceBundle
     */
    public function setIdentifier(CFHIRDataTypeIdentifier $identifier): CFHIRResourceBundle
    {
        $this->identifier = $identifier;

        return $this;
    }

    /**
     * @param CFHIRDataTypeCode $type
     *
     * @return CFHIRResourceBundle
     */
    public function setType(CFHIRDataTypeCode $type): CFHIRResourceBundle
    {
        $available_types = [
            self::TYPE_BATCH,
            self::TYPE_BATCH_RESPONSE,
            self::TYPE_COLLECTION,
            self::TYPE_DOCUMENT,
            self::TYPE_HISTORY,
            self::TYPE_MESSAGE,
            self::TYPE_SEARCHSET,
            self::TYPE_TRANSACTION,
            self::TYPE_TRANSACTION_RESPONSE,
        ];

        $value_type = $type->getValue();
        if (!in_array($value_type, $available_types, true)) {
            throw new CFHIRExceptionNotSupported("This type : $value_type is not supported type for Bundle resource");
        }

        $this->type = $type;

        return $this;
    }

    /**
     * @param CFHIRDataTypeInstant $timestamp
     *
     * @return CFHIRResourceBundle
     */
    public function setTimestamp(CFHIRDataTypeInstant $timestamp): CFHIRResourceBundle
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * @param CFHIRDataTypeUnsignedInt $total
     *
     * @return CFHIRResourceBundle
     */
    public function setTotal(CFHIRDataTypeUnsignedInt $total): CFHIRResourceBundle
    {
        $this->total = $total;

        return $this;
    }

    /**
     * @param CFHIRDataTypeBundleLink[] $links
     *
     * @return CFHIRResourceBundle
     */
    public function setLink(array $links): CFHIRResourceBundle
    {
        $this->link = array_filter($links, function ($link) {
            return $link instanceof CFHIRDataTypeBundleLink;
        });

        return $this;
    }

    /**
     * @param CFHIRDataTypeBundleLink|null $link
     *
     * @return CFHIRDataTypeBundleLink
     */
    public function addLink(CFHIRDataTypeBundleLink $link = null): CFHIRDataTypeBundleLink
    {
        if (!$link) {
            $link = new CFHIRDataTypeBundleLink();
        }

        $this->link[] = $link;

        return $link;
    }

    /**
     * @param CFHIRDataTypeBundleEntry|null $entry
     *
     * @return CFHIRDataTypeBundleEntry
     */
    public function addEntry(CFHIRDataTypeBundleEntry $entry = null): CFHIRDataTypeBundleEntry
    {
        if (!$entry) {
            $entry = new CFHIRDataTypeBundleEntry();
        }

        $this->entry[] = $entry;

        return $entry;
    }

    /**
     * @param CFHIRDataTypeBundleEntry[] $entries
     *
     * @return CFHIRResourceBundle
     */
    public function setEntry(array $entries): CFHIRResourceBundle
    {
        $this->entry = array_filter($entries, function ($entry) {
            return $entry instanceof CFHIRDataTypeBundleEntry;
        });

        return $this;
    }

    /**
     * @param CFHIRDataTypeSignature $signature
     *
     * @return CFHIRResourceBundle
     */
    public function setSignature(CFHIRDataTypeSignature $signature): CFHIRResourceBundle
    {
        $this->signature = $signature;

        return $this;
    }

    /**
     * @return CCapabilitiesResource
     */
    protected function generateCapabilities(): CCapabilitiesResource
    {
        return (parent::generateCapabilities())
            ->addInteractions(
                [
                    CFHIRInteractionCreate::NAME,
                    CFHIRInteractionUpdate::NAME,
                ]
            );
    }

    /**
     * @param CStoredObject|null $object
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *
     * @return CFHIRResource
     */
    public function mapFrom(CStoredObject $object = null): CFHIRResource
    {
        return parent::mapFrom(new CStoredObject());
    }

    /**
     * @param CFHIRResource $resource
     *
     * @return $this
     */
    public function addResource(CFHIRResource $resource): self
    {
        $fullUrl = CFHIRController::getUrl(
            "fhir_read",
            [
                'resource'    => $resource->getResourceType(),
                'resource_id' => $resource->id->getValue(),
            ]
        );

        $entry = CFHIRDataTypeBundleEntry::build(
            [
                'fullUrl'  => $fullUrl,
                'resource' => $resource,
            ]
        );

        $this->entry[] = $entry;

        return $this;
    }

    /**
     * @param CStoredObject $object
     *
     * @return string
     */
    protected function getInternalId(?CStoredObject $object = null): string
    {
        return CMbSecurity::generateUUID();
    }
}
