<?php
/**
 * @package Hospilibre\Soins
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Soins;

use Exception;
use Ox\Core\CMbObject;
use Hospilibre\PlanningOp\CSejour;

/**
 * RDV externe passés et Î° venir des patients du séjour
 */
class CRDVExterne extends CMbObject {
  // DB Table key
  public $rdv_externe_id;

  // DB fields
  public $sejour_id;
  public $libelle;
  public $description;
  public $date_debut;
  public $duree; // en minutes
  public $statut;
  public $commentaire;

  /** @var CSejour */
  public $_ref_sejour;

  /**
   * @inheritDoc
   */
  function getSpec() {
    $spec        = parent::getSpec();
    $spec->table = 'rdv_externe';
    $spec->key   = 'rdv_externe_id';

    return $spec;
  }

  /**
   * @inheritDoc
   */
  function getProps() {
    $props                = parent::getProps();
    $props["sejour_id"]   = "ref notNull class|CSejour back|rdv_externe";
    $props["libelle"]     = "str notNull";
    $props["description"] = "text helped";
    $props["date_debut"]  = "dateTime notNull";
    $props["duree"]       = "num min|0";
    $props["statut"]      = "enum list|encours|realise|annule default|encours";
    $props["commentaire"] = "text";

    return $props;
  }

  /**
   * @inheritDoc
   */
  function updateFormFields() {
    parent::updateFormFields();
  }

  /**
   * @inheritdoc
   */
  function loadAllDocs($params = array()) {
    $this->mapDocs($this, $params);
  }

  /**
   * @inheritDoc
   */
  function loadView() {
    parent::loadView();
  }

  /**
   * @inheritDoc
   */
  function store() {
    return parent::store();
  }

  /**
   * Load sejour
   *
   * @return CSejour
   * @throws Exception
   */
  function loadRefSejour() {
    return $this->_ref_sejour = $this->loadFwdRef("sejour_id", true);
  }
}
