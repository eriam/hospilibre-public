<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Vague épidémique respiratoire (~4 semaines) : montée, pic J14–J21, décrue.
// Jours relatifs au début de la crise ; voir CCrisisScenario pour la sémantique.
return [
    'name'        => 'epidemie',
    'multipliers' => [
        0  => ['arrivals_per_day' => 1.0],
        7  => ['arrivals_per_day' => 1.8, 'avg_sejour_days' => 1.2, 'consults_per_day' => 0.8],
        14 => ['arrivals_per_day' => 2.5, 'avg_sejour_days' => 1.4, 'transfer_rate' => 2.0, 'consults_per_day' => 0.6, 'operations_per_day' => 0.5, 'constantes_per_day' => 1.5],
        21 => ['arrivals_per_day' => 2.2, 'avg_sejour_days' => 1.4, 'transfer_rate' => 1.8, 'consults_per_day' => 0.6, 'operations_per_day' => 0.5, 'constantes_per_day' => 1.5],
        28 => ['arrivals_per_day' => 1.0, 'avg_sejour_days' => 1.0],
    ],
    'admission'   => ['type' => 'comp', 'provenance' => '5', 'mode_entree' => '8', 'services_cibles' => ['Pneumologie', 'Médecine interne', 'Réanimation'], 'intervention' => false],
    'fenetres'    => [
        ['de' => 10, 'a' => 24, 'deprogrammer_operations' => 0.5, 'deprogrammer_consultations' => 0.4],
        ['de' => 12, 'a' => 30, 'renfort_lits' => ['service' => 'Médecine interne', 'nb' => 12]],
    ],
];
