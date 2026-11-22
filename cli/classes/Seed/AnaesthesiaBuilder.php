<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Cabinet\CConsultAnesth;
use Hospilibre\Cabinet\CConsultation;
use Hospilibre\Cabinet\CPlageconsult;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\PlanningOp\COperation;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;

/**
 * Le parcours anesthésique qui PRÉCÈDE l'intervention : consultation, puis visite.
 *
 * DEUX ACTES DISTINCTS, et les confondre serait une faute de fond. Le droit français les sépare
 * (art. D.6124-91 du code de la santé publique, issu du décret du 5 décembre 1994) :
 *
 *   — LA CONSULTATION PRÉ-ANESTHÉSIQUE a lieu PLUSIEURS JOURS avant une intervention réglée. Elle
 *     est un acte à part entière, avec son dossier — antécédents, traitements, bilan — et elle est
 *     souvent faite par un autre anesthésiste que celui qui endormira. C'est `CConsultAnesth`,
 *     accrochée à une `CConsultation`, elle-même posée sur une plage de l'anesthésiste.
 *   — LA VISITE PRÉ-ANESTHÉSIQUE a lieu DANS LES HEURES qui précèdent. Elle ne crée pas de
 *     consultation : elle se consigne sur l'intervention elle-même
 *     (`date_visite_anesth`, `time_visite_anesth`, `prat_visite_anesth_id`).
 *
 * ⚠ RIEN DE TOUT CELA N'EXISTAIT DANS LE SEMIS. Mesuré avant d'écrire : zéro ligne dans
 * `consultation_anesth`, zéro `prat_visite_anesth_id` sur 347 interventions, et aucune
 * `CConsultation` d'aucune sorte. Un jeu chirurgical sans consultation d'anesthésie n'est pas
 * seulement incomplet, il est invraisemblable — l'intervention réglée sans consultation préalable
 * est précisément ce que le décret interdit.
 *
 * ET CE N'EST PAS QU'UNE QUESTION DE VRAISEMBLANCE : c'est ce qui donne au périmètre d'exercice de
 * quoi travailler. Un anesthésiste qui CONSULTE sans être celui qui ENDORT n'est nommé nulle part
 * sur l'intervention ; sans cette chaîne, le périmètre lui refuse le dossier du patient qu'il vient
 * de voir en consultation. Voir `CPermUnit::involved()`, qui remonte désormais cette chaîne.
 *
 * ⚠ RIEN POUR LES URGENCES, et c'est la règle et non une simplification : une intervention non
 * programmée n'a pas de consultation plusieurs jours avant, par définition. Le décret prévoit
 * lui-même ce cas. Leur en fabriquer une antidaterait un acte qui n'a pas eu lieu.
 */
final class AnaesthesiaBuilder
{
    /** Délai minimal entre la consultation et l'intervention, en jours. */
    private const CONSULTATION_LEAD_MIN_DAYS = 3;

    /** Délai maximal. Au-delà, le bilan serait périmé et la consultation à refaire. */
    private const CONSULTATION_LEAD_MAX_DAYS = 21;

    /** Durée d'un créneau de consultation d'anesthésie. */
    private const SLOT_MINUTES = 30;

    /** Plage de consultation : de 08:30 à 17:30. */
    private const SLOT_FIRST_HOUR = 8;
    private const SLOT_LAST_HOUR  = 17;

    /**
     * Part, en pour mille, des visites pré-anesthésiques faites par un AUTRE anesthésiste que celui
     * qui endort.
     *
     * POURQUOI CE N'EST PAS ZÉRO : la visite de la veille au soir est faite par celui qui est là,
     * pas forcément par celui qui est programmé le lendemain. Et ce n'est pas non plus la majorité :
     * la continuité est recherchée. Le chiffre est un choix de vraisemblance, pas une statistique
     * publiée — il est ici pour que le cas existe dans le jeu de données et que les droits qui en
     * dépendent soient éprouvés.
     */
    private const VISIT_BY_ANOTHER_PER_MILLE = 250;

    /** @var SeedContext */
    private $context;

    /** @var Referential */
    private $referential;

