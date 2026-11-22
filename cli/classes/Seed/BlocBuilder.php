<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Bloc\CBlocOperatoire;
use Hospilibre\Bloc\CPlageOp;
use Hospilibre\Bloc\CSalle;
use Hospilibre\Mediusers\CMediusers;
use Ox\Core\CMbDT;

/**
 * Bloc opératoire, salles et plages horaires.
 *
 * Les plages sont créées par salle et par JOUR OUVRÉ sur toute la fenêtre du profil, horizon
 * programmé compris : un bloc qui opérerait le dimanche se remarque immédiatement, et un bloc
 * qui n'ouvrirait pas dans les semaines à venir ne pourrait accueillir aucune des interventions
 * programmées. Chaque plage porte un praticien responsable et ses horaires de référence, parce
 * que `CPlageOp` les exige et parce que l'invariant 4 en dépend — une intervention doit tenir
 * dans la plage de SA salle.
 *
 * DEUX BLOCS. Le bloc programmé n'ouvre que les jours ouvrés, aux heures de programme ; le bloc
 * d'urgence est ouvert EN CONTINU, tous les jours, et sa salle est tenue par l'équipe de garde. Sans
 * lui, une appendicectomie à deux heures du matin n'existait pas dans le jeu de données, et les 10 %
 * de séjours déclarés urgents n'avaient ni lit ni acte.
 *
 * PERSONNE N'EST DANS DEUX SALLES À LA FOIS. L'équipe de garde est réservée EN PREMIER, puis les
 * vacations du programme se servent parmi les praticiens restants, sans remise. L'ordre compte : la
 * choisir en dernier, parmi « ceux qui ne sont pas déjà au programme », ne laissait personne de libre
 * dès que les deux vacations d'une journée avaient consommé tout l'effectif.
 *
 * UNE VACATION APPARTIENT À UNE ÉQUIPE. Le chirurgien d'une plage était tiré parmi TOUS les
 * chirurgiens de l'établissement, et l'intervention héritait de ce chirurgien tout en portant un
 * libellé venu du service du séjour : sur un profil medium, 291 interventions sur 442 étaient opérées
 * par un praticien d'une autre spécialité — un gynécologue posait des prothèses de hanche. Les plages
 * sont donc attribuées aux spécialités chirurgicales à tour de rôle, ce qui est aussi la façon dont
 * un vrai programme opératoire se construit : une vacation est celle d'une équipe.
 *
 * Chaque vacation porte AUSSI son anesthésiste, et deux salles ouvertes en même temps n'ont jamais
 * le même : c'est ce qui donne un sens à l'effectif d'anesthésie. Sans cette affectation, les
 * anesthésistes existaient dans l'annuaire sans apparaître nulle part dans l'activité.
 *
 * LE DIMENSIONNEMENT NE VIENT PLUS DES LITS. Le premier jet ouvrait une salle par 90 lits, avec
 * un minimum de deux : un établissement de 200 lits recevait trois salles pour une activité qui
 * n'en remplissait pas une, et le planning opératoire était vide à 83 %. Ce qui remplit un bloc,
 * c'est le nombre d'actes ; il est calculé par SeedProfile::operatingTheatre().
 */
final class BlocBuilder
{


    /** Nom de la salle d'urgence, et du bloc qui la porte. */
    private const EMERGENCY_BLOC = 'Bloc des urgences';

    private const EMERGENCY_ROOM = "Salle d'urgence";

    /** @var SeedContext */
    private $context;

