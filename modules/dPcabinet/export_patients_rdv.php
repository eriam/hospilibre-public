<?php
/**
 * @package Hospilibre\Cabinet
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

use Ox\Core\CCanDo;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Ox\Core\FileUtil\CCSVFile;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Patients\CPatient;

CCanDo::checkAdmin();

$date = CView::get("date", "date default|now");
$rpps = CView::get("rpps", "str");

CView::checkin();

$curr_group = CGroups::get();

$rpps = $rpps ? explode("|", $rpps) : array();

$consult = new CConsultation();

$ljoin = array(
  "plageconsult"        => "plageconsult.plageconsult_id = consultation.plageconsult_id",
  "users_hospilibre"     => "users_hospilibre.user_id = plageconsult.chir_id",
  "functions_hospilibre" => "functions_hospilibre.function_id = users_hospilibre.function_id"
);

$where = array(
  "plageconsult.date"            => "= '$date'",
  "functions_hospilibre.group_id" => "= '$curr_group->_id'",
  "consultation.patient_id"      => "IS NOT NULL",
  "consultation.annule"          => "= '0'"
);

if (count($rpps)) {
  $where["rpps"] = CSQLDataSource::prepareIn($rpps);
}

$consults = $consult->loadList($where, null, null, "consultation.consultation_id", $ljoin);

$plages = CStoredObject::massLoadFwdRef($consults, "plageconsult_id");
CStoredObject::massLoadFwdRef($plages, "chir_id");

$patients = CStoredObject::massLoadFwdRef($consults, "patient_id");
CPatient::massLoadIPP($patients);

$csv = new CCSVFile();

$csv->writeLine(
  array(
    "RPPS",
    "IPP",
    "Nom de naissance",
    "Nom d'usage",
    "Prénom",
    "Date de naissance",
    "NÂ° de mobile",
    "E-mail",
    "Date et heure de RDV"
  )
);

/** @var CConsultation $_consult */
foreach ($consults as $_consult) {
  /** @var CPatient $_patient */
  $_patient = $_consult->loadRefPatient();
  $_consult->loadRefPlageConsult();

  $csv->writeLine(
    array(
      $_consult->_ref_chir->rpps,
      $_patient->_IPP,
      $_patient->nom_jeune_fille,
      $_patient->nom,
      $_patient->prenom,
      $_patient->naissance,
      $_patient->tel2,
      $_patient->email,
      $_consult->_datetime
    )
  );
}

$csv->stream("export_patients_" . str_replace("-", "_", $date));