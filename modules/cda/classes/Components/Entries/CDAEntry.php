<?php

/**
 * @package Ox\Interop\Cda\Components\Entries
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Cda\Components\Entries;

use Ox\Interop\Cda\CCDAClasseBase;
use Ox\Interop\Cda\CCDAClasseCda;
use Ox\Interop\Cda\CCDADocTools;
use Ox\Interop\Cda\CCDAFactory;
use Ox\Interop\Cda\Components\CDAComponent;
use Ox\Interop\Cda\Exception\CCDAException;
use Ox\Interop\Cda\Structure\CCDAPOCD_MT000040_Act;
use Ox\Interop\Cda\Structure\CCDAPOCD_MT000040_Entry;
use Ox\Interop\Cda\Structure\CCDAPOCD_MT000040_Observation;
use Ox\Interop\Cda\Structure\CCDAPOCD_MT000040_Organizer;
use Ox\Interop\Cda\Structure\CCDAPOCD_MT000040_Procedure;
use Ox\Interop\Cda\Structure\CCDAPOCD_MT000040_SubstanceAdministration;

/**
 * Base d'une entree CDA : encapsule la construction du contenu clinique
 * et son rattachement a un element Entry.
 *
 * @package Ox\Interop\Cda\Components\Entries
 */
abstract class CDAEntry extends CDAComponent
{
    /** @var CCDAPOCD_MT000040_Entry */
    protected $entry;

    /** @var CCDAClasseCda */
    protected $entry_content;

    /** @var string */
    protected $id;

    /**
     * Prepare l'element Entry et attribue un identifiant unique a l'entree.
     */
    public function __construct(CCDAFactory $factory)
    {
        parent::__construct($factory);

        $this->entry = new CCDAPOCD_MT000040_Entry();
        $this->id    = $this->generateUUID();
    }

    /**
     * Construit le contenu puis le positionne sur l'element Entry selon son type.
     *
     * @return CCDAPOCD_MT000040_Entry
     * @throws CCDAException
     */
    final public function buildEntry(): CCDAPOCD_MT000040_Entry
    {
        $entryElement = $this->entry;
        $content      = $this->entry_content = $this->build();
        $contentClass = get_class($content);

        if ($contentClass === CCDAPOCD_MT000040_Act::class) {
            /** @var CCDAPOCD_MT000040_Act $content */
            $entryElement->setAct($content);
        } elseif ($contentClass === CCDAPOCD_MT000040_SubstanceAdministration::class) {
            /** @var CCDAPOCD_MT000040_SubstanceAdministration $content */
            $entryElement->setSubstanceAdministration($content);
        } elseif ($contentClass === CCDAPOCD_MT000040_Procedure::class) {
            /** @var CCDAPOCD_MT000040_Procedure $content */
            $entryElement->setProcedure($content);
        } elseif ($contentClass === CCDAPOCD_MT000040_Observation::class) {
            /** @var CCDAPOCD_MT000040_Observation $content */
            $entryElement->setObservation($content);
        } elseif ($contentClass === CCDAPOCD_MT000040_Organizer::class) {
            /** @var CCDAPOCD_MT000040_Organizer $content */
            $entryElement->setOrganizer($content);
        } else {
            throw new CCDAException(
                "class '$contentClass' not supported, you should be implement it in CDAEntry::buildEntry"
            );
        }

        return $entryElement;
    }

    /**
     * Assemble le contenu de l'entree : identifiants de template, id, puis contenu specifique.
     *
     * @return CCDAClasseCda
     */
    public function build(): CCDAClasseBase
    {
        $content = $this->entry_content;

        $this->setTemplateIds($content);
        $this->setId($content);
        $this->buildContent($content);

        return $this->entry_content;
    }

    /**
     * Remplit le contenu specifique de l'entree (delegue aux sous-classes).
     *
     * @param CCDAClasseCda $entry_content
     *
     * @return void
     */
    abstract protected function buildContent(CCDAClasseCda $entry_content): void;

    /**
     * Applique les identifiants de template sur le contenu.
     */
    protected function setTemplateIds(CCDAClasseCda $entry_content): void
    {
        CCDADocTools::addTemplatesId($entry_content, $this->getTemplateIds());
    }

    /**
     * Applique l'identifiant unique sur le contenu lorsque celui-ci l'accepte.
     *
     * @param CCDAClasseCda $entry_content
     */
    protected function setId(CCDAClasseCda $entry_content): void
    {
        if (!method_exists($entry_content, 'appendId')) {
            return;
        }

        CCDADocTools::setId($entry_content, $this->id);
    }
}