    /**
     * Plages de consultation déjà ouvertes, indexées par anesthésiste et par jour.
     *
     * UNE PLAGE PAR ANESTHÉSISTE ET PAR JOUR, réutilisée par toutes les consultations de ce jour.
     * En ouvrir une par consultation produirait des centaines de plages d'un seul créneau, ce qui
     * ne ressemble à aucun planning réel et rendrait l'agenda illisible à l'écran.
     *
     * @var array<string, CPlageconsult>
     */
    private $slots = [];

    /** @var array<string, int> Créneaux déjà pris dans une plage, pour ne pas superposer. */
    private $taken = [];

    /**
     * Part, en pour mille, des patients à terrain altéré passant en surveillance continue.
     *
     * Pas tous : un ASA III opéré d'une hernie rentre dans son unité. Le chiffre est un choix de
     * vraisemblance, et il n'est tiré que pour les ASA III-IV.
     */
    private const USCPO_PER_MILLE = 350;

    /** @var array<string, string>|null Positions d'installation lues en base, par code. */
    private static $positions;

    /** @var array<string, int> */
    private $counters = ['consultations' => 0, 'visites' => 0, 'plages' => 0, 'dossiers' => 0];

    /** @var ConsultationReportBuilder Le texte de la consultation et son compte rendu. */
    private $reports;

    /**
     * Compte un dossier d'anesthésie écrit par l'appelant.
     *
     * C'EST L'APPELANT QUI L'ÉCRIT, donc lui qui sait si ça a réussi : compter ici à l'avance
     * annoncerait des dossiers qui n'existent pas.
     */
    public function countDossier(): void
    {
        $this->counters['dossiers']++;
    }

    public function __construct(SeedContext $context, Referential $referential)
    {
        $this->context     = $context;
        $this->referential = $referential;
        $this->reports     = new ConsultationReportBuilder($context);
    }

    /**
     * @return array<string, int>
     */
    public function counters(): array
    {
        // FUSIONNÉS ET NON SÉPARÉS : le rapport du semis lit ce tableau, et une consultation dont le
        // compte rendu manque doit se voir ici plutôt que dans un compteur que personne n'affiche.
        return array_merge($this->counters, $this->reports->counters());
    }

    /**
     * Pose la consultation puis la visite pour cette intervention.
     *
     * DEUX CHOSES À LA CHARGE DE L'APPELANT, et l'ordre compte dans les deux sens :
     *
     *   — L'INTERVENTION EST MODIFIÉE ET DOIT ÊTRE ÉCRITE APRÈS : les champs de la visite se portent
     *     sur elle, et la poser après son écriture obligerait à l'enregistrer deux fois.
     *   — LE DOSSIER D'ANESTHÉSIE EST RENDU NON ÉCRIT, et doit l'être APRÈS l'intervention. ⚠ C'est
     *     la correction d'un défaut mesuré : la première version l'écrivait ici, donc AVANT que
     *     l'intervention n'ait d'identifiant, et son `operation_id` restait nul — 336 dossiers
     *     d'anesthésie rattachés à AUCUNE intervention, sans la moindre erreur. Les deux contraintes
     *     sont opposées, c'est pourquoi la méthode fait l'une et délègue l'autre.
     *
     * @throws SeedException
     */
    public function build(
        COperation $operation,
        string $patient_id,
        string $sexe,
        bool $emergency
    ): ?CConsultAnesth {
        $anaesthetists = $this->referential->anaesthetists();

        if ($anaesthetists === [] || !$operation->date || !$patient_id) {
            return null;
        }

        // Pas d'anesthésiste sur l'acte = pas d'anesthésie = ni consultation ni visite.
        if (!$operation->anesth_id) {
            return null;
        }

        if ($emergency) {
            return null;
        }

        // UN FLUX NOMMÉ, comme partout ailleurs dans le semis : les tirages de l'anesthésie ne
        // doivent pas décaler ceux des séjours ou des lits, sinon ajouter cette chaîne changerait
        // TOUT le jeu de données à graine égale.
        $random = $this->context->stream('anesthesie');

        $dossier = $dossier = $this->buildConsultation($operation, $patient_id, $sexe, $anaesthetists, $random);

        $this->buildVisit($operation, $anaesthetists, $random);

        return $dossier;
    }

