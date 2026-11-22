<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

use Ox\Cli\Seed\CommuneDictionary;
use Ox\Cli\Seed\FrenchCoding;
use Ox\Cli\Seed\SeedException;

/**
 * Une CLINIQUE CHIRURGICALE privée : pas d'urgences, de l'ambulatoire, des séjours courts.
 *
 * Ce décor existe pour une raison précise : un point d'extension qui n'a qu'une seule implémentation
 * n'est pas vérifié. Celui-ci en est un second, VOLONTAIREMENT DIFFÉRENT sur tout ce que l'API prétend
 * rendre variable — le catalogue d'unités, les proportions cliniques, l'organisation du bloc, le
 * nommage — et il ne réutilise rien du décor hospitalier.
 *
 * CE QU'IL EXERCE, et que le décor hospitalier ne pouvait pas exercer :
 *
 * - un établissement SANS unité d'accueil des urgences, donc sans séjour urgent. Le mix de types doit
 *   alors être cohérent avec le catalogue, et c'est vérifié : un décor qui annoncerait des séjours
 *   urgents sans unité capable de les héberger est refusé par le planificateur, parce que le socle
 *   refuserait leur affectation ;
 * - un bloc qui prend TROIS interventions par vacation plutôt que deux, ce que permettent des actes
 *   plus courts — la cohérence entre durée maximale et capacité annoncée est contrôlée ;
 * - une population qui revient moins souvent, un horizon de programmation plus proche, et des bandes
 *   d'occupation plus basses : une clinique ne tourne pas comme un CHU.
 */
final class SurgicalClinicDecor implements SeedDecor
{
    public const NAME = 'clinique-chirurgicale';

    /**
     * Les unités, dans l'ordre d'ouverture. Aucune n'accueille les urgences, et la dernière est une
     * surveillance continue post-opératoire — l'équivalent, pour une clinique, d'un lit de réanimation.
     *
     * @var array<int, array{slug: string, service: string, fonction: string, surgical: bool, intensive: bool}>
     */
    private const UNITS = [
        ['slug' => 'clinique-ambulatoire', 'service' => 'Chirurgie ambulatoire', 'fonction' => 'Chirurgie ambulatoire', 'surgical' => true, 'intensive' => false],
        ['slug' => 'clinique-orthopedie', 'service' => 'Orthopédie', 'fonction' => 'Chirurgie orthopédique', 'surgical' => true, 'intensive' => false],
        ['slug' => 'clinique-viscerale', 'service' => 'Chirurgie viscérale', 'fonction' => 'Chirurgie viscérale', 'surgical' => true, 'intensive' => false],
        ['slug' => 'clinique-ophtalmologie', 'service' => 'Ophtalmologie', 'fonction' => 'Ophtalmologie', 'surgical' => true, 'intensive' => false],
        ['slug' => 'clinique-medecine', 'service' => 'Médecine post-opératoire', 'fonction' => 'Médecine polyvalente', 'surgical' => false, 'intensive' => false],
        ['slug' => 'clinique-surveillance', 'service' => 'Surveillance continue', 'fonction' => 'Surveillance continue', 'surgical' => false, 'intensive' => true],
    ];

    /**
     * Actes par unité chirurgicale. Plus courts que ceux d'un centre hospitalier, ce qui est la raison
     * pour laquelle une vacation en absorbe trois.
     *
     * @var array<string, string[]>
     */
    private const PROCEDURES = [
        'clinique-ambulatoire'   => [
            'Exérèse de lipome', 'Cure de hernie ombilicale', 'Ablation de kyste sébacé',
            'Biopsie ganglionnaire', 'Cure de canal carpien',
        ],
        'clinique-orthopedie'    => [
            'Arthroscopie du genou', 'Ablation de matériel d\'ostéosynthèse', 'Cure de doigt à ressaut',
            'Exérèse de kyste synovial', 'Arthroscopie de l\'épaule',
        ],
        'clinique-viscerale'     => [
            'Cure de hernie inguinale', 'Cholécystectomie sous cœlioscopie', 'Cure d\'hémorroïdes',
            'Exérèse de kyste sacro-coccygien',
        ],
        'clinique-ophtalmologie' => [
            'Phacoémulsification avec implant', 'Chirurgie du ptérygion', 'Chirurgie du strabisme',
            'Injection intravitréenne',
        ],
    ];

