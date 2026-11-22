<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Cli\Seed\Decor\ArrayCatalogue;
use Ox\Cli\Seed\Decor\SpecialityCatalogue;

/**
 * LES DONNÉES du catalogue français, et la fabrique qui les assemble.
 *
 * Cette classe ne contient plus de logique de recherche : celle-ci vit dans `ArrayCatalogue`, partagée
 * par tous les décors. Ici ne restent que les unités d'un centre hospitalier français, leurs actes et
 * leurs règles d'admission — c'est-à-dire le DÉCOR par défaut, et rien d'autre.
 *
 * Un autre décor n'a donc pas à hériter de ceci ni à l'imiter : il décrit ses propres tableaux et les
 * passe au même `ArrayCatalogue`.
 *
 * Catalogue de spécialités, dans l'ordre où un établissement les ouvre.
 *
 * L'ORDRE EST SIGNIFIANT : le profil `small` prend les quatre premières, `medium` les dix
 * premières, et ainsi de suite. Les premières entrées doivent donc composer à elles seules un
 * établissement crédible — une médecine, une chirurgie, un plateau de réanimation — plutôt
 * qu'un échantillon arbitraire. Un `small` qui n'aurait que des spécialités chirurgicales ne
 * permettrait pas d'illustrer une hospitalisation complète ordinaire.
 *
 * `surgical` détermine qui peut opérer : `COperation::chir_id` doit désigner un praticien de
 * fonction chirurgicale, faute de quoi le programme du bloc n'a aucun sens clinique.
 * `intensive` alimente `CService::is_soins_continue`, qui change le comportement applicatif.
 */
