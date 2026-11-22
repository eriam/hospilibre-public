<?php
/**
 * @package Hospilibre\Populate
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Populate\Generators;

use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CMbDT;
use Ox\Core\Generators\CObjectGenerator;
use Hospilibre\Patients\CAntecedent;
use Hospilibre\Patients\CDossierMedical;
use Hospilibre\Patients\CPatient;

/**
 * Description
 */
class CPatientGenerator extends CObjectGenerator {
  static $mb_class = CPatient::class;

  /** @var CPatient  */
  protected $object;
  protected $codes_used = array();

  /**
   * Generate a CPatient
   *
   * @return CPatient
   * @throws Exception
   */
  function generate() {
    // Divergence de portage : la clé de config "populate CPatient_pct_create"
    // (pourcentage de chances de créer plutôt que réutiliser un patient
    // existant) n'a jamais été définie dans ce fork — ni config.php ("populate"
    // n'est même pas une clé racine de $dPconfig), ni à la révision d'origine
    // restaurée. CAppUI::conf() lève un warning (accès de clé de tableau
    // indéfinie, promu en erreur par le socle) sur une clé racine absente : on
    // évite donc l'appel et fixe directement un défaut raisonnable de 80
    // (majoritairement création), le comportement "réutiliser au-delà d'un
    // certain volume" restant néanmoins actif.
    $pct_create = 80;

    if (!$this->force && (rand(0, 100) > $pct_create)) {
      $where          = array('deces' => 'IS NULL');
      $count_patients = $this->object->countList($where);

      if ($count_patients) {
        // CObjectGenerator::getRandomObject() (base réimplémentée) attend un
        // fragment SQL brut (?string), pas un tableau colonne => condition
        // (convention de l'ancien socle Mediboard) : on le construit ici.
        $reused = $this->getRandomObject((int) $count_patients, '`deces` IS NULL');

        if ($reused) {
          $this->object = $reused;
          $this->trace(static::TRACE_LOAD);

          return $this->object;
        }
      }
    }

    $persona = CPersonaSource::randomPrenom();
    $this->object->prenom = $persona['prenom'];
    $this->object->nom    = CPersonaSource::randomNom();
    // CPatient::sexe (enum i|m|f) n'accepte pas "u" (indéterminé) : le repli
    // synthétique de CPersonaSource::randomPrenom() peut renvoyer "u" si les
    // fichiers de prénoms sont absents ; on retombe alors sur un tirage f/m.
    $this->object->sexe = $persona['sexe'] !== 'u' ? $persona['sexe'] : (mt_rand(0, 1) ? 'f' : 'm');

    $this->object->civilite = "guess";

    $commune = CPersonaSource::randomCommune();
    if ($commune !== null) {
      $this->object->ville = $commune['commune'];
      $this->object->cp    = $commune['cp'];
    } else {
      // Repli : ancien comportement dégradé (pas de table communes_france
      // peuplée) — code postal aléatoire, commune fictive.
      $this->object->cp    = $this->getRandomCP();
      $this->object->ville = 'Villefictive';
    }

    if ($this->object->_specs['adresse']->notNull) {
      $this->object->adresse = 'sample adresse';
    }

    if ($this->object->_specs['tel']->notNull) {
      $this->object->tel = '0505050505';
    }

    $this->object->pays = 'France';

    // Mêmes clés "populate CPatient_*" absentes de ce fork (cf. pct_create
    // ci-dessus) : bornes d'âge fixées en dur plutôt qu'un appel CAppUI::conf()
    // qui lèverait un warning sur la clé racine "populate" inexistante.
    $start_date              = '-90 years';
    $end_date                = '-18 years';
    $this->object->naissance = CMbDT::getRandomDate($start_date, $end_date, 'Y-m-d');

    if ($msg = $this->object->store()) {
        CAppUI::stepAjax($msg, UI_MSG_WARNING);
        return null;
    }
    else {
      CAppUI::setMsg("CPatient-msg-create", UI_MSG_OK);
      $this->trace(static::TRACE_STORE);
    }

    if (!$this->force) {
      // Mêmes clés de config non définies dans ce fork (cf. pct_create
      // ci-dessus) : défauts raisonnables pour un historique fictif léger,
      // fixés en dur plutôt que via CAppUI::conf() (clé racine "populate"
      // inexistante).
      $min_atcd = 0;
      $max_atcd = 2;
      $min_atcd = ($min_atcd <= $max_atcd) ? $min_atcd : $max_atcd;

      $atcd_count = rand($min_atcd, $max_atcd);
      if ($atcd_count > 0) {
        $dossier_medical_id = CDossierMedical::dossierMedicalId($this->object->_id, $this->object->_class);

        for ($i = 0; $i < $atcd_count; $i++) {
          $this->createAtcd($dossier_medical_id);
        }
      }
    }

    return $this->object;
  }

  /**
   * Create an ATCD
   *
   * @param int $dossier_medical_id Patient's CDossierMedical->_id
   *
   * @return void
   * @throws Exception
   */
  protected function createAtcd($dossier_medical_id) {
    $atcd                     = new CAntecedent();
    $atcd->dossier_medical_id = $dossier_medical_id;
    $code_cim = $this->getRandomCIM10Code($this->codes_used);
    $atcd->rques              = $code_cim['code'] . ' : ' . $code_cim['libelle'];
    $this->codes_used[]       = $code_cim['code'];

    if ($msg = $atcd->store()) {
      CAppUI::stepAjax($msg, UI_MSG_WARNING);
      return null;
    }
    else {
      CAppUI::setMsg("CAntecedent-msg-create", UI_MSG_OK);
    }

    $this->trace(static::TRACE_STORE, $atcd);
  }

  /**
   * Tire un pseudo-code CIM10 pour peupler un antécédent fictif.
   *
   * Divergence de portage : la base réimplémentée n'expose pas de
   * getRandomCIM10Code() (contrairement au socle d'origine). On reste
   * autonome (pas de dépendance à la table de référence CIM10, dont le
   * peuplement n'est pas garanti dans cet environnement) : on pioche dans une
   * courte liste de libellés courants, en évitant les codes déjà utilisés
   * pour ce dossier tant que la liste le permet.
   *
   * @param string[] $excluded Codes déjà utilisés pour ce dossier
   *
   * @return array{code: string, libelle: string}
   */
  protected function getRandomCIM10Code($excluded = []) {
    $codes = [
      'I10' => 'Hypertension essentielle (primitive)',
      'E11' => 'Diabète sucré non insulino-dépendant',
      'J45' => 'Asthme',
      'M54' => 'Dorsalgie',
      'K21' => 'Reflux gastro-oesophagien',
      'F32' => 'Episode dépressif',
      'J06' => 'Infection aiguë des voies respiratoires supérieures',
      'N39' => "Autres affections de l'appareil urinaire",
    ];

    $available = array_diff_key($codes, array_flip($excluded));
    if (!$available) {
      $available = $codes;
    }

    $code = array_rand($available);

    return ['code' => $code, 'libelle' => $available[$code]];
  }
}
