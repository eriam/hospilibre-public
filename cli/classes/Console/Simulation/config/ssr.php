<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Profil SSR / hôpital local (60 lits, aucun bloc, longs séjours). Identité fictive.
// spec_cpam : identifiants de CSpecCPAM::getList() — 1 Médecine générale, 9 Médecine interne,
// 31 Rééducation Réadaptation fonctionnelle.
return [
    'name'        => 'ssr',
    'sejour_type' => 'ssr',
    'hospital'    => [
        'nom'            => 'Centre SSR des Tilleuls Fictifs',
        'raison_sociale' => 'Centre SSR des Tilleuls Fictifs',
        'adresse'        => '3 chemin des Tilleuls',
        'cp'             => '00000',
        'ville'          => 'Villefictive',
        'finess'         => '000000000',
        'type'           => 'ssr',
    ],
    'sizes'       => [
        'patients_initiaux' => 60,
        'sejours_en_cours'  => 50,
    ],
    'structure'   => [
        'services' => [
            ['nom' => 'SSR polyvalent',  'code' => 'SSRP', 'chambres' => 10, 'lits_par_chambre' => 2, 'spec_cpam' => [31, 1], 'medecins' => 2, 'personnels' => 12, 'poids_admissions' => 1.0],
            ['nom' => 'SSR gériatrique', 'code' => 'SSRG', 'chambres' => 10, 'lits_par_chambre' => 2, 'spec_cpam' => [9],     'medecins' => 2, 'personnels' => 12, 'poids_admissions' => 1.2],
            ['nom' => 'SSR locomoteur',  'code' => 'SSRL', 'chambres' => 10, 'lits_par_chambre' => 2, 'spec_cpam' => [31],    'medecins' => 2, 'personnels' => 10, 'poids_admissions' => 0.8],
        ],
        'blocs'    => 0,
    ],
    'rates'       => [
        'arrivals_per_day'   => 2,
        'avg_sejour_days'    => 25,
        'transfer_rate'      => 0.02,
        'consults_per_day'   => 12,
        'operations_per_day' => 0,
        'constantes_per_day' => 4,
        'ambulatoire_rate'   => 0.0,
    ],
];