final class Specialities
{
    /**
     * @var array<int, array{slug: string, service: string, fonction: string, surgical: bool, intensive: bool}>
     */
    private const CATALOGUE = [
        // Les deux premières sont la PORTE DE L'ÉTABLISSEMENT, et elles sont en tête parce que
        // l'ordre est significatif : sans elles dans les premières spécialités, les profils les plus
        // petits n'auraient pas d'unité capable d'héberger un séjour urgent, et 10 % de leurs séjours
        // resteraient des coquilles vides — un séjour déclaré urgent, sans lit et sans acte.
        ['slug' => 'urgences', 'service' => 'Urgences', 'fonction' => "Médecine d'urgence", 'surgical' => false, 'intensive' => false],
        ['slug' => 'uhcd', 'service' => 'UHCD', 'fonction' => 'Hospitalisation de courte durée', 'surgical' => false, 'intensive' => false],
        ['slug' => 'medecine-interne', 'service' => 'Médecine interne', 'fonction' => 'Médecine interne', 'surgical' => false, 'intensive' => false],
        ['slug' => 'chirurgie-viscerale', 'service' => 'Chirurgie viscérale', 'fonction' => 'Chirurgie viscérale', 'surgical' => true, 'intensive' => false],
        ['slug' => 'cardiologie', 'service' => 'Cardiologie', 'fonction' => 'Cardiologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'reanimation', 'service' => 'Réanimation polyvalente', 'fonction' => 'Réanimation', 'surgical' => false, 'intensive' => true],
        ['slug' => 'chirurgie-orthopedique', 'service' => 'Chirurgie orthopédique', 'fonction' => 'Chirurgie orthopédique', 'surgical' => true, 'intensive' => false],
        ['slug' => 'pneumologie', 'service' => 'Pneumologie', 'fonction' => 'Pneumologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'gastro-enterologie', 'service' => 'Gastro-entérologie', 'fonction' => 'Gastro-entérologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'gynecologie-obstetrique', 'service' => 'Gynécologie-obstétrique', 'fonction' => 'Gynécologie-obstétrique', 'surgical' => true, 'intensive' => false],
        ['slug' => 'neurologie', 'service' => 'Neurologie', 'fonction' => 'Neurologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'soins-intensifs-cardio', 'service' => 'Soins intensifs cardiologiques', 'fonction' => 'Cardiologie interventionnelle', 'surgical' => false, 'intensive' => true],
        ['slug' => 'urologie', 'service' => 'Urologie', 'fonction' => 'Urologie', 'surgical' => true, 'intensive' => false],
        ['slug' => 'orl', 'service' => 'ORL', 'fonction' => 'ORL', 'surgical' => true, 'intensive' => false],
        ['slug' => 'ophtalmologie', 'service' => 'Ophtalmologie', 'fonction' => 'Ophtalmologie', 'surgical' => true, 'intensive' => false],
        ['slug' => 'nephrologie', 'service' => 'Néphrologie', 'fonction' => 'Néphrologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'endocrinologie', 'service' => 'Endocrinologie', 'fonction' => 'Endocrinologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'rhumatologie', 'service' => 'Rhumatologie', 'fonction' => 'Rhumatologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'oncologie', 'service' => 'Oncologie médicale', 'fonction' => 'Oncologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'geriatrie', 'service' => 'Gériatrie aiguë', 'fonction' => 'Gériatrie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'chirurgie-vasculaire', 'service' => 'Chirurgie vasculaire', 'fonction' => 'Chirurgie vasculaire', 'surgical' => true, 'intensive' => false],
        ['slug' => 'medecine-physique', 'service' => 'Médecine physique et réadaptation', 'fonction' => 'Médecine physique', 'surgical' => false, 'intensive' => false],
        ['slug' => 'hematologie', 'service' => 'Hématologie clinique', 'fonction' => 'Hématologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'dermatologie', 'service' => 'Dermatologie', 'fonction' => 'Dermatologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'neurochirurgie', 'service' => 'Neurochirurgie', 'fonction' => 'Neurochirurgie', 'surgical' => true, 'intensive' => false],
        ['slug' => 'chirurgie-thoracique', 'service' => 'Chirurgie thoracique', 'fonction' => 'Chirurgie thoracique', 'surgical' => true, 'intensive' => false],
        ['slug' => 'pediatrie', 'service' => 'Pédiatrie', 'fonction' => 'Pédiatrie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'chirurgie-pediatrique', 'service' => 'Chirurgie pédiatrique', 'fonction' => 'Chirurgie pédiatrique', 'surgical' => true, 'intensive' => false],
        ['slug' => 'infectiologie', 'service' => 'Maladies infectieuses', 'fonction' => 'Infectiologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'chirurgie-plastique', 'service' => 'Chirurgie plastique', 'fonction' => 'Chirurgie plastique', 'surgical' => true, 'intensive' => false],
        ['slug' => 'medecine-polyvalente', 'service' => 'Médecine polyvalente', 'fonction' => 'Médecine polyvalente', 'surgical' => false, 'intensive' => false],
        ['slug' => 'usic', 'service' => 'Unité de surveillance continue', 'fonction' => 'Surveillance continue', 'surgical' => false, 'intensive' => true],
        ['slug' => 'chirurgie-maxillo-faciale', 'service' => 'Chirurgie maxillo-faciale', 'fonction' => 'Chirurgie maxillo-faciale', 'surgical' => true, 'intensive' => false],
        ['slug' => 'diabetologie', 'service' => 'Diabétologie', 'fonction' => 'Diabétologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'hepatologie', 'service' => 'Hépatologie', 'fonction' => 'Hépatologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'algologie', 'service' => 'Traitement de la douleur', 'fonction' => 'Algologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'soins-palliatifs', 'service' => 'Soins palliatifs', 'fonction' => 'Soins palliatifs', 'surgical' => false, 'intensive' => false],
        ['slug' => 'chirurgie-ambulatoire', 'service' => 'Chirurgie ambulatoire', 'fonction' => 'Chirurgie ambulatoire', 'surgical' => true, 'intensive' => false],
        ['slug' => 'medecine-nucleaire', 'service' => 'Médecine nucléaire', 'fonction' => 'Médecine nucléaire', 'surgical' => false, 'intensive' => false],
        ['slug' => 'allergologie', 'service' => 'Allergologie', 'fonction' => 'Allergologie', 'surgical' => false, 'intensive' => false],
        ['slug' => 'chirurgie-bariatrique', 'service' => 'Chirurgie bariatrique', 'fonction' => 'Chirurgie bariatrique', 'surgical' => true, 'intensive' => false],
        ['slug' => 'medecine-vasculaire', 'service' => 'Médecine vasculaire', 'fonction' => 'Médecine vasculaire', 'surgical' => false, 'intensive' => false],
    ];

    /**
     * Actes par spécialité chirurgicale.
     *
     * Le premier jet tirait dans une liste unique, entièrement viscérale : la chirurgie
     * orthopédique réalisait des colectomies et des thyroïdectomies. Un programme opératoire
     * incohérent se repère au premier coup d'œil et discrédite tout le jeu de données — c'est
     * précisément le genre de détail qu'un formateur montre à ses étudiants.
     *
     * Libellés génériques et plausibles : ils servent à rendre un programme lisible, pas à
     * décrire une pratique réelle.
     *
     * @var array<string, string[]>
     */
    private const PROCEDURES = [
        'chirurgie-viscerale'       => [
            'Cholécystectomie sous cœlioscopie', 'Appendicectomie', 'Cure de hernie inguinale',
            'Résection colique segmentaire', "Cure d'éventration", 'Colectomie droite',
            'Gastrectomie partielle', "Cure d'hémorroïdes",
        ],
        'chirurgie-orthopedique'    => [
            'Prothèse totale de hanche', 'Prothèse totale de genou', 'Arthroscopie du genou',
            'Ostéosynthèse de fracture du poignet', 'Ablation de matériel d\'ostéosynthèse',
            'Cure de canal carpien', 'Réparation de la coiffe des rotateurs',
            "Arthrodèse de l'avant-pied",
        ],
        'gynecologie-obstetrique'   => [
            'Césarienne', 'Hystérectomie par voie vaginale', 'Cœlioscopie diagnostique',
            'Conisation du col', 'Kystectomie ovarienne', 'Curetage évacuateur',
            'Ligature de trompes',
        ],
        'urologie'                  => [
            'Résection endoscopique de prostate', 'Urétéroscopie pour calcul',
            'Néphrectomie partielle', 'Cure de varicocèle', 'Pose de sonde double J',
            'Circoncision',
        ],
        'orl'                       => [
            'Amygdalectomie', 'Septoplastie', 'Pose d\'aérateurs transtympaniques',
            'Thyroïdectomie totale', 'Méatotomie maxillaire', 'Adénoïdectomie',
        ],
        'ophtalmologie'             => [
            'Phacoémulsification avec implant', 'Vitrectomie', 'Chirurgie du ptérygion',
            'Trabéculectomie', 'Chirurgie du strabisme',
        ],
        'chirurgie-vasculaire'      => [
            'Éveinage de varices', 'Pontage fémoro-poplité', 'Cure d\'anévrisme de l\'aorte',
            'Endartériectomie carotidienne', "Création de fistule artério-veineuse",
        ],
        'neurochirurgie'            => [
            'Cure de hernie discale lombaire', 'Laminectomie décompressive',
            'Dérivation ventriculo-péritonéale', 'Exérèse de méningiome',
        ],
        'chirurgie-thoracique'      => [
            'Lobectomie pulmonaire', 'Médiastinoscopie', 'Pleurodèse', 'Exérèse de nodule pulmonaire',
        ],
        'chirurgie-pediatrique'     => [
            'Cure de hernie inguinale du nourrisson', 'Orchidopexie', 'Circoncision',
            'Appendicectomie', "Cure d'invagination",
        ],
        'chirurgie-plastique'       => [
            'Abdominoplastie', 'Reconstruction mammaire', 'Exérèse de lésion cutanée',
            'Greffe de peau mince',
        ],
        'chirurgie-maxillo-faciale' => [
            'Extraction de dents de sagesse', 'Ostéotomie maxillaire',
            'Réduction de fracture zygomatique', 'Exérèse de kyste mandibulaire',
        ],
        'chirurgie-bariatrique'     => [
            'Sleeve gastrectomie', 'Bypass gastrique', 'Pose d\'anneau gastrique',
            'Ablation d\'anneau gastrique',
        ],
        'chirurgie-ambulatoire'     => [
            'Exérèse de lipome', 'Cure de hernie ombilicale', 'Ablation de kyste sébacé',
            'Biopsie ganglionnaire',
        ],
    ];

    /** Âge de la majorité, et âge maximal de la population : une seule source pour ces bornes. */
    public const ADULT_AGE = 18;

    public const ADULT_MAX_AGE = 95;

    /**
     * Patients qu'une spécialité admet : tranche d'âge, et sexe quand il est imposé.
     *
     * Seules les EXCEPTIONS figurent ici ; tout ce qui n'y est pas accueille des adultes de tout âge
     * et des deux sexes. Le premier jet n'avait qu'une moitié de cette règle — il interdisait les
     * mineurs là où aucun service ne peut les prendre, mais ne disait pas ce que chaque service
     * admet. Résultat mesuré sur un profil `medium` : 111 hommes sur 240 séjours d'obstétrique, et
     * dix césariennes sur des patients masculins dont un de 93 ans. Une gériatrie recevait des
     * patients de dix-huit ans.
     *
     * @var array<string, array{min_age?: int, max_age?: int, sex?: string}>
     */
    private const PATIENT_PROFILES = [
        'pediatrie'               => ['min_age' => 1, 'max_age' => 17],
        'chirurgie-pediatrique'   => ['min_age' => 1, 'max_age' => 17],
        // L'obstétrique n'admet que des femmes, et l'âge retenu est celui de la période où une
        // grossesse est plausible : une césarienne à 90 ans se remarque au premier coup d'œil.
        'gynecologie-obstetrique' => ['min_age' => self::ADULT_AGE, 'max_age' => 45, 'sex' => FictitiousNames::SEX_FEMALE],
        'geriatrie'               => ['min_age' => 70],
        'soins-palliatifs'        => ['min_age' => 50],
    ];

    /**
     * Spécialités qui accueillent des mineurs.
     *
     * Un établissement qui n'en ouvre aucune n'admet pas d'enfants — c'est le cas de la plupart
     * des profils livrés, qui ne comptent pas la pédiatrie dans leurs premières spécialités. Le
     * premier jet tirait pourtant les âges entre 1 et 95 ans quelle que soit la liste : un enfant
     * de six ans se retrouvait hospitalisé en gériatrie ou en cardiologie.
     *
     * @var string[]
     */
    private const PAEDIATRIC = ['pediatrie', 'chirurgie-pediatrique'];

    /**
     * Actes qui ne concernent qu'un sexe, par libellé.
     *
     * Séparé du catalogue d'actes parce que la contrainte porte sur l'acte et non sur la spécialité :
     * l'urologie et la chirurgie pédiatrique opèrent les deux sexes, mais une résection de prostate
     * ou une orchidopexie sur une patiente est une incohérence que n'importe qui repère.
     *
     * Les actes de gynécologie-obstétrique n'y figurent pas : leur service n'admet déjà que des
     * femmes (cf. PATIENT_PROFILES), la contrainte y serait redondante.
     *
     * @var array<string, string>
     */
    private const PROCEDURE_SEX = [
        'Résection endoscopique de prostate' => FictitiousNames::SEX_MALE,
        'Cure de varicocèle'                 => FictitiousNames::SEX_MALE,
        'Circoncision'                       => FictitiousNames::SEX_MALE,
        'Orchidopexie'                       => FictitiousNames::SEX_MALE,
        'Reconstruction mammaire'            => FictitiousNames::SEX_FEMALE,
    ];

    /**
     * Unités capables d'héberger un séjour urgent, et le drapeau de `CService` qui le leur permet.
     *
     * `CAffectation::check()` refuse un séjour urgent dans un service qui ne porte aucun des quatre
     * drapeaux `urgence`, `uhcd`, `radiologie` ou `obstetrique` : « Emergency stay cant be in non
     * emergency service ». Sans ces unités, les séjours de type `urg` ne pouvaient recevoir aucune
     * affectation, et c'est ce qui les laissait sans lit.
     *
     * @var array<string, string>
     */
    private const EMERGENCY_UNITS = [
        'urgences' => 'urgence',
        'uhcd'     => 'uhcd',
    ];

    /**
     * Clé de la réserve de vacations du BLOC D'URGENCE.
     *
     * Les vacations du bloc programmé appartiennent chacune à une spécialité ; celles du bloc
     * d'urgence appartiennent au chirurgien DE GARDE, quelle que soit sa spécialité. Elles forment
     * donc une réserve à part, que toute intervention en urgence peut utiliser.
     */
    public const EMERGENCY_THEATRE = '@urgences';

    /** Fonction transversale : les anesthésistes ne tiennent pas de service d'hospitalisation. */
    public const FONCTION_ANESTHESIE = 'Anesthésie';

    /**
     * Le catalogue français, assemblé à partir des tableaux ci-dessus.
     *
     * @throws SeedException
     */
    public static function catalogue(): SpecialityCatalogue
    {
        return new ArrayCatalogue(
            self::CATALOGUE,
            self::PROCEDURES,
            self::PATIENT_PROFILES,
            self::EMERGENCY_UNITS,
            self::PAEDIATRIC,
            self::PROCEDURE_SEX,
            self::FONCTION_ANESTHESIE,
            [self::ADULT_AGE, self::ADULT_MAX_AGE]
        );
    }








}
