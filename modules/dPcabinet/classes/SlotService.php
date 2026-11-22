<?php

/**
 * @package Hospilibre\Cabinet
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réimplémentation GPL minimale (clean-room) : voir l'en-tête de CSlot.php
 * pour le contexte (SlotService OXOL retiré au nettoyage, cf. commit
 * 2b22f2e02, mais resté appelé inconditionnellement par
 * CPlageconsult::store()/CConsultation::store()/delete()). Ne couvre que le
 * strict nécessaire pour combler ces appels orphelins (création/annulation de
 * plages et consultations) : pas de portage des règles fines de surbooking de
 * l'original (non observables ici, la suite SlotServiceTest existante étant
 * elle-même bloquée par une dépendance de fixtures absente,
 * Ox\Erp\SourceCode\CFixturesReference).
 */

namespace Hospilibre\Cabinet;

/**
 * Gestion minimale des créneaux (slots) d'une plage de consultation.
 */
class SlotService
{
    /**
     * (Re)construit la grille de créneaux d'une plage de consultation à partir
     * de son (debut, fin, freq) sur son jour (date). Appelé par
     * CPlageconsult::store() à la création ou au changement des horaires.
     */
    public function verifySlot(CPlageconsult $plageconsult): void
    {
        if (!$plageconsult->_id || !$plageconsult->date || !$plageconsult->debut || !$plageconsult->fin) {
            return;
        }

        // Purge simple puis reconstruction : suffisant pour notre usage (plages
        // nouvellement créées par la simulation, pas de créneaux déjà réservés
        // à préserver).
        $existing = new CSlot();
        foreach ($existing->loadList(['plageconsult_id' => "= '{$plageconsult->_id}'"]) as $_slot) {
            $_slot->delete();
        }

        $start = strtotime("$plageconsult->date $plageconsult->debut");
        $end   = strtotime("$plageconsult->date $plageconsult->fin");
        if ($start === false || $end === false || $end <= $start) {
            return;
        }

        [$fh, $fm, $fs] = array_pad(array_map('intval', explode(':', $plageconsult->freq ?: '00:15:00')), 3, 0);
        $step = max(60, $fh * 3600 + $fm * 60 + $fs);

        for ($t = $start; $t < $end; $t += $step) {
            $slot                  = new CSlot();
            $slot->plageconsult_id = $plageconsult->_id;
            $slot->start           = date('Y-m-d H:i:s', $t);
            $slot->end             = date('Y-m-d H:i:s', min($t + $step, $end));
            $slot->status          = 'free';
            $slot->store();
        }
    }

    /**
     * Associe une consultation au créneau correspondant à son horaire.
     * Appelé par CConsultation::store() à la création ou au changement
     * d'horaire/plage/durée.
     */
    public function addConsultToSlot(CConsultation $consultation): void
    {
        if (!$consultation->_id || !$consultation->plageconsult_id || !$consultation->heure) {
            return;
        }

        $plage = $consultation->loadRefPlageconsult();
        if (!$plage || !$plage->_id) {
            return;
        }

        $target = "$plage->date $consultation->heure";

        $slot  = new CSlot();
        $slots = $slot->loadList([
            'plageconsult_id' => "= '{$plage->_id}'",
            'start'           => "<= '$target'",
            'end'             => "> '$target'",
        ], 'start');

        if (!$slots) {
            return;
        }

        $target_slot = reset($slots);
        if ($target_slot->consultation_id && (int) $target_slot->consultation_id !== (int) $consultation->_id) {
            $target_slot->overbooked = 1;
        }
        $target_slot->consultation_id = $consultation->_id;
        $target_slot->status          = 'busy';
        $target_slot->store();
    }

    /**
     * Libère le(s) créneau(x) occupé(s) par une consultation (annulation ou
     * suppression). Appelé par CConsultation::store() (annulation) et
     * CConsultation::delete().
     */
    public function deleteConsultationOfASlot(CConsultation $consultation): void
    {
        if (!$consultation->_id) {
            return;
        }

        $slot = new CSlot();
        foreach ($slot->loadList(['consultation_id' => "= '{$consultation->_id}'"]) as $_slot) {
            $_slot->consultation_id = null;
            $_slot->overbooked      = 0;
            $_slot->status          = 'free';
            $_slot->store();
        }
    }
}