    public function __construct(SeedContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param array{rooms: int, sessions: int} $theatre Dimensionnement décidé par le profil
     *
     * @throws SeedException
     */
    public function build(Referential $referential, array $theatre): Referential
    {
        $bloc  = $this->buildBloc($referential, 'Bloc central', 'bloc:central');
        $rooms = [];

        for ($i = 1; $i <= $theatre['rooms']; $i++) {
            $room    = $this->buildRoom($bloc, 'Salle ' . $i, 'salle:' . $i);
            $rooms[] = $room;
            $referential->addRoom($room);
        }

        $emergency_bloc = $this->buildBloc($referential, self::EMERGENCY_BLOC, 'bloc:urgences');
        $emergency_room = $this->buildRoom($emergency_bloc, self::EMERGENCY_ROOM, 'salle:urgences');
        $referential->addRoom($emergency_room, true);

        $this->buildSlots($referential, $rooms, $theatre['sessions'], $emergency_room);

        return $referential;
    }

    /**
     * @throws SeedException
     */
    private function buildBloc(Referential $referential, string $name, string $tag): CBlocOperatoire
    {
        $bloc           = new CBlocOperatoire();
        $bloc->group_id = $referential->group()->_id;
        $bloc->nom      = $name;
        // `CBlocOperatoire::$type` n'offre pas de valeur « urgences » (chir|coro|endo|exte|obst) :
        // un bloc d'urgence est un bloc chirurgical, c'est son ouverture continue qui le distingue.
        $bloc->type     = 'chir';

        /** @var CBlocOperatoire $bloc */
        $bloc = $this->context->write($bloc, $tag);

        return $bloc;
    }

    /**
     * @throws SeedException
     */
    private function buildRoom(CBlocOperatoire $bloc, string $name, string $tag): CSalle
    {
        $room          = new CSalle();
        $room->bloc_id = $bloc->_id;
        $room->nom     = $name;
        $room->stats   = '1';
        $room->dh      = '0';

        /** @var CSalle $room */
        $room = $this->context->write($room, $tag);

        return $room;
    }

    /**
     * Plages sur les jours ouvrés de la fenêtre, pour chaque salle.
     *
     * @param CSalle[] $rooms
     *
     * @throws SeedException
     */
    private function buildSlots(
        Referential $referential,
        array $rooms,
        int $sessions,
        CSalle $emergency_room
    ): void {
        $mix           = $this->context->decor()->clinicalMix();
        $random        = $this->context->stream('plages');
        $specialities  = $referential->surgicalSpecialities();
        $anaesthetists = $referential->anaesthetists();

        if ($specialities === []) {
            throw new SeedException(
                'Aucune spécialité chirurgicale : impossible de programmer un bloc. '
                . "Le profil doit inclure au moins une spécialité chirurgicale."
            );
        }

        if (count($anaesthetists) < count($rooms)) {
            throw new SeedException(
                'Moins d\'anesthésistes (' . count($anaesthetists) . ') que de salles ('
                . count($rooms) . ') : une salle en activité occupe un anesthésiste du début à la'
                . ' fin, deux salles simultanées ne peuvent pas se partager le même.'
            );
        }

        $turn = 0;

        $day = $this->context->windowStart();
        $day = substr($day, 0, 10);

        // Le bloc ouvre jusqu'au bout de l'horizon des admissions programmées : sans cela, une
        // intervention programmée dans deux mois n'a aucune plage où s'inscrire, et elle était
        // purement et simplement perdue.
        $end = $this->context->shiftDate('+' . $this->context->profile()->futureHorizonMonths() . ' months');

        while (strcmp($day, $end) <= 0) {
            // CMbDT::transform rend le jour de la semaine, 1 = lundi … 7 = dimanche.
            $weekday = (int)CMbDT::format($day, '%u');

            // LA GARDE D'ABORD. Elle tient la salle d'urgence vingt-quatre heures, donc elle
            // chevauche forcément tout le programme du jour : il faut la réserver avant de composer
            // celui-ci, et non l'inverse.
            $on_call_speciality   = $specialities[$turn % count($specialities)];
            $on_call_surgeon      = $random->pick($referential->practitioners($on_call_speciality));
            $on_call_anaesthetist = $random->pick($anaesthetists);
            $turn++;

            $reserved = [
                (int)$on_call_surgeon->_id      => true,
                (int)$on_call_anaesthetist->_id => true,
            ];

            foreach ($mix->emergencySessions() as $session) {
                $this->buildSlot(
                    $referential,
                    $emergency_room,
                    $day,
                    $session,
                    $on_call_surgeon,
                    $on_call_anaesthetist,
                    true
                );
            }

            if ($weekday <= 5) {
                // Boucle par VACATION et non par salle : les équipes sont tirées pour la vacation
                // entière et SANS REMISE, de sorte que personne ne tienne deux salles au même
                // moment.
                $available = $this->without($anaesthetists, $reserved);

                foreach (array_slice($mix->theatreSessions(), 0, $sessions) as $session) {
                    $anaesthesia = $random->take($available, count($rooms));

                    // Tour de rôle strict entre spécialités, continué d'un jour au suivant : sur la
                    // fenêtre entière, chacune reçoit la même part de vacations, et l'ordre ne
                    // corrèle pas avec celui des salles.
                    $teams = [];
                    foreach (array_keys($rooms) as $ignored) {
                        $teams[] = $specialities[$turn % count($specialities)];
                        $turn++;
                    }

                    $surgeons = $this->pickDistinctSurgeons($referential, $teams, $random, $reserved);

                    foreach (array_values($rooms) as $index => $room) {
                        $this->buildSlot($referential, $room, $day, $session, $surgeons[$index], $anaesthesia[$index]);
                    }
                }
            }

            $day = CMbDT::date('+1 day', $day);
        }
    }

    /**
     * Un chirurgien par salle, distinct des autres, en respectant la spécialité de chaque vacation.
     *
     * Une spécialité peut tenir plusieurs salles dans la même vacation : il lui faut alors autant de
     * praticiens distincts, ce dont l'effectif tient compte (cf. EtablissementBuilder). Si elle n'en
     * a pas assez — un profil surchargé à la main —, on réutilise les siens plutôt que d'échouer,
     * parce qu'un chirurgien en double reste moins faux qu'un chirurgien d'une autre spécialité.
     *
     * @param string[]         $teams    spécialité de chaque salle, dans l'ordre
     * @param array<int, true> $reserved praticiens de garde, à ne pas engager au programme
     *
     * @return CMediusers[]
     * @throws SeedException
     */
    private function pickDistinctSurgeons(
        Referential $referential,
        array $teams,
        SeedRandom $random,
        array $reserved
    ): array {
        $pools    = [];
        $surgeons = [];

        foreach ($teams as $speciality) {
            if (empty($pools[$speciality])) {
                $pools[$speciality] = $random->shuffle(
                    $this->without($referential->practitioners($speciality), $reserved)
                );
            }

            // Épuisée malgré la marge — un profil surchargé à la main : on réutilise les praticiens
            // de la spécialité plutôt que d'échouer, parce qu'un chirurgien en double reste moins
            // faux qu'un chirurgien d'une autre spécialité.
            if ($pools[$speciality] === []) {
                $pools[$speciality] = $random->shuffle($referential->practitioners($speciality));
            }

            $surgeons[] = array_shift($pools[$speciality]);
        }

        return $surgeons;
    }

    /**
     * Les praticiens d'une liste qui ne sont pas réservés, la liste entière s'ils le sont tous.
     *
     * @param CMediusers[]     $pool
     * @param array<int, true> $reserved
     *
     * @return CMediusers[]
     */
    private function without(array $pool, array $reserved): array
    {
        $free = [];

        foreach ($pool as $practitioner) {
            if (!isset($reserved[(int)$practitioner->_id])) {
                $free[] = $practitioner;
            }
        }

        return $free === [] ? $pool : $free;
    }

    /**
     * @throws SeedException
     */
    private function buildSlot(
        Referential $referential,
        CSalle $room,
        string $day,
        array $session,
        $surgeon,
        $anaesthetist,
        bool $emergency = false
    ): void {
        $slot                  = new CPlageOp();
        $slot->salle_id        = $room->_id;
        $slot->date            = $day;
        $slot->debut           = $session['debut'];
        $slot->fin             = $session['fin'];
        $slot->debut_reference = $session['debut'];
        $slot->fin_reference   = $session['fin'];
        $slot->chir_id         = $surgeon->_id;
        $slot->anesth_id       = $anaesthetist->_id;

        // Pas de tag : une plage est un objet de masse, et un tag n'a d'intérêt que pour ce
        // qu'une consigne d'exercice peut citer.
        /** @var CPlageOp $slot */
        $slot = $this->context->write($slot);

        $referential->addSlot($slot, $emergency);
    }
}
