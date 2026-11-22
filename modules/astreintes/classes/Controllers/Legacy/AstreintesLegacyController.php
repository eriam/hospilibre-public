<?php

/**
 * @package Hospilibre\Astreintes
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Astreintes\Controllers\Legacy;

use DateTimeImmutable;
use Exception;
use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CView;
use Hospilibre\Admin\CUser;
use Hospilibre\Astreintes\AstreinteCalendarBuilder;
use Hospilibre\Astreintes\AstreinteCalendarFactory;
use Hospilibre\Astreintes\AstreinteManager;
use Hospilibre\Astreintes\CCategorieAstreinte;
use Hospilibre\Astreintes\CPlageAstreinte;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CMediusers;

/**
 * Contrôleur legacy dédié à la gestion des astreintes.
 */
class AstreintesLegacyController extends CLegacyController
{
    /**
     * Affiche le calendrier des astreintes pour la période demandée.
     *
     * @throws Exception
     */
    public function calendrierAstreinte(): void
    {
        $this->checkPermRead();

        $selectedDate = CView::get("date", "date default|now", true);
        $displayMode  = CView::get("mode", "enum list|day|week|month|year default|week", true);
        $categoryId   = CView::get(
            "category",
            "ref class|CCategorieAstreinte default|" . CAppUI::pref("categorie-astreintes")
        );

        CView::checkin();

        $builder = new AstreinteCalendarBuilder(new DateTimeImmutable($selectedDate), $displayMode, $categoryId);
        $calendar = $builder->buildAstreinteCalendar(new AstreinteCalendarFactory());

        [$previousDate, $nextDate] = (new AstreinteManager())->computeNextPrev($selectedDate, $displayMode);

        $this->renderSmarty(
            "vw_calendar",
            [
                "date"                      => $selectedDate,
                "next"                      => $nextDate,
                "prev"                      => $previousDate,
                "planning"                  => $calendar,
                "height_planning_astreinte" => CAppUI::pref("planning_resa_height", 1500),
                "mode"                      => $displayMode,
                "categories"                => CCategorieAstreinte::loadListCategories(),
                "current_category_id"       => $categoryId,
            ]
        );
    }

    /**
     * Liste les astreintes selon la période, les types et la catégorie choisis.
     *
     * @throws Exception
     */
    public function listAstreintes(): void
    {
        $this->checkPermRead();

        $selectedDate = CView::get("date", "date default|now", true);
        $displayMode  = CView::get("mode", "enum list|day|week|month|year default|day", true);
        $rawTypes     = CView::get("type_names", "str default|all");
        $categoryId   = CView::get(
            "category",
            "ref class|CCategorieAstreinte default|" . CAppUI::pref("categorie-astreintes")
        );

        CView::checkin();

        $manager = new AstreinteManager();

        $typeNames = is_array($rawTypes) ? $rawTypes : explode(",", $rawTypes);

        $astreintes = $manager->loadAstreintes($selectedDate, $displayMode, $typeNames, $categoryId);
        [$previousDate, $nextDate] = $manager->computeNextPrev($selectedDate, $displayMode);

        $this->renderSmarty("vw_list_astreintes", [
            "astreinte"           => new CPlageAstreinte(),
            "astreintes"          => $astreintes,
            "today"               => $selectedDate,
            "date_prev"           => $previousDate,
            "date_next"           => $nextDate,
            "mode"                => $displayMode,
            "type_names"          => $typeNames,
            "categories"          => CCategorieAstreinte::loadListCategories(),
            "current_category_id" => $categoryId,

        ]);
    }

