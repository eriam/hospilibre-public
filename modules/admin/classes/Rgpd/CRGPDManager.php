<?php
/**
 * @package Hospilibre\Admin
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Admin\Rgpd;

use DOMNode;
use DOMNodeList;
use Hospilibre\System\CSourceSMTP;
use Ox\Core\Autoload\IShortNameAutoloadable;
use Ox\Core\CApp;
use Ox\Core\CAppUI;
use Ox\Core\CMbXMLDocument;
use Ox\Core\CMbXPath;
use Ox\Core\Handlers\Facades\HandlerManager;

/**
 * Gestionnaire des traitements RGPD (consentements, notifications, textes).
 */
class CRGPDManager implements IShortNameAutoloadable {
  const EMAIL_SUBJECT     = 'RGPD';
  const PROOF_FILENAME    = 'RGPD.txt';
  const SMTP_SOURCE_NAME  = 'rgpd_consent';
  const SPECIAL_MODEL_NAME = '[RGPD]';

  const DEFAULT_CONF_FILE = 'modules/admin/resources/default_rgpd_conf.xml';

  const ENABLE_MANUAL              = 1;
  const ENABLE_NOTIFICATION        = 2;
  const ENABLE_NOTIFICATION_ACTION = 3;

  const CONFIGURATION_MODES = [
    self::ENABLE_MANUAL,
    self::ENABLE_NOTIFICATION,
    // Desactive (usage de test)
    //self::ENABLE_NOTIFICATION_ACTION,
  ];

  const COMPLIANT_CLASSES = [
    'CPatient', 'CMedecin', 'CCorrespondantPatient', 'CUser',
  ];

  /** @var int Identifiant de CGroups */
  private $group_id;

  /**
   * @param int $group_id
   *
   * @throws CRGPDException
   */
  public function __construct($group_id) {
    if (!$group_id) {
      throw new CRGPDException('common-error-Object %s not found', "CGroups-{$group_id}");
    }

    $this->group_id = $group_id;
  }

  /**
   * Liste des classes prises en charge par le module RGPD.
   *
   * @return array
   */
  public static function getCompliantClasses() {
    return self::COMPLIANT_CLASSES;
  }

  /**
   * Renvoie le niveau de configuration de notification pour la classe fournie.
   *
   * @param string $object_class
   *
   * @return bool|mixed
   */
  private function getNotificationConfiguration($object_class) {
    if (!$object_class) {
      return false;
    }

    if (!in_array($object_class, self::getCompliantClasses())) {
      return false;
    }

    return CAppUI::gconf("admin CRGPDConsent {$object_class} enable", $this->group_id);
  }

  /**
   * Indique si la demande de consentement se gere manuellement.
   *
   * @param string $object_class
   *
   * @return bool
   */
  public function inManualMode($object_class) {
    return $this->getNotificationConfiguration($object_class) >= self::ENABLE_MANUAL;
  }

  /**
   * Indique si la demande de consentement peut etre envoyee.
   *
   * @param string $object_class
   *
   * @return bool
   */
  public function canNotify($object_class) {
    return $this->getNotificationConfiguration($object_class) >= self::ENABLE_NOTIFICATION;
  }

  /**
   * Indique si la demande de consentement embarque des jetons de reponse.
   *
   * @param string $object_class
   *
   * @return bool
   */
  public function canNotifyWithActions($object_class) {
    return $this->getNotificationConfiguration($object_class) == self::ENABLE_NOTIFICATION_ACTION;
  }

  /**
   * Indique si la demande de consentement est active (handler + niveau de conf).
   *
   * @param IRGPDCompliant $object
   *
   * @return bool
   */
  public function isEnabledFor(IRGPDCompliant $object) {
    // Todo: gerer _class de facon explicite
    if (!$this->isHandlerEnabled()) {
      return false;
    }

    return $this->getNotificationConfiguration($object->_class) > 0;
  }

  /**
   * Indique si le handler RGPD est actif.
   *
   * @return bool
   */
  public function isHandlerEnabled() {
    return HandlerManager::isObjectHandlerActive('CRGPDHandler', $this->group_id);
  }

  /**
   * Renvoie le referent RGPD.
   *
   * @return int
   */
  public function getRGPDUserID() {
    return CAppUI::conf('admin CRGPDConsent user_id');
  }

