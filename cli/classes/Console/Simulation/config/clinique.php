<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Profil clinique chirurgicale privée (~70 lits, 5 salles, ambulatoire dominant). Identité fictive.
// spec_cpam : identifiants de CSpecCPAM::getList() — 2 Anesthésie-Réanimation, 4 Chirurgie générale,
// 15 Ophtalmologie.
return [
    'name'        => 'clinique',
    'sejour_type' => 'comp',
    'hospital'    => [
        'nom'            => 'Clinique du Parc Fictif',
        'raison_sociale' => 'SAS Clinique du Parc Fictif',
        'adresse'        => '12 avenue du Parc',
        'cp'             => '00000',
        'ville'          => 'Villefictive',
        'finess'         => '000000000',
        'type'           => 'clinique',
    ],
    'sizes'       => [
        'patients_initiaux' => 80,
        'sejours_en_cours'  => 45,
    ],
    'structure'   => [
        'services' => [
            ['nom' => 'Chirurgie ambulatoire',          'code' => 'AMBU', 'chambres' => 24, 'lits_par_chambre' => 1, 'spec_cpam' => [4, 15], 'medecins' => 6, 'personnels' => 12, 'poids_admissions' => 3.0],
            ['nom' => 'Orthopédie',                     'code' => 'ORTH', 'chambres' => 10, 'lits_par_chambre' => 2, 'spec_cpam' => [4],     'medecins' => 4, 'personnels' => 9,  'poids_admissions' => 1.2],
            ['nom' => 'Chirurgie viscérale',            'code' => 'VISC', 'chambres' => 8,  'lits_par_chambre' => 2, 'spec_cpam' => [4],     'medecins' => 3, 'personnels' => 7,  'poids_admissions' => 1.0],
            ['nom' => 'Ophtalmologie',                  'code' => 'OPH',  'chambres' => 6,  'lits_par_chambre' => 1, 'spec_cpam' => [15],    'medecins' => 3, 'personnels' => 4,  'poids_admissions' => 0.8],
            ['nom' => 'Unité de surveillance continue', 'code' => 'USC',  'chambres' => 6,  'lits_par_chambre' => 1, 'spec_cpam' => [2],     'medecins' => 2, 'personnels' => 8,  'poids_admissions' => 0.2],
        ],
        'blocs'    => 5,
    ],
    'rates'       => [
        'arrivals_per_day'   => 30,
        'avg_sejour_days'    => 2,
        'transfer_rate'      => 0.05,
        'consults_per_day'   => 45,
        'operations_per_day' => 22,
        'constantes_per_day' => 2,
        'ambulatoire_rate'   => 0.6,
    ],
];
