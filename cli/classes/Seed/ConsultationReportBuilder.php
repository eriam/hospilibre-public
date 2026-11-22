<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Cabinet\CConsultation;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\PlanningOp\COperation;

/**
 * Le compte rendu de la consultation pré-anesthésique, et le texte de la consultation elle-même.
 *
 * ⚠ CE QUI MANQUAIT, ET POURQUOI CE N'ÉTAIT PAS VISIBLE. La chaîne pré-anesthésique écrivait la
 * consultation avec son seul motif — « Consultation pré-anesthésique » — et AUCUN des champs que
 * l'écran de consultation affiche : ni examen clinique, ni conclusion, ni remarques. Elle n'écrivait
 * pas non plus de document. Le dossier d'anesthésie, lui, était rempli depuis le 2026-10-07 : en
 * ouvrant un patient on voyait donc un bilan biologique complet et, juste à côté, une consultation
 * vide. L'incohérence n'était pas dans les données mais dans ce qu'elles couvraient.
 *
 * DEUX ÉCRITURES DISTINCTES, parce que l'application les montre à deux endroits :
 *
 *   — LES CHAMPS DE LA CONSULTATION (`examen`, `conclusion`, `rques`) s'affichent dans l'écran de
 *     consultation du cabinet. C'est là que le praticien saisit, et c'est là qu'un formateur
 *     regarde.
 *   — LE COMPTE RENDU (`CCompteRendu` accroché à la `CConsultation`) s'affiche dans l'onglet des
 *     documents du dossier. C'est le document qui circule, celui qu'on imprime ou qu'on envoie au
 *     chirurgien.
 *
 * Les réunir en un seul endroit ferait disparaître l'un des deux écrans du jeu de données.
 *
 * ⚠ LE COMPTE RENDU REPREND LES VALEURS DU BILAN, et c'est tout l'intérêt de l'ordre d'appel : le
 * bilan est tiré AVANT que la consultation ne soit écrite, donc le document peut porter dans son
 * en-tête factuel l'hémoglobine, l'INR et la créatinine qui figurent dans le dossier d'anesthésie.
 * Les deux ne peuvent pas se contredire puisqu'ils lisent le même tirage. Un document qui
 * annoncerait une autre hémoglobine que le dossier est exactement le défaut que la séparation
 * faits/prose existe pour empêcher.
 *
 * LA PROSE VIENT DU CORPUS et suit la même bande « sain » / « altéré » que le reste du dossier : un
 * examen clinique « sans particularité » chez un patient classé ASA IV se contredirait avec sa
 * propre conclusion.
 */
final class ConsultationReportBuilder
{
    /** Le nom du document, tel qu'il apparaît dans l'onglet des documents. */
    private const NAME = 'Compte rendu de consultation pré-anesthésique';

    /** @var SeedContext */
    private $context;

    /** @var array<string, int> */
    private $counters = ['comptes_rendus' => 0, 'consultations_redigees' => 0];

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
     * Pose le texte sur la consultation, AVANT son écriture.
     *
     * ⚠ APPELÉ AVANT `store()` ET NON APRÈS : les trois champs appartiennent à la consultation
     * elle-même. Les poser après son écriture obligerait à l'enregistrer une seconde fois, ce qui
     * double le coût et fait passer l'objet deux fois dans `check()` pour rien.
     *
     * @param array<string, string|float|int> $workup Bilan déjà tiré, pour choisir la bande
     *
     * @throws SeedException
     */
    public function fillConsultation(
        CConsultation $consultation,
        COperation $operation,
        array $workup,
        SeedRandom $random
    ): void {
        if (!ReportCorpus::isAvailable()) {
            return;
        }

        $band = ($workup['_altere'] ?? '0') === '1' ? 'altere' : 'sain';

        $consultation->examen     = $this->fill(
            (string)$random->pick(ReportCorpus::band('consultation_examens', $band)),
            $operation
        );
        $consultation->conclusion = $this->fill(
            (string)$random->pick(ReportCorpus::band('consultation_conclusions', $band)),
            $operation
        );
        $consultation->rques      = $this->fill(
            (string)$random->pick(ReportCorpus::section('consultation_informations')),
            $operation
        );

        $this->counters['consultations_redigees']++;
    }

    /**
     * Écrit le document, APRÈS l'écriture de la consultation.
     *
     * ⚠ APRÈS, ET POUR LA RAISON INVERSE DE `fillConsultation()` : `CCompteRendu::object_id` exige
     * l'identifiant de la consultation. C'est la même contrainte qui avait laissé 336
     * `operation_id` nuls sur les dossiers d'anesthésie, et les deux méthodes de cette classe
     * tombent de part et d'autre du même `store()`.
     *
     * @param array<string, string|float|int> $workup
     *
     * @throws SeedException
     */
    public function writeReport(
        CConsultation $consultation,
        COperation $operation,
        string $author_id,
        string $consultation_date,
        array $workup,
        SeedRandom $random
    ): void {
        if (!$consultation->_id || !ReportCorpus::isAvailable()) {
            return;
        }

        $band = ($workup['_altere'] ?? '0') === '1' ? 'altere' : 'sain';

        $paragraphs = [
            $this->fill((string)$random->pick(ReportCorpus::band('consultation_examens', $band)), $operation),
            $this->fill((string)$random->pick(ReportCorpus::section('consultation_informations')), $operation),
            $this->fill((string)$random->pick(ReportCorpus::band('consultation_conclusions', $band)), $operation),
        ];

        $report               = new CCompteRendu();
        $report->object_id    = $consultation->_id;
        $report->object_class = 'CConsultation';
        $report->nom          = self::NAME;
        $report->type         = 'body';
        $report->author_id    = $author_id ?: null;
        $report->valide       = 1;
        $report->_source      = $this->html($this->facts($consultation_date, $operation, $workup), $paragraphs);

        $this->context->write($report);

        $this->counters['comptes_rendus']++;
    }