    /**
     * La consultation pré-anesthésique, plusieurs jours avant.
     *
     * @param CMediusers[] $anaesthetists
     *
     * @throws SeedException
     */
    private function buildConsultation(
        COperation $operation,
        string $patient_id,
        string $sexe,
        array $anaesthetists,
        SeedRandom $random
    ): ?CConsultAnesth {
        // L'ANESTHÉSISTE DE LA CONSULTATION EST TIRÉ INDÉPENDAMMENT de celui de l'acte : c'est le
        // cas courant, et c'est aussi celui qui éprouve le périmètre — ce praticien n'apparaît
        // nulle part sur l'intervention.
        $practitioner = $random->pick($anaesthetists);

        $lead = $random->int(self::CONSULTATION_LEAD_MIN_DAYS, self::CONSULTATION_LEAD_MAX_DAYS);
        $date = CMbDT::date("-{$lead} days", (string)$operation->date);

        $slot = $this->slotFor($practitioner, $date);

        if ($slot === null) {
            return null;
        }

        // ⚠ LE BILAN EST TIRÉ ICI, AVANT TOUTE ÉCRITURE, et l'ordre est la raison d'être de ce
        // découpage. Trois choses en dépendent et doivent toutes dire la même : le texte de la
        // consultation, le document qu'elle produit, et le dossier d'anesthésie. Les tirer
        // séparément donnerait un examen clinique « sans particularité » à côté d'une hémoglobine à
        // neuf — un dossier qui se contredit avec lui-même, ce qui est précisément ce que la
        // corrélation au terrain existe pour empêcher.
        $workup = PreAnaesthesiaWorkup::forPatient($random, $sexe === 'f' ? 'f' : 'm', (int)$operation->ASA);

        $consultation                  = new CConsultation();
        $consultation->plageconsult_id = $slot->_id;
        $consultation->patient_id      = $patient_id;
        $consultation->sejour_id       = $operation->sejour_id;
        $consultation->heure           = $this->nextFreeTime($slot);
        $consultation->duree           = 1;
        $consultation->chrono          = CConsultation::TERMINE;
        $consultation->motif           = "Consultation pré-anesthésique";
        $consultation->premiere        = 1;

        // AVANT L'ÉCRITURE : examen, conclusion et remarques appartiennent à la consultation, et les
        // poser après obligerait à l'enregistrer deux fois.
        $this->reports->fillConsultation($consultation, $operation, $workup, $random);

        $consultation = $this->context->write($consultation);

        if (!$consultation->_id) {
            return null;
        }

        $this->counters['consultations']++;

        // APRÈS L'ÉCRITURE, pour la raison inverse : `CCompteRendu::object_id` exige l'identifiant
        // de la consultation.
        $this->reports->writeReport(
            $consultation,
            $operation,
            (string)$practitioner->_id,
            $date,
            $workup,
            $random
        );

        // RENDU, PAS ÉCRIT : `operation_id` exige l'identifiant de l'intervention, qui n'existe pas
        // encore. Voir l'en-tête de build().
        $dossier                  = new CConsultAnesth();
        $dossier->consultation_id = $consultation->_id;
        $dossier->sejour_id       = $operation->sejour_id;
        $dossier->chir_id         = $operation->chir_id;
        $dossier->date_interv     = $operation->date;
        $dossier->libelle_interv  = $operation->libelle;

        $this->fillWorkup($dossier, $operation, $workup, $random);

        return $dossier;
    }

