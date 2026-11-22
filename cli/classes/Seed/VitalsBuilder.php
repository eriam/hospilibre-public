<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Patients\CConstantesMedicales;
use Hospilibre\PlanningOp\CSejour;
use Ox\Core\CMbDT;

/**
 * Les constantes du séjour : poids, taille, pouls, tension, température, saturation.
 *
 * CE QUI MANQUAIT. Le semis produisait des séjours, des interventions, des observations et des
 * transmissions — et ZÉRO constante. Mesuré : 0 ligne dans `constantes_medicales`. Or c'est la
 * première chose qu'un soignant regarde en ouvrant un dossier, et la courbe de surveillance est
 * l'écran le plus montré d'un dossier d'hospitalisation. Un dossier sans constante ne ressemble à
 * rien, exactement comme le dossier d'anesthésie sans bilan.
 *
 * ⚠ LA TENSION S'ÉCRIT « systole|diastole » EN CENTIMÈTRES DE MERCURE, et les deux points comptent.
 * Le séparateur est une BARRE VERTICALE, pas un slash : `CConstantesMedicales` fait
 * `explode('|', $this->ta)` pour calculer la tension artérielle moyenne, et un « 120/80 » y
 * donnerait une valeur unique illisible. L'unité est le centimètre de mercure, ce que deux indices
 * concordants établissent — le maximum déclaré de `_ta_systole` est 50, et le critère qSOFA du code
 * compare la systolique à 10, seuil qui vaut 100 mmHg. Donc « 12|8 » pour 120/80.
 *
 * LES VALEURS SUIVENT TROIS CHOSES, parce qu'une constante tirée au hasard trahit le jeu de données
 * aussi sûrement qu'un bilan incohérent :
 *
 *   — L'ÂGE. Un enfant a un pouls plus rapide et une tension plus basse qu'un adulte ; les mêmes
 *     bornes pour tous donneraient des bradycardies à six ans.
 *   — LE TERRAIN, par la classe ASA de l'intervention quand il y en a une. Un ASA III-IV est plus
 *     souvent hypertendu et moins bien saturé.
 *   — LE MOMENT. Les premières heures après une intervention montrent un pouls plus rapide, une
 *     fébricule et une saturation plus basse. C'est le relevé que la surveillance
 *     post-interventionnelle produit, et son absence se verrait.
 *
 * LES BORNES SONT DES VALEURS USUELLES, pas une distribution publiée : pouls de repos de 60 à 90
 * chez l'adulte, tension de 110-140 sur 65-85 mmHg, température de 36,2 à 37,2 °C, saturation de 95
 * à 99 %. Elles sont ici pour être corrigées par quelqu'un qui connaît mieux.
 */
final class VitalsBuilder
{
    /** Au plus un relevé par jour de séjour, et jamais plus que cela. */
    private const MAX_PER_STAY = 6;

    /** Heures du relevé quotidien : le tour du matin. */
    private const MORNING_FROM = 6;
    private const MORNING_TO   = 9;

    /** Âge à partir duquel on emploie les bornes de l'adulte. */
    private const ADULT_AGE = 15;

    /** @var array<string, int> */
    private $counters = ['releves' => 0, 'sejours' => 0];

    /** @var SeedContext */
    private $context;

    public function __construct(SeedContext $context)
    {
        $this->context = $context;
    }

    /**
     * @return array<string, int>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * Écrit les constantes d'un séjour.
     *
     * @param string|null $operated Instant de l'intervention, ou null si le séjour n'en a pas
     *
     * @throws SeedException
     */
    public function build(CSejour $sejour, SejourPlan $plan, int $age, int $asa, ?string $operated): void
    {
        $bounds = $plan->stayBounds();

        if ($bounds === null || !$sejour->_id || !$sejour->patient_id) {
            return;
        }

        [$from, $to] = $bounds;
        $random      = $this->context->stream('constantes');

        // POIDS ET TAILLE UNE SEULE FOIS, au premier relevé : ils ne changent pas d'un jour à
        // l'autre, et les répéter donnerait un patient qui grandit pendant son séjour.
        $first = true;

        foreach ($this->moments($random, $from, $to, $operated) as $moment) {
            $constantes                = new CConstantesMedicales();
            $constantes->patient_id    = $sejour->patient_id;
            $constantes->context_class = 'CSejour';
            $constantes->context_id    = $sejour->_id;
            $constantes->datetime      = $moment;

            // POSTOPÉRATOIRE IMMÉDIAT : dans les six heures qui suivent l'intervention. C'est là que
            // le pouls s'accélère et que la fébricule apparaît ; au-delà, le patient est revenu à
            // son état de base.
            $post_op = $operated !== null
                && strtotime($moment) >= strtotime($operated)
                && strtotime($moment) <= strtotime($operated) + (6 * 3600);

            $this->fill($constantes, $random, $age, $asa, $post_op, $first);

            $this->context->write($constantes);
            $this->counters['releves']++;

            $first = false;
        }

        $this->counters['sejours']++;
    }

