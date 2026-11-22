<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Le bilan pré-anesthésique : des valeurs structurées, corrélées au terrain.
 *
 * POURQUOI CETTE CLASSE EXISTE. La chaîne pré-anesthésique livrée le 2026-10-04 écrivait la
 * COQUILLE du dossier — qui a consulté, quand, pour quelle intervention — et AUCUN de son contenu.
 * Mesuré après coup, sur les 349 dossiers produits : 349 sans antécédents, 349 sans traitements,
 * 349 sans prémédication, 349 sans bilan. Le cadre « Infos. Anesth. » de l'application affichait
 * donc un formulaire entièrement vide, ce qui se lit à l'écran comme « rien ne s'affiche » — c'est
 * ainsi qu'eriam l'a signalé. L'écran n'était pas en cause.
 *
 * ⚠ LES VALEURS SONT CORRÉLÉES À LA CLASSE ASA, ET C'EST LE POINT. Tirer une hémoglobine
 * uniformément entre 8 et 17 donnerait des anémies chez des patients ASA I et des bilans parfaits
 * chez des ASA IV — un dossier incohérent avec lui-même, et c'est précisément ce qu'un formateur
 * montre pour dire qu'un jeu de données ne vaut rien. Le terrain décide donc des intervalles.
 *
 * LES BORNES SONT DES VALEURS DE RÉFÉRENCE USUELLES, pas une distribution publiée : un adulte sain
 * a une hémoglobine de 13 à 17 g/dL chez l'homme et 12 à 16 chez la femme, un INR de 0,8 à 1,2 hors
 * anticoagulant, un taux de prothrombine de 70 à 100 %, un fibrinogène de 2 à 4 g/L, une
 * créatinine de 6 à 12 mg/L. Les classes ASA élevées déplacent ces intervalles vers l'anomalie,
 * sans les rendre caricaturaux. C'est un choix de vraisemblance assumé, et il est ICI pour être
 * corrigé par quelqu'un qui connaît mieux — pas dispersé dans le code qui écrit.
 *
 * ⚠ CE QU'ELLE NE FAIT PAS : de la prose. Les champs de texte libre — antécédents, traitements,
 * tabac, œnolisme, prémédication, préparation — viennent du corpus, qui interdit les chiffres. La
 * séparation est la même que pour les comptes rendus : les faits sont des valeurs, le récit est un
 * fragment, et ils ne se mélangent jamais.
 */
final class PreAnaesthesiaWorkup
{
    /**
     * Hémoglobine, en dixièmes de g/dL pour rester en entiers.
     *
     * Par sexe puis par gravité : un ASA III-IV est plus souvent anémié, ce qui est l'une des
     * raisons de la consultation pré-anesthésique.
     *
     * @var array<string, array<string, array{int, int}>>
     */
    private const HAEMOGLOBIN = [
        'm' => ['sain' => [130, 170], 'altere' => [95, 135]],
        'f' => ['sain' => [120, 160], 'altere' => [90, 125]],
    ];

    /** INR en millièmes : 0,800 à 1,200 hors anticoagulant ; 2,000 à 3,000 sous AVK. */
    private const INR_NORMAL = [800, 1200];
    private const INR_ANTICOAGULATED = [2000, 3000];

    /**
     * Part, en pour mille, des patients sous anticoagulant oral — seulement parmi les ASA III-IV.
     *
     * Chez un ASA I-II l'anticoagulation est rare et sa présence interrogerait le classement
     * lui-même ; on ne la tire donc pas du tout pour eux.
     */
    private const ANTICOAGULATED_PER_MILLE = 220;

    /** Taux de prothrombine, en pourcent. Le champ accepte jusqu'à 140. */
    private const PROTHROMBIN = ['sain' => [75, 100], 'altere' => [45, 85]];

    /** TCA en secondes : le champ est un numchar de DEUX caractères, donc 10 à 99. */
    private const APTT = ['sain' => [26, 36], 'altere' => [34, 52]];

    /** Témoin du TCA : la valeur du laboratoire, stable. */
    private const APTT_CONTROL = [29, 33];

    /** Créatinine en dixièmes de mg/L : 6,0 à 12,0 normal, jusqu'à 28,0 en insuffisance rénale. */
    private const CREATININE = ['sain' => [60, 120], 'altere' => [110, 280]];