    /**
     * Le CONTENU du dossier : bilan structuré et texte libre.
     *
     * ⚠ CE QUI MANQUAIT, ET CE QUE ÇA DONNAIT À L'ÉCRAN. La première version de ce bâtisseur
     * écrivait la coquille du dossier et rien d'autre. Mesuré après coup : 349 dossiers sur 349
     * sans antécédents, sans traitements, sans prémédication, sans bilan. Le cadre
     * « Infos. Anesth. » affichait donc un formulaire entièrement vide — ce qui se lit « rien ne
     * s'affiche », et c'est ainsi qu'eriam l'a signalé. L'écran n'était pas en cause ; le dossier
     * était creux.
     *
     * DEUX SOURCES ET DEUX NATURES, comme pour les comptes rendus. Les valeurs du bilan viennent de
     * `PreAnaesthesiaWorkup`, qui les corrèle à la classe ASA et au sexe — un bilan parfait chez un
     * ASA IV serait une incohérence interne au dossier. Les textes viennent du corpus, qui interdit
     * les chiffres : aucune valeur n'y est donc écrite en prose, où elle contredirait le champ
     * structuré dès le deuxième emploi du fragment.
     *
     * LE TERRAIN CHOISIT AUSSI LA PROSE : les antécédents et les traitements sont tirés dans la
     * bande « sain » ou « altéré » selon la même classe ASA, et la bande « anticoagulé » n'est
     * employée que si le bilan a effectivement tiré une anticoagulation. Sans cela on obtiendrait
     * un patient sans antécédent sous antivitamine K, ou un INR à deux et demi chez quelqu'un dont
     * les traitements disent « aucun ».
     *
     * ⚠ LE BILAN EST REÇU, PAS TIRÉ. Il l'était ici dans la première version, ce qui le rendait
     * inaccessible à l'appelant : le texte de la consultation et son compte rendu ne pouvaient donc
     * pas s'y accorder. C'est `buildConsultation()` qui le tire désormais, et qui le passe aux trois
     * écritures qui en dépendent.
     *
     * @param array<string, string|float|int> $workup Bilan déjà tiré par l'appelant
     *
     * @throws SeedException
     */
    private function fillWorkup(
        CConsultAnesth $dossier,
        COperation $operation,
        array $workup,
        SeedRandom $random
    ): void {
        $altered = ($workup['_altere'] ?? '0') === '1';
        $band    = $altered ? 'altere' : 'sain';

        // ⚠ LES VALEURS STRUCTURÉES SONT ÉCRITES AVANT TOUT CONTRÔLE SUR LE CORPUS, et c'est une
        // correction. La première version sortait de la méthode quand le corpus manquait, ce qui
        // privait le dossier de SA BIOLOGIE — alors qu'elle ne doit rien au corpus. Un semis fait
        // sans fichier de prose produisait donc des dossiers d'anesthésie entièrement vides, là où
        // il devait seulement les produire sans texte libre.
        foreach (['hb', 'inr', 'tp', 'tca', 'tca_temoin', 'creatinine', 'fibrinogene',
                  'rai', 'hepatite_b', 'hepatite_c'] as $field) {
            $dossier->{$field} = $workup[$field];
        }

        // L'INSTALLATION ET LE PASSAGE EN SURVEILLANCE CONTINUE SE PORTENT SUR L'INTERVENTION, pas
        // sur le dossier : c'est elle que le cadre « Infos. Anesth. » lit pour ces deux champs.
        // L'intervention n'est pas encore écrite, l'appelant l'enregistrera après. Ni l'un ni
        // l'autre ne doit rien au corpus, donc tous deux sont posés avant le contrôle.
        $operation->position_id = $this->positionFor($operation);

        // SURVEILLANCE CONTINUE SELON LE TERRAIN : un ASA III-IV y passe souvent, un ASA I jamais.
        $operation->passage_uscpo = $altered && $random->chanceInThousand(self::USCPO_PER_MILLE)
            ? '1'
            : '0';

        if (!ReportCorpus::isAvailable()) {
            return;
        }

        $dossier->antecedents = (string)$random->pick(ReportCorpus::band('anesthesie_antecedents', $band));

        $treatments = $workup['_anticoagule'] === '1'
            ? ReportCorpus::band('anesthesie_traitements', 'anticoagule')
            : ReportCorpus::band('anesthesie_traitements', $band);

        $dossier->traitements   = (string)$random->pick($treatments);
        $dossier->tabac         = (string)$random->pick(ReportCorpus::section('anesthesie_tabac'));
        $dossier->oenolisme     = (string)$random->pick(ReportCorpus::section('anesthesie_oenolisme'));
        $dossier->premedication = (string)$random->pick(ReportCorpus::section('anesthesie_premedications'));
        $dossier->prepa_preop   = (string)$random->pick(ReportCorpus::section('anesthesie_preparations'));

        $operation->rques = (string)$random->pick(ReportCorpus::section('anesthesie_remarques_op'));
    }