    /**
     * Affiche le détail des astreintes couvrant un jour et une heure donnés.
     *
     * @throws Exception
     */
    public function listAstreintesDay(): void
    {
        $this->checkPermRead();

        $selectedDate = CView::get("date", "date default|now");
        $selectedTime = CView::get("time", "time default|now");

        CView::checkin();

        $plagesAstreinte = (new AstreinteManager())->loadAstreintesForDays($selectedDate, $selectedTime);

        $formattedDate = htmlentities(
            CMbDT::format($selectedDate, CAppUI::conf("longdate")),
            ENT_COMPAT
        );
        $title = CAppUI::tr("CPlageAstreinte.For") . " " . $formattedDate;

        $this->renderSmarty(
            "vw_list_day_astreinte",
            [
                "plages_astreinte" => $plagesAstreinte,
                "categories"       => CCategorieAstreinte::loadListCategories(),
                "title"            => $title,
                "date"             => $selectedDate,
            ]
        );
    }

    /**
     * Version hors-ligne du listing des astreintes.
     *
     * @throws Exception
     */
    public function offlineListAstreintes(): void
    {
        $this->checkPermRead();

        $selectedDate = CView::get("date", "date default|now", true);
        $displayMode  = CView::get("mode", "enum list|day|week|month|year default|day", true);
        $rawTypes     = CView::get("type_names", "str default|all", true);
        $categoryId   = CView::get("category", "ref class|CCategorieAstreinte");

        CView::checkin();

        $manager = new AstreinteManager();

        $typeNames  = is_array($rawTypes) ? $rawTypes : explode(",", $rawTypes);
        $astreintes = $manager->loadAstreintes($selectedDate, $displayMode, $typeNames, $categoryId ?: null);
        [$periodStart, $periodEnd] = $manager->computeNextPrev($selectedDate, AstreinteManager::MODE_OFFLINE);

        $this->renderSmarty("offline_list_astreintes", [
            "astreintes"   => $astreintes,
            "start_period" => $periodStart,
            "end_period"   => $periodEnd,
        ]);
    }

    /**
     * Rend la liste des numéros de téléphone rattachés à un utilisateur.
     *
     * @throws Exception
     */
    public function listPhonesFromUser(): void
    {
        $this->checkPermRead();

        $userId = CView::get("user_id", "ref class|CMediusers");

        CView::checkin();

        $user = CUser::findOrNew($userId);

        $phones = (new AstreinteManager())->getPhonesFromUser($user);

        $this->renderSmarty("inc_list_phones", [
            "phones" => $phones,
            "user"   => $user,
        ]);
    }

    /**
     * Charge les plages d'astreinte associées à un utilisateur.
     *
     * @throws Exception
     */
    public function loadAstreintesUser(): void
    {
        $this->checkPermRead();

        $userId  = CView::get("user_id", "ref notNull class|CMediusers");
        $plageId = CView::get("plage_id", "ref class|CPlageAstreinte");

        CView::checkin();

        $user = CMediusers::findOrFail($userId);

        $plagesAstreinte = (new AstreinteManager())->loadAstreintesForUser($user);

        $this->renderSmarty("inc_liste_plages_astreinte", [
            "user"             => $user,
            "plages_astreinte" => $plagesAstreinte,
            "plage_id"         => $plageId,
            "today"            => CMbDT::dateTime(),
        ]);
    }

    /**
     * Prépare le formulaire d'édition d'une plage d'astreinte.
     *
     * @throws Exception
     */
    public function editPlageAstreinte(): void
    {
        $this->checkPermEdit();

        $plageId      = CView::get("plage_id", "str");
        $plageDate    = CView::get("date", "str");
        $plageHour    = CView::get("hour", "str");
        $plageMinutes = CView::get("minutes", "str");
        $userId       = CView::get("user_id", "str");

        CView::checkin();

        $manager = new AstreinteManager();

        $users = $manager->getUsersForPlageAstreinte();

        $user = CMediusers::findOrNew($userId);

        $plageAstreinte = $manager->getPlageAstreintes(
            $user,
            $users,
            $plageId,
            $plageDate,
            $plageHour,
            $plageMinutes
        );

        $this->renderSmarty(
            "inc_edit_plage_astreinte",
            [
                "users"          => $users,
                "user"           => CMediusers::get(),
                "group"          => CGroups::get(),
                "plageastreinte" => $plageAstreinte,
                "categories"     => CCategorieAstreinte::loadListCategories(),
            ]
        );
    }
}
