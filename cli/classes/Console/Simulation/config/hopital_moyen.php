<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

// Config par défaut « taille moyenne » de l'hôpital fictif de simulation.
// Toutes les clés sont surchargeables (--config <fichier> ou options CLI).
return [
    'name'     => 'moyen',
    'hospital' => [
        'nom'            => 'Centre Hospitalier Fictif',
        'raison_sociale' => 'Centre Hospitalier Fictif',
        'adresse'        => '1 rue de la Simulation',
        'cp'             => '00000',
        'ville'          => 'Villefictive',
        'finess'         => '000000000',
        'type'           => 'centre_hospitalier',
    ],
    'sizes'    => [
        'services'            => 4,
        'chambres_par_service'=> 8,
        'lits_par_chambre'    => 2,
        'medecins'            => 12,
        'personnels'          => 30,
        'salles_bloc'         => 3,
        'patients_initiaux'   => 40,
        'sejours_en_cours'    => 15,
    ],
    'rates'    => [
        'arrivals_per_day'   => 6,      // nouvelles admissions / jour
        'avg_sejour_days'    => 5,      // durée moyenne de séjour (jitter ±)
        'transfer_rate'      => 0.15,   // fraction des séjours en cours transférés / jour
        'consults_per_day'   => 20,     // consultations / jour
        'operations_per_day' => 4,      // interventions au bloc / jour
        'constantes_per_day' => 3,      // relevés de constantes / séjour / jour
    ],
];
