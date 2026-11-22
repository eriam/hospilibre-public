<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Crise interne (~6 semaines) : pénurie de personnel + fermeture de l'aile SSR locomoteur
// (20 des 60 lits), refus d'admission, séjours allongés faute de sortie vers l'aval.
return [
    'name'        => 'crise_interne',
    'multipliers' => [
        0  => ['consults_per_day' => 0.5, 'avg_sejour_days' => 1.4, 'constantes_per_day' => 0.75],
        42 => ['consults_per_day' => 0.5, 'avg_sejour_days' => 1.4, 'constantes_per_day' => 0.75],
    ],
    'admission'   => ['type' => 'ssr', 'services_cibles' => ['SSR polyvalent', 'SSR gériatrique'], 'intervention' => false],
    'fenetres'    => [
        ['de' => 0, 'a' => 42, 'fermer_service' => 'SSR locomoteur'],
        ['de' => 0, 'a' => 42, 'refus_admissions' => 0.3],
    ],
];