    /** @var SpecialityCatalogue|null */
    private $catalogue;

    /** @var ClinicalMix|null */
    private $mix;

    /** @var ClinicalCoding|null */
    private $coding;

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Clinique chirurgicale privée : ambulatoire dominant, séjours courts, pas de service'
            . " d'urgences, une surveillance continue post-opératoire.";
    }

    /**
     * @throws SeedException
     */
    public function catalogue(): SpecialityCatalogue
    {
        return $this->catalogue = $this->catalogue ?? new ArrayCatalogue(
            self::UNITS,
            self::PROCEDURES,
            // L'ophtalmologie de cataracte opère une population âgée : c'est le seul profil
            // d'admission qui sorte de l'ordinaire ici.
            ['clinique-ophtalmologie' => ['min_age' => 50]],
            [],
            [],
            [],
            'Anesthésie',
            [18, 95]
        );
    }

    /**
     * @throws SeedException
     */
    public function clinicalMix(): ClinicalMix
    {
        return $this->mix = $this->mix ?? new ClinicalMix(
            [
                // PAS DE SÉJOUR URGENT : aucune unité ne pourrait l'héberger, et le socle refuserait
                // son affectation. Les dix points du mix hospitalier vont à l'ambulatoire, qui est le
                // cœur de l'activité d'une clinique.
                'type_mix' => [
                    'ambu'    => 60,
                    'comp'    => 25,
                    'exte'    => 10,
                    'consult' => 5,
                ],
                // Une hospitalisation de clinique se compte en jours, pas en semaines.
                'durations'    => [
                    'comp'    => [1, 5],
                    'ambu'    => [0, 0],
                    'exte'    => [0, 0],
                    'consult' => [0, 0],
                ],
                'icu_duration' => [1, 3],
                // Une clinique programme et ne subit pas : elle tourne moins tendu qu'un hôpital.
                'occupancy_band'     => [65, 80],
                'icu_occupancy_band' => [40, 60],
                // Presque tout séjour chirurgical est opéré : c'est la raison d'être de l'établissement.
                'surgical_operation_rate' => 950,
                'operation_rate'          => 95,
                // Des actes courts : trois par vacation de cinq heures, et la borne haute le garantit.
                'operation_minutes'   => [30, 100],
                'operations_per_slot' => 3,
                // Un patient de clinique revient rarement.
                'sejours_per_patient' => 150,
                // Et la programmation se fait à quelques semaines.
                'future_window_divisor' => 6,
                // Une équipe resserrée.
                'practitioners_per_service' => 2,
                'anaesthesia_spare'         => 1,
            ]
        );
    }

    public function identities(): IdentitySource
    {
        return new FileIdentities();
    }

    public function geography(): ?GeographySource
    {
        // Une clinique française recrute dans son territoire comme un hôpital : même dictionnaire.
        return new CommuneDictionary();
    }

    /**
     * Une clinique française code dans la même nomenclature qu'un hôpital français : la table est
     * indexée par libellé d'acte, donc les actes que les deux décors partagent — canal carpien, hernie
     * ombilicale, phacoémulsification — sont codés une seule fois.
     *
     * @throws SeedException
     */
    public function coding(): ?ClinicalCoding
    {
        return $this->coding = $this->coding ?? FrenchCoding::coding();
    }

    public function naming(): EstablishmentNaming
    {
        return new PatronNaming(
            'Clinique',
            ['du Val-Fleuri', 'des Tilleuls', 'Sainte-Colombe', 'du Parc-Ombragé', 'de la Roseraie'],
            'CL'
        );
    }

    public function builders(): SeedBuilders
    {
        return new StandardBuilders();
    }
}