  /**
   * Renvoie la source SMTP dediee a la validation du consentement.
   *
   * @return CSourceSMTP|null
   */
  public function getRGPDSource() {
    $smtp_source       = new CSourceSMTP();
    $smtp_source->name = $this->getRGPDSourceName();

    $smtp_source->loadMatchingObject();

    if ($smtp_source && $smtp_source->_id) {
      return $smtp_source;
    }

    return null;
  }

  /**
   * @return string
   */
  public function getRGPDSourceName() {
    return self::SMTP_SOURCE_NAME;
  }

  /**
   * @return string
   */
  public function getEmailSubject() {
    return self::EMAIL_SUBJECT;
  }

  /**
   * @return string
   */
  public function getProofFileName() {
    return self::PROOF_FILENAME;
  }

  /**
   * @return string
   */
  public function getSpecialModelName() {
    return self::SPECIAL_MODEL_NAME;
  }

  /**
   * Renvoie les textes RGPD par defaut charges depuis le fichier XML.
   *
   * @return array
   */
  static public function getRGPDDefaultConf() {
    $texts = [];

    if (!is_readable(self::DEFAULT_CONF_FILE)) {
      return $texts;
    }

    $document = new CMbXMLDocument();

    if (!$document->load(self::DEFAULT_CONF_FILE)) {
      return $texts;
    }

    $xpath = new CMbXPath($document);

    $nodes = $xpath->query('configuration');

    if (!$nodes instanceof DOMNodeList) {
      return $texts;
    }

    /** @var DOMNode $_node */
    foreach ($nodes as $_node) {
      $context = $_node->getAttribute('context');
      $key     = $_node->getAttribute('key');

      if (!isset($texts[$context])) {
        $texts[$context] = [];
      }

      // Pas de utf8_decode() : le fichier XML est en UTF-8 et la base en utf8mb4. Convertir
      // en ISO-8859-1 produisait des octets invalides pour la colonne de destination
      // (« Incorrect string value: '\xE9es…' »), ce qui interrompait le setup du module admin
      // — et, par dépendance, celui de system. utf8_decode() est de surcroît déprécié
      // depuis PHP 8.2.
      $texts[$context][$key] = trim($_node->nodeValue);
    }

    return $texts;
  }

  /**
   * Renvoie le modele de configuration RGPD.
   *
   * @return array
   */
  static function getRGPDConfigurationModel() {
    static $model = [];

    if ($model) {
      return $model;
    }

    static $classes = [];

    if (!$classes) {
      $classes = self::getCompliantClasses();
    }

    $enable_spec = 'enum list|0|' . implode('|', self::getEnableConfigurations()) . ' default|0 localize';

    $model = array_fill_keys(
      $classes,
      [
        'enable'          => $enable_spec,
        'rgpd_intro'      => 'text',
        'rgpd_data'       => 'text',
        'rgpd_droits'     => 'text',
        'rgpd_contact'    => 'text',
        'rgpd_conclusion' => 'text',
      ]
    );

    // Cas particulier : pas de notification pour les utilisateurs
    if (isset($model['CUser'])) {
      $model['CUser']['enable'] = 'enum list|0|1 default|0 localize';
    }

    return $model;
  }

  /**
   * Renvoie le texte RGPD decoupe en parties pour la classe fournie.
   *
   * @param string $object_class Classe de l'objet
   *
   * @return array
   */
  public function getRGPDText($object_class) {
    if (!$object_class || !in_array($object_class, self::getCompliantClasses())) {
      return [];
    }

    $parts = ['intro', 'data', 'droits', 'contact', 'conclusion'];

    $texts = [];
    foreach ($parts as $_part) {
      $texts[$_part] = CAppUI::gconf("admin CRGPDConsent {$object_class} rgpd_{$_part}", $this->group_id);
    }

    return $texts;
  }

  /**
   * Renvoie les modes de configuration disponibles.
   *
   * @return array
   */
  static private function getEnableConfigurations() {
    return self::CONFIGURATION_MODES;
  }

  /**
   * Renvoie le consentement associe a l'objet donne.
   *
   * @param IRGPDCompliant $object
   * @param int            $tag
   *
   * @return CRGPDConsent|null
   */
  public function getConsentForObject(IRGPDCompliant $object, $tag = CRGPDConsent::TAG_DATA) {
    // Todo: gerer _id de facon explicite
    if (!$object || !$object->_id) {
      return null;
    }

    $consent           = new CRGPDConsent();
    $consent->tag      = $tag;
    $consent->group_id = $this->group_id;
    $consent->setObject($object);

    if ($consent->loadMatchingObject()) {
      return $consent;
    }

    return null;
  }

