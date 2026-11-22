<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Profil CHU (~300 lits, 6 salles de bloc) pour le cours MIAGE. Identité fictive.
// spec_cpam : identifiants de CSpecCPAM::getList() — 1 Médecine générale, 2 Anesthésie-Réanimation,
// 3 Cardiologie, 4 Chirurgie générale, 9 Médecine interne, 12 Pédiatrie, 13 Pneumologie,
// 17 Neuro-Psychiatrie, 20 Réanimation médicale.
// Débits mesurés (Task 0) : 90 jours d'historique en ~47 min sur la machine de développement, en tranches de 3 jours.
return [
    'name'        => 'chu',
    'sejour_type' => 'comp',
    'hospital'    => [
        'nom'            => 'CHU de Villefictive',
        'raison_sociale' => 'Centre Hospitalier Universitaire de Villefictive',
        'adresse'        => '1 place de la Simulation',
        'cp'             => '00000',
        'ville'          => 'Villefictive',
        'finess'         => '000000000',
        'type'           => 'centre_hospitalier',
    ],
    'sizes'       => [
        'patients_initiaux' => 300,
        'sejours_en_cours'  => 230,
    ],
    'structure'   => [
        'services' => [
            ['nom' => 'Urgences - UHCD',     'code' => 'URG',  'chambres' => 12, 'lits_par_chambre' => 1, 'spec_cpam' => [1],     'medecins' => 6, 'personnels' => 18, 'poids_admissions' => 2.0],
            ['nom' => 'Médecine interne',    'code' => 'MED',  'chambres' => 20, 'lits_par_chambre' => 2, 'spec_cpam' => [9],     'medecins' => 5, 'personnels' => 16, 'poids_admissions' => 1.6],
            ['nom' => 'Cardiologie',         'code' => 'CARD', 'chambres' => 16, 'lits_par_chambre' => 2, 'spec_cpam' => [3],     'medecins' => 5, 'personnels' => 14, 'poids_admissions' => 1.2],
            ['nom' => 'Pneumologie',         'code' => 'PNEU', 'chambres' => 16, 'lits_par_chambre' => 2, 'spec_cpam' => [13],    'medecins' => 4, 'personnels' => 13, 'poids_admissions' => 1.0],
            ['nom' => 'Réanimation',         'code' => 'REA',  'chambres' => 16, 'lits_par_chambre' => 1, 'spec_cpam' => [20, 2], 'medecins' => 6, 'personnels' => 22, 'poids_admissions' => 0.3],
            ['nom' => 'Chirurgie viscérale', 'code' => 'VISC', 'chambres' => 16, 'lits_par_chambre' => 2, 'spec_cpam' => [4],     'medecins' => 4, 'personnels' => 12, 'poids_admissions' => 1.0],
            ['nom' => 'Orthopédie',          'code' => 'ORTH', 'chambres' => 16, 'lits_par_chambre' => 2, 'spec_cpam' => [4],     'medecins' => 4, 'personnels' => 12, 'poids_admissions' => 1.0],
            ['nom' => 'Neurologie',          'code' => 'NEUR', 'chambres' => 14, 'lits_par_chambre' => 2, 'spec_cpam' => [17],    'medecins' => 4, 'personnels' => 12, 'poids_admissions' => 0.8],
            ['nom' => 'Pédiatrie',           'code' => 'PED',  'chambres' => 14, 'lits_par_chambre' => 2, 'spec_cpam' => [12],    'medecins' => 3, 'personnels' => 11, 'poids_admissions' => 0.8],
            ['nom' => 'Gériatrie',           'code' => 'GER',  'chambres' => 20, 'lits_par_chambre' => 2, 'spec_cpam' => [9],     'medecins' => 4, 'personnels' => 14, 'poids_admissions' => 1.2],
        ],
        'blocs'    => 6,
    ],
    'rates'       => [
        'arrivals_per_day'   => 50,
        'avg_sejour_days'    => 5,
        'transfer_rate'      => 0.12,
        'consults_per_day'   => 120,
        'operations_per_day' => 25,
        'constantes_per_day' => 3,
        'ambulatoire_rate'   => 0.15,
    ],
];