    /** Fibrinogène en dixièmes de g/L : 2,0 à 4,0 normal. */
    private const FIBRINOGEN = ['sain' => [20, 40], 'altere' => [18, 55]];

    /** Part, en pour mille, de recherches d'agglutinines irrégulières POSITIVES. */
    private const RAI_POSITIVE_PER_MILLE = 25;

    /** Part, en pour mille, de sérologies d'hépatite positives. */
    private const HEPATITIS_POSITIVE_PER_MILLE = 15;

    /** Part, en pour mille, de sérologies non faites — le « ? » du dictionnaire. */
    private const HEPATITIS_UNKNOWN_PER_MILLE = 180;

    /** À partir de cette classe ASA, le terrain est considéré comme altéré. */
    private const ALTERED_FROM_ASA = 3;

    /**
     * Le bilan d'un patient, prêt à être posé sur le dossier d'anesthésie.
     *
     * @param string $sex « m » ou « f »
     * @param int    $asa Classe ASA de 1 à 4, 0 si inconnue
     *
     * @return array<string, string|float|int>
     * @throws SeedException
     */
    public static function forPatient(SeedRandom $random, string $sex, int $asa): array
    {
        // ASA INCONNUE = TERRAIN SAIN, et non « altéré par défaut ». Un bilan pathologique posé
        // faute de classement serait une information inventée, pas une précaution.
        $altered = $asa >= self::ALTERED_FROM_ASA;
        $band    = $altered ? 'altere' : 'sain';

        $haemoglobin = self::HAEMOGLOBIN[$sex] ?? self::HAEMOGLOBIN['m'];

        // L'ANTICOAGULATION EST TIRÉE AVANT L'INR, parce qu'elle le détermine : un INR à 2,5 chez un
        // patient sans anticoagulant décrirait un trouble de l'hémostase que rien d'autre dans le
        // dossier ne soutiendrait.
        $anticoagulated = $altered && $random->chanceInThousand(self::ANTICOAGULATED_PER_MILLE);
        $inr_band       = $anticoagulated ? self::INR_ANTICOAGULATED : self::INR_NORMAL;

        return [
            'hb'           => self::tenths($random, $haemoglobin[$band]),
            'inr'          => self::thousandths($random, $inr_band),
            'tp'           => (string)$random->int(...self::PROTHROMBIN[$band]),
            'tca'          => (string)$random->int(...self::APTT[$band]),
            'tca_temoin'   => (string)$random->int(...self::APTT_CONTROL),
            'creatinine'   => self::tenths($random, self::CREATININE[$band]),
            'fibrinogene'  => self::tenths($random, self::FIBRINOGEN[$band]),
            'rai'          => $random->chanceInThousand(self::RAI_POSITIVE_PER_MILLE) ? 'POS' : 'NEG',
            'hepatite_b'   => self::serology($random),
            'hepatite_c'   => self::serology($random),
            '_anticoagule' => $anticoagulated ? '1' : '0',
            '_altere'      => $altered ? '1' : '0',
        ];
    }

    /**
     * Une sérologie : négative le plus souvent, parfois non faite, rarement positive.
     *
     * LE « ? » EST UNE VALEUR DU DICTIONNAIRE, pas un trou : il signifie « non recherchée », ce qui
     * arrive pour une intervention programmée sans facteur de risque. Le remplacer par NEG
     * affirmerait un résultat qu'on n'a pas.
     *
     * @throws SeedException
     */
    private static function serology(SeedRandom $random): string
    {
        if ($random->chanceInThousand(self::HEPATITIS_POSITIVE_PER_MILLE)) {
            return 'POS';
        }

        return $random->chanceInThousand(self::HEPATITIS_UNKNOWN_PER_MILLE) ? '?' : 'NEG';
    }

    /**
     * Tire dans un intervalle exprimé en dixièmes et rend la valeur décimale.
     *
     * EN ENTIERS PUIS DIVISÉ, parce que `SeedRandom::int()` est le seul tirage reproductible dont on
     * dispose : tirer un flottant demanderait un autre chemin, donc un autre flux.
     *
     * @param array{int, int} $band
     *
     * @throws SeedException
     */
    private static function tenths(SeedRandom $random, array $band): float
    {
        return $random->int($band[0], $band[1]) / 10;
    }

    /**
     * @param array{int, int} $band
     *
     * @throws SeedException
     */
    private static function thousandths(SeedRandom $random, array $band): float
    {
        return $random->int($band[0], $band[1]) / 1000;
    }
}
