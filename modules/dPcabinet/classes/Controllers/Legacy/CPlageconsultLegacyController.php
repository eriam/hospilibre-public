<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Cabinet\Controllers\Legacy;

use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\Cabinet\CSlot;

class CPlageconsultLegacyController extends CLegacyController
{
    public function vw_list_next_slots(): void
    {
        $this->checkPermRead();

        // Lecture des paramètres de recherche transmis par la requête
        $practitionerIds = CView::get("prats_ids", "str");
        $weekdays        = CView::get("days", "str", true);
        $hourSlots       = CView::get("times", "str", true);
        $rangeLabel      = CView::get("libelle_plage", "str", true);
        $weekNumber      = CView::get("week_number", "str", true);
        $year            = CView::get("year", "str");
        $isAppointment   = CView::get("rdv", "bool");

        CView::checkin();

        // Ces trois champs arrivent sous forme de listes séparées par des virgules
        $practitionerIds = explode(",", $practitionerIds);
        $weekdays        = explode(",", $weekdays);
        $hourSlots       = explode(",", $hourSlots);

        // On se cale sur le lundi de la semaine demandée
        $weekBounds = CMbDT::dateFromWeekNumber($weekNumber, $year);
        $mondayDate = CMbDT::date("this week", $weekBounds["start"]);

        // Détermination de la date de départ à partir du premier jour coché
        $firstWeekday = $weekdays[array_key_first($weekdays)];
        $startDate    = ($firstWeekday === "Monday")
            ? $mondayDate
            : CMbDT::date("next $firstWeekday", $mondayDate);

        // Jamais dans le passé
        $today = CMbDT::date();
        if ($startDate < $today) {
            $startDate = $today;
        }

        // Correspondance jour -> index MySQL DAYOFWEEK (dimanche = 1)
        $weekdayIndexes = [
            "Monday"    => 2,
            "Tuesday"   => 3,
            "Wednesday" => 4,
            "Thursday"  => 5,
            "Friday"    => 6,
            "Saturday"  => 7,
        ];

        // On collecte les index des jours NON retenus pour les exclure
        $excludedDayIndexes = [];
        foreach ($weekdayIndexes as $dayName => $dayIndex) {
            if (!in_array($dayName, $weekdays)) {
                $excludedDayIndexes[] = $dayIndex;
            }
        }

        $where = [];
        $slots = [];

        // Exclusion des jours de la semaine non cochés
        if (count($excludedDayIndexes)) {
            $where[] = "DAYOFWEEK(slot.start) " . CSQLDataSource::prepareNotIn($excludedDayIndexes);
        }

        $where["plageconsult.chir_id"] = CSQLDataSource::prepareIn($practitionerIds);

        $where[] = "DATE(slot.start) >= '$startDate'";

        if ($rangeLabel) {
            $where['plageconsult.libelle'] = " LIKE '%$rangeLabel%'";
        }

        $ljoin = [];
        $ljoin["plageconsult"] = "plageconsult.plageconsult_id = slot.plageconsult_id";

        $where['plageconsult.locked'] = " != '1'";
        $where['slot.status']         = " = 'free'";

        // Construction des tranches horaires : on regroupe les créneaux consécutifs
        $timeClauses = [];
        if ($hourSlots) {
            $rangeStart = "07:00:00";
            $inSeries   = false;
            foreach ($hourSlots as $currentTime) {
                if (!$inSeries) {
                    $rangeStart = $currentTime;
                }

                $nextHour = CMbDT::addTime("+ 01:00:00", $currentTime);
                if (in_array($nextHour, $hourSlots)) {
                    $inSeries = true;
                } else {
                    $timeClauses[] = "TIME(slot.start) >= '$rangeStart' AND TIME(slot.end) BETWEEN '$rangeStart' AND '"
                        . $nextHour . "'";
                    $inSeries = false;
                }
            }
        }
        $where[] = implode(' OR ', $timeClauses);

        // Pour aujourd'hui, on écarte les créneaux dont l'heure est déjà passée
        $where[] = "IF (DATE(slot.start) = '" . CMbDT::date() . "',
                        IF (TIME(slot.start) >= '" . CMbDT::time() . "', true, false),
                        true
                    )";

        $slot  = new CSlot();
        $slots = $slot->loadList($where, "start ASC", 10, null, $ljoin);

        CStoredObject::massLoadFwdRef($slots, "plageconsult_id");
        foreach ($slots as $currentSlot) {
            $currentSlot->loadRefPlageconsult();
            $currentSlot->_ref_plageconsult->loadRefChir();

            $slotDay              = CMbDT::format($currentSlot->start, "%y-%m-%d");
            $currentSlot->_date   = CMbDT::strftime('%A %d %B', strtotime($slotDay));
            $currentSlot->_heure  = CMbDT::format($currentSlot->start, "%H:%M:%S");
            $currentSlot->_nb_week = CMbDT::format($slotDay, "%W");
        }

        // Rendu du fragment listant les créneaux
        $this->renderSmarty('inc_show_slots', ["slots" => $slots, "rdv" => $isAppointment]);
    }
}
