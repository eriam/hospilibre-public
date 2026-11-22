<?php

/**
 * @package Hospilibre\Cabinet
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réimplémentation GPL minimale (clean-room) du modèle CSlot : la version
 * d'origine (OXOL, retirée au nettoyage cf. commit 2b22f2e02) mappait déjà la
 * table `slot`, toujours présente en base (introspectée via SHOW COLUMNS,
 * aucune source OXOL copiée). Ce retrait avait laissé
 * CPlageconsult::store()/CConsultation::store() référencer `SlotService`/
 * `CSlot` de façon inconditionnelle, plantant toute création de plage ou de
 * consultation ; reconstruite pour combler ces appels orphelins.
 */

namespace Hospilibre\Cabinet;

use Ox\Core\CMbObject;
use Ox\Core\CMbObjectSpec;

/**
 * Créneau (slot) d'une plage de consultation.
 */
class CSlot extends CMbObject
{
    /** @var int Primary key */
    public $slot_id;

    /** @var int */
    public $plageconsult_id;
    /** @var int|null */
    public $consultation_id;
    /** @var string */
    public $start;
    /** @var string */
    public $end;
    /** @var bool */
    public $overbooked;
    /** @var string */
    public $status;

    /** @var CConsultation */
    public $_ref_consultation;
    /** @var CPlageconsult */
    public $_ref_plageconsult;

    /**
     * @inheritdoc
     */
    public function getSpec(): CMbObjectSpec
    {
        $spec        = parent::getSpec();
        $spec->table = 'slot';
        $spec->key   = 'slot_id';

        return $spec;
    }

    /**
     * @inheritdoc
     */
    public function getProps(): array
    {
        $props = parent::getProps();

        $props["plageconsult_id"] = "ref notNull class|CPlageconsult back|slots cascade";
        $props["consultation_id"] = "ref class|CConsultation back|slots";
        $props["start"]           = "dateTime notNull";
        $props["end"]             = "dateTime notNull";
        $props["overbooked"]      = "bool default|0";
        $props["status"]          = "enum list|busy|free|busy-unavailable|busy-tentative|entered-in-error default|free";

        return $props;
    }

    public function loadRefConsultation(): CConsultation
    {
        return $this->_ref_consultation = $this->loadFwdRef("consultation_id", true);
    }

    public function loadRefPlageconsult(): CPlageconsult
    {
        return $this->_ref_plageconsult = $this->loadFwdRef("plageconsult_id", true);
    }
}