  /**
   * Initialise un objet CRGPDConsent.
   *
   * @param IRGPDCompliant $object
   * @param int            $tag
   *
   * @return CRGPDConsent
   * @throws CRGPDException
   */
  public function initConsent(IRGPDCompliant $object, $tag = CRGPDConsent::TAG_DATA) {
    $consent           = new CRGPDConsent();
    $consent->tag      = $tag;
    $consent->group_id = $this->group_id;
    $consent->setObject($object);
    $consent->setManager($this);

    return $consent->markAsGenerated();
  }

  /**
   * Renvoie le consentement existant, sinon en cree un nouveau.
   * Todo: gerer les autres TAGS
   *
   * @param IRGPDCompliant $object
   * @param int            $tag
   *
   * @return CRGPDConsent|null
   * @throws CRGPDException
   */
  public function getOrInitConsent(IRGPDCompliant $object, $tag = CRGPDConsent::TAG_DATA) {
    $consent = $this->getConsentForObject($object, $tag);

    if ($consent && $consent->_id) {
      return $consent;
    }

    return $this->initConsent($object, $tag);
  }

  /**
   * Verifie si le consentement a bien ete valide.
   *
   * @param IRGPDCompliant $object
   * @param int            $tag
   *
   * @return bool
   * @throws CRGPDException
   */
  public function checkConsentFor(IRGPDCompliant $object, $tag = CRGPDConsent::TAG_DATA) {
    if (!$object->shouldAskConsent()) {
      return true;
    }

    $consent = $this->getOrInitConsent($object, $tag);

    if (!$consent || !$consent->_id) {
      return false;
    }

    return $consent->isOK();
  }

  /**
   * @param IRGPDCompliant $object
   *
   * @return bool
   */
  public function shouldAskConsentFor(IRGPDCompliant $object) {
    return $object->shouldAskConsent();
  }

  /**
   * @param IRGPDCompliant $object
   *
   * @return bool
   */
  public function canAskConsentFor(IRGPDCompliant $object) {
    return $object->canAskConsent();
  }

  /**
   * @param IRGPDCompliant $object
   *
   * @return void
   * @throws CRGPDException
   */
  public function askConsentFor(IRGPDCompliant $object) {
    if (!$object->shouldAskConsent()) {
      throw new CRGPDException('CRGPD-error-Should not ask consent');
    }

    // Todo: gerer _class de facon explicite
    if (!$this->canNotify($object->_class)) {
      return;
    }

    $consent = $this->getConsentForObject($object);

    if (!$consent || !$consent->_id) {
      $consent = $this->initConsent($object);
    }

    $object->setRGPDConsent($consent);

    // Pas d'envoi synchrone
//    if (!$this->canAskConsent()) {
//      throw new CRGPDException('CRGPD-error-Cannot ask consent');
//    }

//    if (!$consent || !$consent->_id) {
//      throw new CRGPDException('CRGPD-error-No consent object');
//    }
//
//    $consent->sendRequest();
  }

  /**
   * Envoie la demande de consentement par courriel.
   *
   * @param CRGPDConsent $consent
   * @param CSourceSMTP  $source
   *
   * @return void
   * @throws CRGPDException
   */
  public function send(CRGPDConsent $consent, CSourceSMTP $source) {
    if (!$consent->_id) {
      throw new CRGPDException('common-error-Object %s not found', CRGPDConsent::class);
    }

    if (!$source->_id) {
      throw new CRGPDException('common-error-Object %s not found', CSourceSMTP::class);
    }

    /** @var IRGPDCompliant $context */
    $context = $consent->loadTargetObject();

    if (!$consent || !$consent->_id) {
      throw new CRGPDException('common-error-Object %s not found', "{$consent->object_class}-{$consent->object_id}");
    }

    $subject   = $consent->getEmailSubject();
    $body      = $consent->getEmailBody();
    $recipient = $context->getEmail();

    if (!CApp::sendEmail($subject, $body, null, null, null, $recipient, $source)) {
      throw new CRGPDException('common-error-Unable to send mail');
    }

    $consent->markAsSent();
  }
}