    /**
     * La position d'installation, selon l'acte.
     *
     * ⚠ LUE EN BASE ET NON INVENTÉE : la table `position` est peuplée par le socle (DD, DV, DL, GYN,
     * assise, table orthopédique…). Y écrire nos propres libellés créerait des doublons dans une
     * table de référence que l'application propose à la saisie.
     *
     * Et une proctologie ne s'opère pas en décubitus dorsal : les actes anorectaux — préfixe `EGF`
     * de la CCAM — prennent la position gynécologique. C'est le seul cas où le décor dispose de
     * l'information nécessaire pour trancher ; ailleurs le décubitus dorsal est la position de loin
     * la plus fréquente en viscéral.
     */
    private function positionFor(COperation $operation): ?string
    {
        if (self::$positions === null) {
            $ds = CSQLDataSource::get('std');
            self::$positions = [];

            foreach ((array)$ds->loadList("SELECT position_id, code FROM position WHERE actif = '1'") as $_row) {
                self::$positions[(string)$_row['code']] = (string)$_row['position_id'];
            }
        }

        $code = strpos((string)$operation->codes_ccam, 'EGF') === 0 ? 'GYN' : 'DD';

        return self::$positions[$code] ?? null;
    }

    /**
     * La visite pré-anesthésique, dans les heures qui précèdent.
     *
     * ELLE NE CRÉE RIEN : elle se consigne sur l'intervention, que l'appelant écrira ensuite.
     *
     * @param CMediusers[] $anaesthetists
     *
     * @throws SeedException
     */
    private function buildVisit(COperation $operation, array $anaesthetists, SeedRandom $random): void
    {
        // LA VEILLE, et non le jour même : c'est l'usage pour une intervention réglée, et ça rend la
        // visite distincte de la prise en charge au bloc. Le décret dit « dans les heures
        // précédant », ce que la veille au soir satisfait.
        $operation->date_visite_anesth = CMbDT::date('-1 day', (string)$operation->date);
        $operation->time_visite_anesth = sprintf('%02d:%02d:00', $random->int(17, 19), $random->int(0, 59));

        if ($random->chanceInThousand(self::VISIT_BY_ANOTHER_PER_MILLE)) {
            $operation->prat_visite_anesth_id = $random->pick($anaesthetists)->_id;
        } else {
            $operation->prat_visite_anesth_id = $operation->anesth_id;
        }

        $this->counters['visites']++;
    }

    /**
     * La plage de consultation de cet anesthésiste ce jour-là, ouverte au besoin.
     *
     * @throws SeedException
     */
    private function slotFor(CMediusers $practitioner, string $date): ?CPlageconsult
    {
        $key = $practitioner->_id . '/' . $date;

        if (isset($this->slots[$key])) {
            return $this->slots[$key];
        }

        $slot          = new CPlageconsult();
        $slot->chir_id = $practitioner->_id;
        $slot->date    = $date;
        $slot->debut   = sprintf('%02d:30:00', self::SLOT_FIRST_HOUR);
        $slot->fin     = sprintf('%02d:30:00', self::SLOT_LAST_HOUR);
        $slot->freq    = sprintf('00:%02d:00', self::SLOT_MINUTES);
        $slot->libelle = 'Consultations pré-anesthésiques';

        $slot = $this->context->write($slot);

        if (!$slot->_id) {
            return null;
        }

        $this->counters['plages']++;
        $this->taken[$key] = 0;

        return $this->slots[$key] = $slot;
    }

    /**
     * L'heure du prochain créneau libre de cette plage.
     *
     * SANS SUPERPOSITION, parce que deux consultations à la même heure chez le même praticien est
     * une incohérence que l'agenda montre immédiatement à l'écran. Quand la journée est pleine, on
     * reprend au début : le jeu reste lisible, au prix d'un chevauchement assumé plutôt que d'une
     * consultation perdue.
     */
    private function nextFreeTime(CPlageconsult $slot): string
    {
        $key   = $slot->chir_id . '/' . $slot->date;
        $index = $this->taken[$key] ?? 0;

        $per_day = (int)(((self::SLOT_LAST_HOUR - self::SLOT_FIRST_HOUR) * 60) / self::SLOT_MINUTES);

        $this->taken[$key] = $index + 1;

        $minutes = (self::SLOT_FIRST_HOUR * 60) + 30 + (($index % max(1, $per_day)) * self::SLOT_MINUTES);

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }
}
