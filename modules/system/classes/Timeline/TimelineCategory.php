<?php

/**
 * @package Hospilibre\System
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\System\Timeline;

use DateTime;
use Exception;
use Ox\Core\Autoload\IShortNameAutoloadable;
use Ox\Core\CMbDT;
use Ox\Core\Comparator;
use Ox\Core\ComparatorException;
use Ox\Core\CStoredObject;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Mediusers\CMediusersComparator;
use Hospilibre\Patients\CPatient;

/**
 * Socle commun aux categories de timeline.
 */
abstract class TimelineCategory implements ITimelineCategory, IShortNameAutoloadable
{
    /** @var CMediusers */
    protected $users;

    /** @var CPatient */
    protected $patient;

    /** @var array */
    private $timeline = [];

    /** @var int */
    private $amount_events = 0;

    /** @var CMediusers[] */
    private $involved_users = [];

    /**
     * @inheritDoc
     */
    public function getAmountEvents(): int
    {
        return $this->amount_events;
    }

    /**
     * @inheritDoc
     */
    public function setUsers(array $users): void
    {
        $this->users = $users;
    }

    /**
     * @inheritDoc
     */
    public function setPatient(CPatient $patient): void
    {
        $this->patient = $patient;
    }

    /**
     * @inheritDoc
     */
    public function getInvolvedUsers(): array
    {
        return $this->involved_users;
    }

    /**
     * @return array
     */
    protected function getTimeline(): array
    {
        return $this->timeline;
    }

    /**
     * Enregistre un element dans la timeline, range par categorie.
     *
     * @param string              $year
     * @param string              $month
     * @param string              $date
     * @param string              $object_type Nom canonique associe au menu
     * @param CStoredObject|array $object
     * @param string|null         $context     Tri complementaire eventuel
     *
     * @return void
     */
    protected function appendTimeline(
        string $year,
        string $month,
        string $date,
        string $object_type,
        $object,
        string $context = null
    ): void {
        $this->timeline[$year][$month][$date][$object_type][$context][] = $object;
    }

    /**
     * Decompose une date en annee, annee-mois et annee-mois-jour.
     *
     * @param string $date
     *
     * @return string[]
     * @throws Exception
     */
    protected function makeListDates(?string $date): array
    {
        if (CMbDT::isLunarDate($date)) {
            $date = CMbDT::lunarToGregorian($date);
        }

        $parsed = new DateTime($date ?? '');

        return [$parsed->format("Y"), $parsed->format("Y-m"), $parsed->format("Y-m-d")];
    }

    /**
     * Indique si le praticien fait partie de la selection courante.
     *
     * @param CMediusers $user Peut etre null : certaines categories n'ont pas de praticien
     *
     * @return bool
     * @throws ComparatorException
     */
    protected function selectedPractitioner(?CMediusers $user): bool
    {
        // Aucun praticien sur l'evenement mais un filtre est actif : on ecarte.
        if (!$user && $this->users) {
            return false;
        }

        // Pas de filtre actif : tout passe.
        if (!$this->users || (!$user && !$this->users)) {
            return true;
        }

        foreach ($this->users as $selected_user) {
            $comparator = new Comparator(new CMediusersComparator());

            if (!$comparator->executeStrategy($selected_user, $user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Incremente le compteur d'evenements.
     *
     * @return void
     */
    protected function incrementAmountEvents(): void
    {
        $this->amount_events++;
    }

    /**
     * Ajoute un praticien a la liste des intervenants s'il n'y figure pas deja.
     *
     * @param CMediusers $user
     *
     * @return void
     * @throws Exception
     */
    protected function addToInvolvedUser(CMediusers $user): void
    {
        if (in_array($user, $this->involved_users)) {
            return;
        }

        $this->involved_users[] = $user;
    }
}
