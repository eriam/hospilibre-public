<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Plan blanc (accident collectif) : pic de 48 h d'urgences chirurgicales, puis ~2 semaines de queue.
// Le plan blanc OUVRE des lits (renfort) et déprogramme pour absorber l'afflux : sans renfort et
// à ×6, une clinique de 72 lits refusait 280 admissions pour 20 urgences admises (vérif Task 7).
return [
    'name'        => 'plan_blanc',
    'multipliers' => [
        0  => ['arrivals_per_day' => 3.0, 'ambulatoire_rate' => 0.2, 'operations_per_day' => 1.0],
        1  => ['arrivals_per_day' => 3.0, 'ambulatoire_rate' => 0.2],
        2  => ['arrivals_per_day' => 1.0, 'avg_sejour_days' => 1.6, 'ambulatoire_rate' => 0.4],
        14 => ['arrivals_per_day' => 1.0, 'avg_sejour_days' => 1.0, 'ambulatoire_rate' => 1.0],
    ],
    'admission'   => ['type' => 'comp', 'provenance' => '5', 'mode_entree' => '8', 'services_cibles' => ['Orthopédie', 'Chirurgie viscérale', 'Unité de surveillance continue'], 'intervention' => true],
    'fenetres'    => [
        ['de' => 0, 'a' => 3, 'deprogrammer_operations' => 0.8, 'deprogrammer_consultations' => 0.6],
        ['de' => 4, 'a' => 10, 'deprogrammer_operations' => 0.3],
        ['de' => 0, 'a' => 7, 'renfort_lits' => ['service' => 'Orthopédie', 'nb' => 10]],
        ['de' => 0, 'a' => 7, 'renfort_lits' => ['service' => 'Chirurgie viscérale', 'nb' => 10]],
    ],
];
