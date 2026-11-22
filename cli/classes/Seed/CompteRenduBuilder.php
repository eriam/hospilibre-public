<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\PlanningOp\COperation;

/**
 * Les comptes rendus opératoire et anesthésique, assemblés des FAITS et de la PROSE.
 *
 * LA SÉPARATION EST LA RAISON D'ÊTRE DE CETTE CLASSE. Tout ce qui est vérifiable — la date, l'acte,
 * son code CCAM, le chirurgien, l'anesthésiste, la durée, la classe ASA, l'incident éventuel — est
 * lu dans le jeu de données et rendu dans un en-tête factuel. Tout ce qui est récit vient du corpus
 * (`ReportCorpus`), qui ne contient AUCUN chiffre et le prouve à chaque chargement. Un compte rendu
 * ne peut donc pas contredire le dossier auquel il est attaché : il n'a rien à inventer.
 *
 * ⚠ ET IL NE PEUT PAS NON PLUS LE CONTREDIRE SUR L'INCIDENT. C'est le point qui a motivé tout le
 * dispositif : un récit d'hémorragie sur un séjour sans transfusion et sorti à J1 apprend au lecteur
 * que le dossier ment. Le paragraphe d'incident n'est donc écrit QUE si l'incident figure dans les
 * données, et il est écrit DÈS QU'il y figure — le corpus garantit qu'aucun incident du catalogue
 * n'est sans prose.
 *
 * DEUX DOCUMENTS ET NON UN, parce que ce sont deux actes et deux auteurs : le compte rendu
 * opératoire est signé du chirurgien, le compte rendu d'anesthésie de l'anesthésiste. Les réunir
 * ferait signer à l'un ce que l'autre a fait, ce qui n'a aucun sens dans un dossier.
 *
 * ⚠ AUCUN MODÈLE DE LANGAGE N'INTERVIENT ICI, ET C'EST STRUCTUREL. Le semis reste hors ligne et
 * reproductible : à graine égale il produit les mêmes comptes rendus. Le corpus, lui, peut avoir
 * été produit hors ligne par un modèle — c'est un fichier de DONNÉES, versionné, relu et validé
 * avant d'entrer. Appeler un modèle au moment du semis rendrait le jeu de données irreproductible
 * et dépendant d'un service.
 */
final class CompteRenduBuilder
{
    /** Le nom du compte rendu opératoire, tel qu'il apparaît dans le dossier. */
    private const NAME_SURGERY = 'Compte rendu opératoire';

    /** Le nom du compte rendu d'anesthésie. */
    private const NAME_ANAESTHESIA = "Compte rendu d'anesthésie";

    /** @var SeedContext */
    private $context;

    /** @var array<string, int> */
    private $counters = [
        'operatoires'     => 0,
        'anesthesiques'   => 0,
        'actes_generiques' => 0,
        'incidents_narres' => 0,
    ];

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
     * Écrit les comptes rendus de cette intervention.
     *
     * ⚠ APPELÉ APRÈS L'ÉCRITURE DE L'INTERVENTION : un compte rendu s'accroche à elle par
     * `object_id`, qui exige son identifiant. C'est la même contrainte que le dossier d'anesthésie,
     * et elle s'était déjà payée une fois en laissant 336 `operation_id` nuls.
     *
     * @param string|null $incident Nom de l'incident survenu, ou null
     *
     * @throws SeedException
     */
    public function build(COperation $operation, ?string $incident = null): void
    {
        if (!$operation->_id || !ReportCorpus::isAvailable()) {
            return;
        }

        $random = $this->context->stream('comptes-rendus');
        $code   = $operation->codes_ccam ? (string)$operation->codes_ccam : null;

        $this->writeSurgery($operation, $code, $incident, $random);
        $this->writeAnaesthesia($operation, $random);
    }

    /**
     * @throws SeedException
     */
    private function writeSurgery(
        COperation $operation,
        ?string $code,
        ?string $incident,
        SeedRandom $random
    ): void {
        $gestures = ReportCorpus::gesturesFor($code);

        if ($gestures === []) {
            return;
        }

        if (!ReportCorpus::hasOwnGesture($code)) {
            // COMPTÉ, PAS TU : un geste générique est un compte rendu peu spécifique, et le rapport
            // du semis doit pouvoir dire combien d'actes sont dans ce cas. Sans ce compteur, un
            // corpus entièrement générique ressemblerait à un corpus complet.
            $this->counters['actes_generiques']++;
        }

        $paragraphs = [
            $this->fill((string)$random->pick(ReportCorpus::section('ouvertures')), $operation),
            $this->fill((string)$random->pick($gestures), $operation),
        ];

        if ($incident !== null) {
            $fragments = ReportCorpus::incident($incident);

            if ($fragments !== []) {
                $paragraphs[] = $this->fill((string)$random->pick($fragments), $operation);
                $this->counters['incidents_narres']++;
            }
        }

        $paragraphs[] = $this->fill((string)$random->pick(ReportCorpus::section('fermetures')), $operation);

        $this->write(
            $operation,
            self::NAME_SURGERY,
            (string)$operation->chir_id,
            $this->facts($operation, $code, $incident),
            $paragraphs
        );

        $this->counters['operatoires']++;
    }

    /**
     * @throws SeedException
     */
    private function writeAnaesthesia(COperation $operation, SeedRandom $random): void
    {
        if (!$operation->anesth_id) {
            return;
        }

        $paragraphs = [
            $this->fill((string)$random->pick(ReportCorpus::section('anesthesie_ouvertures')), $operation),
            $this->fill((string)$random->pick(ReportCorpus::section('anesthesie_conduites')), $operation),
            $this->fill((string)$random->pick(ReportCorpus::section('anesthesie_reveils')), $operation),
        ];

        $this->write(
            $operation,
            self::NAME_ANAESTHESIA,
            (string)$operation->anesth_id,
            $this->anaesthesiaFacts($operation),
            $paragraphs
        );

        $this->counters['anesthesiques']++;
    }

