<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities\BundleBuilder;

use Ox\Core\CMbSecurity;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeId;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeInstant;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUnsignedInt;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle\CFHIRDataTypeBundleEntry;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\Bundle\CFHIRDataTypeBundleLink;
use Ox\Interop\Fhir\Resources\R4\Bundle\CFHIRResourceBundle;

/**
 * Constructeur fluide de ressources Bundle FHIR.
 */
class BundleBuilder
{
    /** @var CFHIRResourceBundle */
    protected $bundle;

    /**
     * Initialise le builder ; crée un Bundle vide si aucun n'est fourni
     * et lui affecte un identifiant UUID s'il n'en possède pas déjà un.
     *
     * @param CFHIRResourceBundle|null $bundle
     */
    public function __construct(CFHIRResourceBundle $bundle = null)
    {
        $target = $bundle ?: new CFHIRResourceBundle();

        $currentId = $target->id;
        if ($currentId === null || !$currentId->getValue()) {
            $target->id = new CFHIRDataTypeId(CMbSecurity::generateUUID());
        }

        $this->bundle = $target;
    }

    /**
     * Ajoute une entrée au Bundle (en crée une vide si nécessaire).
     *
     * @param CFHIRDataTypeBundleEntry|null $entry
     *
     * @return CFHIRDataTypeBundleEntry L'entrée effectivement ajoutée
     */
    public function addEntry(CFHIRDataTypeBundleEntry $entry = null): CFHIRDataTypeBundleEntry
    {
        $entry = $entry ?: new CFHIRDataTypeBundleEntry();

        $this->bundle->addEntry($entry);

        return $entry;
    }

    /**
     * Positionne le type du Bundle.
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType(string $type): self
    {
        $this->bundle->setType(new CFHIRDataTypeCode($type));

        return $this;
    }

    /**
     * Positionne l'horodatage du Bundle.
     *
     * @param string $datetime
     *
     * @return $this
     */
    public function setTimestamp(string $datetime): self
    {
        $this->bundle->setTimestamp(new CFHIRDataTypeInstant($datetime));

        return $this;
    }

    /**
     * Positionne le nombre total de résultats.
     *
     * @param int $total
     *
     * @return $this
     */
    public function setTotal(int $total): self
    {
        $this->bundle->setTotal(new CFHIRDataTypeUnsignedInt($total));

        return $this;
    }

    /**
     * Crée et renseigne un nouveau lien (relation + URL).
     *
     * @return $this
     */
    public function addLink(string $url, string $relation): self
    {
        $this->bundle->addLink()
            ->setRelation($relation)
            ->setUrl($url);

        return $this;
    }

    /**
     * Ajoute un lien déjà construit.
     *
     * @param CFHIRDataTypeBundleLink $link
     *
     * @return $this
     */
    public function addLinkElement(CFHIRDataTypeBundleLink $link): self
    {
        $this->bundle->addLink($link);

        return $this;
    }

    /**
     * Remplace l'ensemble des liens du Bundle.
     *
     * @param CFHIRDataTypeBundleLink[] $link
     *
     * @return $this
     */
    public function setLink(array $link): self
    {
        $this->bundle->setLink($link);

        return $this;
    }

    /**
     * Fabrique un builder de type "transaction".
     *
     * @param CFHIRResourceBundle|null $bundle
     *
     * @return BuilderBatchTransaction
     */
    public static function getBuilderTransaction(CFHIRResourceBundle $bundle = null): BuilderBatchTransaction
    {
        return (new BuilderBatchTransaction($bundle))->setType(CFHIRResourceBundle::TYPE_TRANSACTION);
    }

    /**
     * Fabrique un builder de type "batch".
     *
     * @param CFHIRResourceBundle|null $bundle
     *
     * @return BuilderBatchTransaction
     */
    public static function getBuilderBatch(CFHIRResourceBundle $bundle = null): BuilderBatchTransaction
    {
        return (new BuilderBatchTransaction($bundle))->setType(CFHIRResourceBundle::TYPE_BATCH);
    }

    /**
     * Fabrique un builder de type "collection".
     *
     * @param CFHIRResourceBundle|null $bundle
     *
     * @return BuilderCollection
     */
    public static function getBuilderCollection(CFHIRResourceBundle $bundle = null): BuilderCollection
    {
        return (new BuilderCollection($bundle))->setType(CFHIRResourceBundle::TYPE_COLLECTION);
    }

    /**
     * Fabrique un builder de type "searchset".
     *
     * @param CFHIRResourceBundle|null $bundle
     *
     * @return BuilderCollection
     */
    public static function getBuilderSearchset(CFHIRResourceBundle $bundle = null): BuilderCollection
    {
        return (new BuilderCollection($bundle))->setType(CFHIRResourceBundle::TYPE_SEARCHSET);
    }

    /**
     * Fabrique un builder de type "history".
     */
    public static function getBuilderHistory(CFHIRResourceBundle $bundle = null): BuilderCollection
    {
        return (new BuilderCollection($bundle))->setType(CFHIRResourceBundle::TYPE_HISTORY);
    }

    /**
     * Fabrique un builder de type "batch-response".
     */
    public static function getBuilderBatchResponse(CFHIRResourceBundle $bundle = null): BuilderBatchTransactionResponse
    {
        return (new BuilderBatchTransactionResponse($bundle))->setType(CFHIRResourceBundle::TYPE_BATCH_RESPONSE);
    }

    /**
     * Fabrique un builder de type "transaction-response".
     */
    public static function getBuilderTransactionResponse(CFHIRResourceBundle $bundle = null): BuilderBatchTransactionResponse
    {
        return (new BuilderBatchTransactionResponse($bundle))->setType(CFHIRResourceBundle::TYPE_TRANSACTION_RESPONSE);
    }

    /**
     * Retourne le Bundle construit.
     *
     * @return CFHIRResourceBundle
     */
    public function build(): CFHIRResourceBundle
    {
        return $this->bundle;
    }
}