    /**
     * Les instants de relevé : l'admission, puis le tour du matin, plus la sortie de salle.
     *
     * ⚠ BORNÉ À SIX, et pas par souci de place : chaque `CConstantesMedicales::store()` recalcule
     * l'indice de masse corporelle, le qSOFA et les unités, ce qui n'est pas gratuit. Un séjour de
     * trois semaines produirait vingt relevés pour une valeur pédagogique nulle — la courbe se lit
     * aussi bien sur six points.
     *
     * @return string[]
     * @throws SeedException
     */
    private function moments(SeedRandom $random, string $from, string $to, ?string $operated): array
    {
        $moments = [$from];

        if ($operated !== null) {
            // Deux heures après l'incision : le patient est en surveillance post-interventionnelle.
            $moments[] = CMbDT::dateTime('+2 hours', $operated);
        }

        $day   = CMbDT::date('+1 day', $from);
        $limit = CMbDT::date(null, $to);

        while ($day <= $limit && count($moments) < self::MAX_PER_STAY) {
            $moments[] = $day . sprintf(
                ' %02d:%02d:00',
                $random->int(self::MORNING_FROM, self::MORNING_TO),
                $random->int(0, 59)
            );

            $day = CMbDT::date('+1 day', $day);
        }

        // TRIÉS, parce qu'une courbe de surveillance se lit dans l'ordre et que l'instant
        // postopératoire s'insère au milieu des tours du matin.
        sort($moments);

        return $moments;
    }

    /**
     * @throws SeedException
     */
    private function fill(
        CConstantesMedicales $constantes,
        SeedRandom $random,
        int $age,
        int $asa,
        bool $post_op,
        bool $with_morphology
    ): void {
        $child   = $age < self::ADULT_AGE;
        $altered = $asa >= 3;

        // POULS : plus rapide chez l'enfant, plus rapide encore après une intervention.
        $pulse = $child ? [85, 115] : ($post_op ? [80, 110] : [58, 88]);

        // TENSION en cmHg. L'enfant est plus bas, le patient à terrain altéré plus haut.
        if ($child) {
            $systolic = [9, 12];
            $diastolic = [5, 7];
        } elseif ($altered) {
            // PLAFONNÉE À 16 cmHg, qui est le maximum de la bande déclarée par la classe pour cette
            // constante. Au-delà, la valeur reste acceptée par le champ — dont le maximum est 50 —
            // mais sort de l'échelle du graphique de surveillance, où elle s'afficherait hors cadre.
            $systolic = [13, 16];
            $diastolic = [7, 10];
        } else {
            $systolic = [11, 14];
            $diastolic = [6, 9];
        }

        // ⚠ LA TENSION S'ÉCRIT PAR SES CHAMPS DE FORMULAIRE, JAMAIS PAR `ta` DIRECTEMENT, et c'est
        // un défaut mesuré, pas une précaution : `updatePlainFields()` RÉASSEMBLE `ta` depuis
        // `_ta_systole` et `_ta_diastole` à chaque écriture, et l'écrase à la chaîne vide quand ils
        // sont absents (ligne 2330 de la classe). La première version posait « 12|8 » sur `ta` et
        // l'ORM l'effaçait sans rien dire — 3 971 relevés écrits, pouls, température et saturation
        // présents, et ZÉRO tension. Il n'y avait ni erreur ni avertissement : la courbe de tension
        // était simplement plate.
        //
        // ET LA CONVERSION EST DÉSACTIVÉE, parce que l'unité des champs de formulaire dépend de la
        // configuration de l'instance (`dPpatients CConstantesMedicales unite_ta`, « cmHg » par
        // défaut mais « mmHg » possible). Sans cela, la même graine produirait 12 cmHg sur une
        // instance et 1,2 sur l'autre. Avec `_convert_value` à faux, les valeurs ci-dessous sont
        // prises telles quelles en centimètres de mercure, quelle que soit la configuration.
        $constantes->_convert_value = false;
        $constantes->_ta_systole    = $random->int(...$systolic);
        $constantes->_ta_diastole   = $random->int(...$diastolic);

        $constantes->pouls       = $random->int(...$pulse);
        $constantes->temperature = $random->int($post_op ? 368 : 362, $post_op ? 381 : 372) / 10;
        $constantes->spo2        = $random->int($post_op || $altered ? 92 : 95, $post_op ? 97 : 99);
        $constantes->frequence_respiratoire = $random->int($post_op ? 14 : 12, $post_op ? 22 : 18);

        if (!$with_morphology) {
            return;
        }

        // MORPHOLOGIE : au premier relevé seulement. Les bornes sont celles d'une population
        // adulte française ; un enfant prend des valeurs grossièrement proportionnées à son âge,
        // faute de courbes de croissance dans le dépôt — c'est une approximation assumée, et elle
        // est visible plutôt que cachée.
        if ($child) {
            $constantes->taille = $random->int(90 + ($age * 5), 110 + ($age * 6));
            $constantes->poids  = $random->int(120 + ($age * 25), 180 + ($age * 35)) / 10;

            return;
        }

        $constantes->taille = $random->int(152, 190);
        $constantes->poids  = $random->int(480, 1000) / 10;
    }
}