    /**
     * L'en-tête factuel : rien que des valeurs lues dans le dossier.
     *
     * POURQUOI UN EN-TÊTE SÉPARÉ DU RÉCIT. Un lecteur doit pouvoir confronter le compte rendu au
     * dossier sans lire la prose, et un relecteur doit pouvoir vérifier que le récit ne dit rien
     * d'autre. Mêler les deux rendrait les deux contrôles impossibles.
     *
     * @return array<string, string>
     */
    private function facts(COperation $operation, ?string $code, ?string $incident): array
    {
        $facts = [
            'Date'        => (string)$operation->date,
            'Intervention' => (string)$operation->libelle,
        ];

        if ($code !== null) {
            $facts['Acte CCAM'] = $code;
        }

        $minutes = $this->minutes((string)$operation->temp_operation);

        if ($minutes !== null) {
            $facts['Durée prévue'] = $minutes . ' minutes';
        }

        if ($operation->urgence === '1') {
            $facts['Cadre'] = 'intervention non programmée';
        }

        if ($incident !== null) {
            // LE FAIT, PAS LE RÉCIT : l'incident est nommé ici parce qu'il est dans les données. Le
            // paragraphe qui le raconte vient du corpus, et les deux doivent coexister — l'un sans
            // l'autre serait soit un fait muet, soit un récit non étayé.
            $facts['Incident peropératoire'] = SurgicalIncidents::label($incident);
        }

        return $facts;
    }

    /**
     * @return array<string, string>
     */
    private function anaesthesiaFacts(COperation $operation): array
    {
        $facts = [
            'Date'         => (string)$operation->date,
            'Intervention' => (string)$operation->libelle,
        ];

        if ($operation->ASA) {
            $facts['Classe ASA'] = (string)$operation->ASA;
        }

        if ($operation->date_visite_anesth) {
            $facts['Visite pré-anesthésique'] = (string)$operation->date_visite_anesth;
        }

        return $facts;
    }

    /**
     * Remplit les substitutions admises par le corpus.
     *
     * ⚠ UNE SUBSTITUTION NON REMPLIE RESTERAIT TELLE QUELLE DANS LE DOSSIER — « patient classé
     * {asa} » —, ce qui est pire qu'une phrase absente. `ReportCorpus` refuse à l'ouverture toute
     * substitution qui n'est pas déclarée ; cette méthode doit donc savoir remplir chacune des
     * déclarées, et c'est la raison pour laquelle la liste est fermée.
     */
    private function fill(string $fragment, COperation $operation): string
    {
        $asa = $operation->ASA ? 'ASA ' . $this->roman((int)$operation->ASA) : 'ASA non renseignée';

        return strtr(
            $fragment,
            [
                '{acte}'          => (string)$operation->libelle,
                '{asa}'           => $asa,
                '{installation}'  => $this->installation($operation),
                '{duree}'         => (string)($this->minutes((string)$operation->temp_operation) ?? ''),
                '{chirurgien}'    => '',
                '{anesthesiste}'  => '',
            ]
        );
    }

    /**
     * L'installation du patient, tirée du corpus mais STABLE pour une intervention donnée.
     *
     * DÉRIVÉE DE L'IDENTIFIANT ET NON TIRÉE AU SORT À CHAQUE APPEL : l'ouverture du compte rendu
     * opératoire et celle du compte rendu d'anesthésie décriraient sinon deux installations
     * différentes pour la même intervention.
     *
     * @throws SeedException
     */
    private function installation(COperation $operation): string
    {
        $choices = ReportCorpus::section('installations');

        if ($choices === []) {
            return 'décubitus dorsal';
        }

        return (string)$choices[((int)$operation->_id) % count($choices)];
    }

    /**
     * @param array<string, string> $facts
     * @param string[]              $paragraphs
     *
     * @throws SeedException
     */
    private function write(
        COperation $operation,
        string $name,
        string $author_id,
        array $facts,
        array $paragraphs
    ): void {
        $report               = new CCompteRendu();
        $report->object_id    = $operation->_id;
        $report->object_class = 'COperation';
        $report->nom          = $name;
        $report->type         = 'body';
        $report->author_id    = $author_id ?: null;
        $report->valide       = 1;
        $report->_source      = $this->html($name, $facts, $paragraphs);

        $this->context->write($report);
    }

    /**
     * @param array<string, string> $facts
     * @param string[]              $paragraphs
     */
    private function html(string $name, array $facts, array $paragraphs): string
    {
        $html = '<h1>' . $this->escape($name) . '</h1><table>';

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

    /**
     * La durée en minutes, depuis le `temp_operation` de l'intervention.
     *
     * ⚠ `temp_operation` EST UNE HEURE (« 01:30:00 »), PAS UN NOMBRE. La lire comme un entier
     * donnerait 1 au lieu de 90 — et c'est le genre de chiffre qu'un compte rendu afficherait sans
     * que personne ne le remarque.
     */
    private function minutes(string $duration): ?int
    {
        if ($duration === '') {
            return null;
        }

        $parts = explode(':', $duration);

        if (count($parts) < 2) {
            return null;
        }

        $minutes = ((int)$parts[0] * 60) + (int)$parts[1];

        return $minutes > 0 ? $minutes : null;
    }

    private function roman(int $asa): string
    {
        $romans = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'];

        return $romans[$asa] ?? (string)$asa;
    }
}
