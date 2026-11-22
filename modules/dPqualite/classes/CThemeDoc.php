<?php
/**
 * @package Hospilibre\Qualite
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Qualite;

use Ox\Core\CMbObject;
use Hospilibre\Etablissement\CGroups;

/**
 * ThÎ¸mes des documents qualité
 * Class CThemeDoc
 */
class CThemeDoc extends CMbObject {
  // DB Table key
  public $doc_theme_id;

  // DB Fields
  public $group_id;
  public $nom;

  // Fwd refs
  /** @var CGroups */
  public $_ref_group;

  /**
   * @see parent::getSpec()
   */
  function getSpec() {
    $spec        = parent::getSpec();
    $spec->table = 'doc_themes';
    $spec->key   = 'doc_theme_id';

    return $spec;
  }

  /**
   * @see parent::getProps()
   */
  function getProps() {
    $specs             = parent::getProps();
    $specs["group_id"] = "ref class|CGroups back|themes_qualite";
    $specs["nom"]      = "str notNull maxLength|50";

    return $specs;
  }

  /**
   * @see parent::updateFormFields()
   */
  function updateFormFields() {
    parent::updateFormFields();
    $this->_view = $this->nom;
  }

  function loadRefGroup() {
    if (!$this->_ref_group) {
      $this->_ref_group = new CGroups();
      $this->_ref_group->load($this->group_id);
    }
  }

  /**
   * @see parent::loadRefsFwd()
   */
  function loadRefsFwd() {
    $this->loadRefGroup();
  }
}