    /**
     * L'en-tête factuel : la date, l'intervention prévue, la classe ASA et le bilan.
     *
     * RIEN QUI NE SOIT DÉJÀ DANS LE DOSSIER. Chaque ligne est relisible contre la base : la date
     * vient de la consultation, l'intervention et l'ASA de l'opération, les valeurs du bilan du même
     * tirage que le dossier d'anesthésie. Un relecteur peut donc confronter le document au dossier
     * ligne à ligne, ce qui est le seul contrôle qui vaille sur un jeu de données de démonstration.
     *
     * @param array<string, string|float|int> $workup
     *
     * @return array<string, string>
     */
    private function facts(string $consultation_date, COperation $operation, array $workup): array
    {
        $facts = [];

        // ⚠ LA DATE EST REÇUE, PAS RELUE SUR LA CONSULTATION. Elle vit sur la PLAGE
        // (`CPlageconsult::date`) et non sur la consultation, dont `_ref_plageconsult` n'est pas
        // chargée après `store()`. La lire sur place aurait donc rendu une date vide, ou coûté une
        // requête par consultation là où l'appelant la connaît déjà.
        if ($consultation_date !== '') {
            $facts['Date de la consultation'] = $consultation_date;
        }

        $facts['Intervention prévue'] = (string)$operation->libelle;
        $facts['Date prévue']         = (string)$operation->date;

        if ($operation->ASA) {
            $facts['Classe ASA'] = (string)$operation->ASA;
        }

        // LE BILAN EN UNITÉS, parce qu'une valeur sans unité n'est pas une mesure. Les unités sont
        // celles des champs de `CConsultAnesth`, et non celles qu'un lecteur supposerait : la
        // créatinine y est en milligrammes par litre, pas en micromoles.
        $units = [
            'hb'          => ['Hémoglobine', 'g/dL'],
            'inr'         => ['INR', ''],
            'tp'          => ['Taux de prothrombine', '%'],
            'creatinine'  => ['Créatinine', 'mg/L'],
            'fibrinogene' => ['Fibrinogène', 'g/L'],
        ];

        foreach ($units as $field => [$label, $unit]) {
            if (!isset($workup[$field]) || $workup[$field] === '') {
                continue;
            }

            $facts[$label] = trim((string)$workup[$field] . ' ' . $unit);
        }

        if (isset($workup['rai'])) {
            $facts['Agglutinines irrégulières'] = (string)$workup['rai'];
        }

        if (($workup['_anticoagule'] ?? '0') === '1') {
            // DIT EXPLICITEMENT PARCE QUE L'INR SEUL NE SUFFIT PAS : un INR à deux et demi se lit
            // comme un trouble de l'hémostase si rien ne dit qu'il est voulu.
            $facts['Traitement anticoagulant'] = 'en cours';
        }

        return $facts;
    }

    /**
     * Les substitutions admises, pour une consultation.
     *
     * LA MÊME LISTE FERMÉE QUE POUR LES COMPTES RENDUS OPÉRATOIRES, et pour la même raison : une
     * substitution non remplie resterait telle quelle — « patient classé {asa} » — dans le dossier
     * du patient. Les sections de consultation n'emploient que `{asa}` et `{acte}` ; les autres sont
     * remplies à vide plutôt que laissées, pour qu'un fragment ajouté plus tard ne puisse pas fuir.
     */
    private function fill(string $fragment, COperation $operation): string
    {
        $asa = $operation->ASA ? 'ASA ' . $this->roman((int)$operation->ASA) : 'ASA non renseignée';

        return strtr(
            $fragment,
            [
                '{acte}'         => (string)$operation->libelle,
                '{asa}'          => $asa,
                '{duree}'        => '',
                '{installation}' => '',
                '{chirurgien}'   => '',
                '{anesthesiste}' => '',
            ]
        );
    }

    /**
     * @param array<string, string> $facts
     * @param string[]              $paragraphs
     */
    private function html(array $facts, array $paragraphs): string
    {
        $html = '<h1>' . $this->escape(self::NAME) . '</h1><table>';

        foreach ($facts as $label => $value) {
            $html .= '<tr><th style="text-align:left">' . $this->escape($label) . '</th><td>'
                . $this->escape($value) . '</td></tr>';
        }

        $html .= '</table>';

        foreach ($paragraphs as $paragraph) {
            $html .= '<p>' . $this->escape($paragraph) . '</p>';
        }

        return $html;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function roman(int $asa): string
    {
        $romans = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'];

        return $romans[$asa] ?? (string)$asa;
    }
}
