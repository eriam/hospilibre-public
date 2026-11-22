<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Ce qu'un run de seed a produit, et ce qu'il n'a pas pu produire.
 *
 * Les deux compteurs d'écart — lits non trouvés, interventions non programmées — sont la partie
 * importante. Un générateur qui ne rapporte que ses succès laisse croire que le jeu de données
 * est conforme à la commande alors qu'il a peut-être dégradé la moitié de ses affectations. Ils
 * sont donc exposés au même rang que les comptes, et l'occupation mesurée avec eux.
 */
final class SeedReport
{
    /** @var SeedContext */
    private $context;

    /** @var Referential */
    private $referential;

    /** @var int */
    private $patients;

    /** @var int */
    private $sejours;

    /** @var int */
    private $bed_fallbacks;

    /** @var int */
    private $unscheduled_operations;

    /** @var int */
    private $occupied_beds;

    /** @var int */
    private $occupied_icu_beds;

    /** @var int */
    private $profile_fallbacks;

    /** @var int */
    private $occupied_emergency_beds;

    /** @var array<string, int> */
    private $operations;

    /** @var array<string, int> Parcours anesthésique écrit : plages, consultations, dossiers, visites */
    private $anaesthesia = [];

    /** @var array<string, int> Les pièces jointes écrites, et leurs refus. */
    private $documents = [];

    /** @var array<string, int> Les constantes écrites : relevés et séjours couverts. */
    private $vitals = [];

    /** @var array<string, int> Comptes rendus écrits : opératoires, anesthésiques, couverture */
    private $reports = [];

    /**
     * Incidents peropératoires survenus, par type.
     *
     * ⚠ ILS N'ÉTAIENT PAS RAPPORTÉS DU TOUT. `SejourBuilder::incidents()` existait et comptait
     * depuis le début, mais le rapport ne le recevait jamais : un semis pouvait produire des
     * conversions, des hémorragies et des plaies d'organe sans qu'une ligne ne le dise. Un jeu de
     * données sans incident ressemble à un jeu de données normal, et c'est précisément pourquoi le
     * silence est le pire des états.
     *
     * @var array<string, int>
     */
    private $incidents = [];

    /** @var array<string, int|bool> Codage produit : voir SejourBuilder::coding() */
    private $coding = [];

    /** @var IppRegistry|null */
    private $ipp;

    /** @var AnchoredCohort|null */
    private $anchors;

    /** @var array Ancrés restés sans séjour */
    private $unplaced_anchors = [];

    /** @var int */
    private $anchor_profile_overrides = 0;

    /** @var int */
    private $anchors_without_matching_unit = 0;

    public function __construct(
        SeedContext $context,
        Referential $referential,
        int $patients,
        int $sejours,
        int $bed_fallbacks,
        int $unscheduled_operations,
        int $occupied_beds,
        int $occupied_icu_beds = 0,
        int $profile_fallbacks = 0,
        int $occupied_emergency_beds = 0,
        array $operations = [],
        array $coding = [],
        ?IppRegistry $ipp = null,
        ?AnchoredCohort $anchors = null,
        array $unplaced_anchors = [],
        int $anchor_profile_overrides = 0,
        int $anchors_without_matching_unit = 0,
        array $anaesthesia = [],
        array $reports = [],
        array $incidents = [],
        array $documents = [],
        array $vitals = []
    ) {
        $this->anaesthesia            = $anaesthesia;
        $this->documents              = $documents;
        $this->vitals                 = $vitals;
        $this->reports                = $reports;
        $this->incidents              = $incidents;
        $this->context                = $context;
        $this->referential            = $referential;
        $this->patients               = $patients;
        $this->sejours                = $sejours;
        $this->bed_fallbacks          = $bed_fallbacks;
        $this->unscheduled_operations = $unscheduled_operations;
        $this->occupied_beds          = $occupied_beds;
        $this->occupied_icu_beds      = $occupied_icu_beds;
        $this->profile_fallbacks      = $profile_fallbacks;
        $this->occupied_emergency_beds = $occupied_emergency_beds;
        $this->operations              = $operations;
        $this->coding                  = $coding;
        $this->ipp                     = $ipp;
        $this->anchors                 = $anchors;
        $this->unplaced_anchors        = $unplaced_anchors;
        $this->anchor_profile_overrides = $anchor_profile_overrides;
        $this->anchors_without_matching_unit = $anchors_without_matching_unit;
    }

    /**
     * Ancrés restés sans séjour.
     *
     * @return array
     */
    public function unplacedAnchors(): array
    {
        return $this->unplaced_anchors;
    }

    public function referential(): Referential
    {
        return $this->referential;
    }

    public function patients(): int
    {
        return $this->patients;
    }

    public function sejours(): int
    {
        return $this->sejours;
    }

    public function bedFallbacks(): int
    {
        return $this->bed_fallbacks;
    }

    public function unscheduledOperations(): int
    {
        return $this->unscheduled_operations;
    }

    /** Séjours confiés à un patient hors du profil d'admission de leur service. */
    public function profileFallbacks(): int
    {
        return $this->profile_fallbacks;
    }

    public function occupiedBeds(): int
    {
        return $this->occupied_beds;
    }

    public function wardBeds(): int
    {
        return count($this->referential->wardBeds());
    }

    /** Lits des unités de soins continus. */
    public function icuBeds(): int
    {
        return $this->referential->bedCount() - $this->wardBeds() - $this->emergencyBeds();
    }

    /** Lits des unités d'accueil des urgences, boxes et UHCD confondus. */
    public function emergencyBeds(): int
    {
        $beds = 0;

        foreach ($this->referential->emergencySlugs() as $slug) {
            $beds += count($this->referential->beds($slug));
        }

        return $beds;
    }

    /**
     * Interventions écrites, ventilées entre le programme opératoire et la garde.
     *
     * @return array<string, int>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * @return array<string, int|bool>
     */
    public function coding(): array
    {
        return $this->coding;
    }

    public function occupiedEmergencyBeds(): int
    {
        return $this->occupied_emergency_beds;
    }

    /** Occupation instantanée des unités d'accueil des urgences, en pourcentage. */
    public function emergencyOccupancyRate(): float
    {
        $beds = $this->emergencyBeds();

        return $beds === 0 ? 0.0 : round(100 * $this->occupied_emergency_beds / $beds, 1);
    }

    /** L'occupation des urgences tombe-t-elle dans leur propre bande ? */
    public function emergencyOccupancyInBand(): bool
    {
        if ($this->emergencyBeds() === 0) {
            return true;
        }

        [$min, $max] = $this->context->profile()->emergencyOccupancyBand();
        $rate        = $this->emergencyOccupancyRate();

        return $rate >= $min && $rate <= $max;
    }

    public function occupiedIcuBeds(): int
    {
        return $this->occupied_icu_beds;
    }

    /** Occupation instantanée des soins continus, en pourcentage. */
    public function icuOccupancyRate(): float
    {
        $beds = $this->icuBeds();

        return $beds === 0 ? 0.0 : round(100 * $this->occupied_icu_beds / $beds, 1);
    }

    /** L'occupation des soins continus tombe-t-elle dans leur propre bande ? */
    public function icuOccupancyInBand(): bool
    {
        if ($this->icuBeds() === 0) {
            return true;
        }

        [$min, $max] = $this->context->profile()->icuOccupancyBand();
        $rate        = $this->icuOccupancyRate();

        return $rate >= $min && $rate <= $max;
    }

    /** Occupation instantanée des lits d'hospitalisation complète, en pourcentage. */
    public function occupancyRate(): float
    {
        $ward = $this->wardBeds();

        return $ward === 0 ? 0.0 : round(100 * $this->occupied_beds / $ward, 1);
    }

    /** L'occupation mesurée tombe-t-elle dans la bande annoncée par le profil ? */
    public function occupancyInBand(): bool
    {
        [$min, $max] = $this->context->profile()->occupancyBand();
        $rate        = $this->occupancyRate();

        return $rate >= $min && $rate <= $max;
    }

    /**
     * Lignes destinées à la sortie de la commande. Tout ce qui permet de rejouer le run figure
     * ici : sans la graine ni l'instant de référence, un jeu de données n'est pas reproductible.
     *
     * @return array<string, int|string>
     */
    public function lines(): array
    {
        $writer = $this->context->writer();

        $lines = [
            'décor'                     => $this->context->decor()->name(),
            'profil'                    => $this->context->profile()->name(),
            'instant de référence'      => $this->context->now(),
            'établissement'             => $this->referential->group()->text
                . ($this->referential->group()->cp ? ' (' . $this->referential->group()->cp . ')' : ''),
            'identités'                 => FictitiousNames::source(),
            'communes'                  => (new CommuneDictionary())->describe(),
            'patients'                  => $this->patients,
            'séjours'                   => $this->sejours,
            'lits (hospi complète)'     => $this->wardBeds(),
            'lits occupés maintenant'   => $this->occupied_beds,
            'occupation'                => $this->occupancyRate() . ' % '
                . ($this->occupancyInBand() ? '(dans la bande)' : '(HORS BANDE)'),
            'écriture'                  => $writer->name(),
            'objets écrits'             => $writer->total(),
            // LE PIC MÉMOIRE EST RAPPORTÉ, parce qu'il décide de ce qui est possible : dépasser la
            // limite ne rend pas une erreur mais tue le processus au milieu de l'écriture, en
            // laissant la base à moitié peuplée. Un exploitant qui dimensionne plusieurs semis
            // concurrents a besoin du chiffre, et un développeur qui touche à l'échelle a besoin de
            // voir s'il dérive.
            'pic mémoire'               => round(memory_get_peak_usage(true) / 1048576) . ' Mio'
                . ' (limite ' . ini_get('memory_limit') . ')',
        ];

        // Les familles d'unités que le décor n'ouvre pas ne sont pas rapportées : « 0 lit, 0 %, dans la
        // bande » est une ligne qui n'informe de rien et laisse croire à un oubli. Une clinique sans
        // urgences ne doit pas avoir de ligne d'urgences.
        foreach (
            [
                'soins continus' => [$this->icuBeds(), $this->icuOccupancyRate(), $this->icuOccupancyInBand()],
                'urgences'       => [
                    $this->emergencyBeds(),
                    $this->emergencyOccupancyRate(),
                    $this->emergencyOccupancyInBand(),
                ],
            ] as $family => [$beds, $rate, $in_band]
        ) {
            if ($beds === 0) {
                continue;
            }

            $lines["lits ({$family})"]       = $beds;
            $lines["occupation {$family}"]   = $rate . ' % ' . ($in_band ? '(dans la bande)' : '(HORS BANDE)');
        }

        $lines['interventions'] = array_sum($this->operations) . ' ('
            . ($this->operations['programme'] ?? 0) . ' au programme, '
            . ($this->operations['urgence'] ?? 0) . ' en urgence)';

        // LE PARCOURS ANESTHÉSIQUE, et le rapprochement avec les interventions réglées EST le
        // contrôle : une consultation par intervention programmée, et pas une de plus. Un écart
        // signale soit des urgences qui en auraient reçu une — ce que le décret exclut —, soit des
        // dossiers perdus en route. Les dossiers sont comptés SÉPARÉMENT des consultations parce
        // qu'ils s'écrivent après l'intervention et peuvent échouer seuls.
        // LES COMPTES RENDUS, avec LEUR COUVERTURE et non seulement leur nombre. « 347 comptes
        // rendus » ne dit pas s'ils décrivent l'acte ou récitent une prose passe-partout : c'est la
        // part d'actes retombés sur le geste générique qui le dit, et elle doit être visible pour
        // qu'on sache ce qu'il reste à écrire dans le corpus.
        if ($this->incidents !== []) {
            $total = array_sum($this->incidents);
            $detail = [];

            foreach ($this->incidents as $_name => $_count) {
                $detail[] = SurgicalIncidents::label($_name) . ' ' . $_count;
            }

            $lines['incidents peropératoires'] = $total . ' (' . implode(', ', $detail) . ')';
        }

        $operatoires = (int)($this->reports['operatoires'] ?? 0);

        if ($operatoires > 0) {
            $generiques = (int)($this->reports['actes_generiques'] ?? 0);
            $specifiques = $operatoires - $generiques;

            $lines['comptes rendus opératoires'] = $operatoires . ' (' . $specifiques
                . ' avec un geste propre à l\'acte, ' . $generiques . ' générique(s))';

            $lines["comptes rendus d'anesthésie"] = (int)($this->reports['anesthesiques'] ?? 0);

            $narres = (int)($this->reports['incidents_narres'] ?? 0);
            $survenus = array_sum($this->incidents);

            // LE RAPPROCHEMENT EST LE CONTRÔLE : autant d'incidents racontés que d'incidents
            // survenus. Un écart signifierait soit un fait muet dans le dossier, soit un récit sans
            // fait — les deux apprenant au lecteur que le dossier se contredit.
            $lines['incidents racontés'] = $narres . ' / ' . $survenus
                . ($narres === $survenus ? '' : ' — ÉCART');
        }

        $consultations = (int)($this->anaesthesia['consultations'] ?? 0);

        if ($consultations > 0) {
            $scheduled = (int)($this->operations['programme'] ?? 0);
            $dossiers  = (int)($this->anaesthesia['dossiers'] ?? 0);

            $lines['consultations pré-anesthésiques'] = $consultations . ' / ' . $scheduled
                . ' intervention(s) réglée(s)'
                . ($consultations === $scheduled ? '' : ' — ÉCART')
                . ', sur ' . (int)($this->anaesthesia['plages'] ?? 0) . ' plage(s)';

            $lines["dossiers d'anesthésie"] = $dossiers . ' / ' . $consultations
                . ($dossiers === $consultations ? '' : ' — ÉCART, des consultations sans dossier');

            $lines['visites pré-anesthésiques'] = (int)($this->anaesthesia['visites'] ?? 0)
                . ' (la veille au soir, consignées sur l\'intervention)';

            // LE TEXTE DE LA CONSULTATION ET SON DOCUMENT, RAPPROCHÉS DU NOMBRE DE CONSULTATIONS.
            // Un écart ici veut dire qu'une consultation a été écrite sans examen ni conclusion,
            // c'est-à-dire l'état exact dans lequel le semis les laissait jusqu'au 2026-10-07 : 362
            // consultations, 0 avec examen, 0 avec conclusion, et aucun document.
            $redigees = (int)($this->anaesthesia['consultations_redigees'] ?? 0);
            $crs      = (int)($this->anaesthesia['comptes_rendus'] ?? 0);

            $lines['consultations rédigées'] = $redigees . ' / ' . $consultations
                . ($redigees === $consultations ? '' : ' — ÉCART, des consultations sans examen ni conclusion');

            $lines['comptes rendus de consultation'] = $crs . ' / ' . $consultations
                . ($crs === $consultations ? '' : ' — ÉCART');
        }

        // LES PIÈCES JOINTES, ET SURTOUT LEURS REFUS. `CFile::store()` rend une chaîne d'erreur au
        // lieu de lever quand le répertoire `files/` n'est pas inscriptible — ce qui était le cas de
        // toute instance avant le 2026-10-07, le volume étant créé en root par l'entrypoint. Sans
        // cette ligne, un semis sur une instance mal réglée se dirait réussi et laisserait l'onglet
        // des documents vide.
        if ($this->documents !== []) {
            $refuses = (int)($this->documents['refuses'] ?? 0);

            // ⚠ L'ÉTIQUETTE DIT « HTML » PARCE QUE C'EST CE QUE C'EST. Elle a d'abord dit
            // « (PDF) », et le rapport du 2026-10-07 l'a affichée telle quelle sur 1 836 fichiers
            // HTML : compte juste, nom faux. C'est le même défaut que j'ai déjà commis six fois
            // dans ce dossier, et cette fois DANS LA SÉANCE OÙ JE DOCUMENTAIS L'ABANDON DU PDF.
            // Seule la lecture du rapport produit l'a montré — aucun test ne lit une étiquette.
            $lines['pièces jointes (HTML)'] = (int)($this->documents['fichiers'] ?? 0)
                . ' — ' . (int)($this->documents['courriers'] ?? 0) . ' courrier(s), '
                . (int)($this->documents['consentements'] ?? 0) . ' consentement(s), '
                . (int)($this->documents['imageries'] ?? 0) . ' imagerie(s), '
                . (int)($this->documents['anapaths'] ?? 0) . ' anapath(s)';

            if ($refuses > 0) {
                $lines['pièces jointes REFUSÉES'] = $refuses
                    . " — CFile a refusé l'écriture. Cause la plus probable : le répertoire"
                    . ' `files/` n\'est pas inscriptible par www-data. Vérifier son propriétaire.';
            }
        }

        // LES CONSTANTES, ET ELLES NE L'ÉTAIENT PAS. `SejourBuilder::vitalsCounters()` existait et
        // comptait depuis sa livraison, mais le rapport ne le recevait JAMAIS : le semis pouvait
        // écrire quatre mille relevés — ou zéro — sans qu'une ligne ne le dise. C'est exactement le
        // défaut qu'avaient les incidents peropératoires avant le 2026-10-06, et il s'est reproduit
        // à l'identique parce qu'un compteur non branché ne se signale par aucune erreur.
        if ($this->vitals !== []) {
            $releves = (int)($this->vitals['releves'] ?? 0);
            $couverts = (int)($this->vitals['sejours'] ?? 0);

            // ⚠ LE DÉNOMINATEUR EST LE TOTAL DES SÉJOURS, PAS CEUX QUI ONT DES CONSTANTES. Ma
            // première version affichait « 3971 relevé(s) sur 1400 séjour(s) » : les deux nombres
            // étaient exacts, mais « sur 1400 » se lit comme un total alors qu'il y avait 1 556
            // séjours. L'écart de 156 disparaissait dans la formulation — c'est le même défaut
            // d'étiquette que j'ai commis sept fois dans ce dossier, et il se reproduit chaque fois
            // qu'un compte vrai est présenté sous un nom plus large que son prédicat.
            //
            // ET L'ÉCART EST LÉGITIME, CE QU'IL FAUT DIRE PLUTÔT QUE DE LAISSER DEVINER : un séjour
            // dont l'admission n'a pas encore eu lieu n'a pas de constantes, parce qu'on ne relève
            // pas celles d'un patient qui n'est pas arrivé. `SejourPlan::stayBounds()` rend null
            // tant que `entree_reelle` est nulle, et `VitalsBuilder` s'arrête là. Mesuré sur le
            // semis du 2026-10-07 : 156 des 157 séjours sans constante ne sont pas encore admis.
            // ⚠ ET L'ÉCART EST CONSTATÉ, PAS EXPLIQUÉ. Ma rédaction suivante disait « les N autres ne
            // sont pas encore admis » — une raison mesurée UNE FOIS et qui serait dès lors affirmée
            // TOUJOURS. Or un séjour ADMIS sans constante serait un vrai défaut, et cette phrase le
            // couvrirait d'une explication innocente. Le rapport donne donc le chiffre et nomme la
            // cause ATTENDUE, sans prétendre l'avoir vérifiée sur ce run.
            $total = $this->sejours > 0 ? $this->sejours : $couverts;

            $lines['constantes médicales'] = $releves . ' relevé(s) couvrant ' . $couverts
                . ' des ' . $total . ' séjour(s)'
                . ($couverts > 0 ? ' — ' . round($releves / $couverts, 1) . ' par séjour couvert' : '')
                . ($couverts < $total
                    ? ' ; ' . ($total - $couverts) . ' sans aucun relevé, attendu pour les séjours'
                    . ' non encore admis — à vérifier si le compte surprend'
                    : '');
        }

        // LE CODAGE EST RAPPORTÉ MÊME QUAND IL EST ABSENT, et surtout quand il l'est : un jeu de
        // données sans diagnostic ressemble à un jeu de données normal, et rien ne le signalerait.
        if ($this->coding !== []) {
            $operations = array_sum($this->operations);
            $cotees     = $operations - (int)($this->coding['actes_sans_cotation'] ?? 0);

            $lines['actes cotés (CCAM)'] = $cotees . ' / ' . $operations;

            // LES ACTES SONT RAPPORTÉS À PART DES COTATIONS, parce que ce ne sont pas la même chose :
            // `codes_ccam` est une chaîne que n'importe quelle instance accepte, un CActeCCAM est un
            // codage réel — avec son exécutant, son activité, ses modificateurs précodés et sa feuille
            // de codage — et il EXIGE le dictionnaire.
            if (!($this->coding['dictionnaire_ccam'] ?? false)) {
                $lines['actes CCAM détaillés'] = 'AUCUN — le dictionnaire CCAM ne répond pas sur cette'
                    . ' instance. Les interventions gardent leur cotation en clair.';
            } else {
                $geste  = (int)($this->coding['actes_geste'] ?? 0);
                $anesth = (int)($this->coding['actes_anesthesie'] ?? 0);

                $lines['actes CCAM détaillés'] = ($geste + $anesth) . " ({$geste} gestes, {$anesth}"
                    . " anesthésies)";
            }

            // LA VARIÉTÉ DES ACTES, et son empreinte de millésime. « 694 actes cotés » ne dit pas si
            // le bloc répète huit gestes ou en pratique cent : c'est le nombre d'actes DISTINCTS
            // disponibles qui le dit. Et le dictionnaire est rapporté avec lui parce que deux
            // instances de millésimes différents ne produiront pas la même cohorte à graine égale —
            // une divergence doit pouvoir se rattacher à cette cause au lieu de rester un mystère.
            $pool = (int)($this->coding['vivier_ccam'] ?? 0);

            if ($pool > 0) {
                $lines['vivier CCAM'] = $pool . ' acte(s) thérapeutique(s) distinct(s) dans les'
                    . ' spécialités employées, sur ' . (int)($this->coding['dictionnaire_actes'] ?? 0)
                    . ' en vigueur au dictionnaire';
            }

            $dp_speciality = (int)($this->coding['dp_de_specialite'] ?? 0);

            if ($dp_speciality > 0) {
                $lines['DP tirés de la spécialité'] = $dp_speciality
                    . " (pas de correspondance acte -> diagnostic : le décor ne la tient que pour"
                    . ' ses actes écrits à la main)';
            }

            $hors = (int)($this->coding['actes_hors_journee'] ?? 0);
            if ($hors > 0) {
                $lines['actes lourds en journée'] = $hors
                    . " (aucun acte ambulatoire dans la spécialité : séjour de jour opéré quand même)";
            }

            if (!($this->coding['dictionnaire'] ?? false)) {
                $lines['diagnostics (CIM-10)'] = 'AUCUN — le dictionnaire CIM-10 ne répond pas sur'
                    . ' cette instance. Déclarer la datasource « cim10 » et importer le dictionnaire'
                    . ' (ox-install:externals --import), sans quoi le socle refuse tout code.';
            } else {
                $codes = (int)($this->coding['sejours_codes'] ?? 0);
                $sans  = (int)($this->coding['sejours_sans_code'] ?? 0);

                $lines['diagnostics (CIM-10)'] = $codes . ' / ' . ($codes + $sans)
                    . ($sans > 0 ? " ({$sans} unités sans diagnostic déclaré au décor)" : '');
            }
        }

        if ($this->bed_fallbacks > 0) {
            // Ce n'est plus forcément un profil sur-souscrit : depuis que le nombre de séjours est
            // déduit de la capacité, l'établissement tourne À la bande d'occupation visée, et
            // l'occupation instantanée fluctue autour de cette moyenne. Quelques pour cent de
            // séjours hébergés sans lit nommé sont la contrepartie normale — c'est d'ailleurs un cas
            // réel. Au-delà, c'est la capacité qui manque.
            $share = (int)round(100 * $this->bed_fallbacks / max(1, $this->sejours));

            $lines['affectations sans lit'] = $this->bed_fallbacks . " ({$share} % des séjours :"
                . ' aucun lit libre sur leur période, hébergement en service)';
        }

        if ($this->profile_fallbacks > 0) {
            $lines['patients hors profil'] = $this->profile_fallbacks
                . " (aucun patient de l'âge ou du sexe qu'admet le service : population trop"
                . ' petite pour son offre de soins)';
        }

        if ($this->unscheduled_operations > 0) {
            $lines['interventions non programmées'] = $this->unscheduled_operations
                . ' (aucune plage atteignable dans la période du séjour)';
        }

        // L'IPP EST RAPPORTÉ MÊME QUAND IL EST ABSENT. Un jeu de données sans IPP ressemble à un
        // jeu de données normal tant qu'on n'ouvre pas de dossier, et rien d'autre ne le dirait.
        if ($this->ipp === null) {
            $lines['IPP'] = 'AUCUN (--no-ipp) — les dossiers n\'ont pas d\'identifiant permanent,'
                . ' et aucun système extérieur ne peut désigner ces patients.';
        } else {
            [$prefix, $next, $start] = $this->ipp->massRange();

            $lines['IPP'] = $this->ipp->count() . ' posés, tag « ' . $this->ipp->tag() . ' »'
                . ($next > $start
                    ? ', masse de ' . $prefix . $start . ' à ' . $prefix . ($next - 1)
                    : ' (aucun numéro de masse attribué)');
        }

        if ($this->anchors !== null && !$this->anchors->isEmpty()) {
            $lines['patients ancrés'] = $this->anchors->count() . ' (identité imposée par '
                . $this->anchors->path() . ')';

            if ($this->unplaced_anchors !== []) {
                // ÉCHEC, et non écart : un ancré sans séjour est le dossier le plus vide de
                // l'établissement, et c'est celui qu'une consigne d'exercice cite nommément.
                $names = [];
                foreach (array_slice($this->unplaced_anchors, 0, 5) as $patient) {
                    $names[] = $patient->nom . ' ' . $patient->prenom;
                }

                $lines['ancrés SANS SÉJOUR'] = count($this->unplaced_anchors) . ' — '
                    . implode(', ', $names)
                    . (count($this->unplaced_anchors) > 5 ? '…' : '');
            }

            if ($this->anchor_profile_overrides > 0) {
                $lines['ancrés hors profil du service'] = $this->anchor_profile_overrides
                    . ' (service imposé par le fichier, contre le profil d\'admission de l\'unité)';
            }

            // DEUX RAISONS DISTINCTES, DEUX LIGNES. Confondre « le fichier a imposé une unité qui
            // n'admet pas ce patient » et « aucune unité ouverte ne l'admet » ferait chercher un
            // service dans un fichier qui n'en cite aucun. Le remède n'est pas le même : corriger le
            // fichier dans un cas, changer de profil dans l'autre.
            if ($this->anchors_without_matching_unit > 0) {
                $lines['ancrés qu\'aucune unité n\'admet'] = $this->anchors_without_matching_unit
                    . ' (âge ou sexe hors du profil de TOUTES les unités ouvertes — un enfant sans'
                    . ' pédiatrie, par exemple). Admis quand même, pour ne pas laisser un dossier'
                    . ' vide : choisir un profil qui ouvre l\'unité voulue.';
            }
        }

        return $lines;
    }

    /** Y a-t-il un ancré sans séjour ? La commande en fait un avertissement. */
    public function hasUnplacedAnchors(): bool
    {
        return $this->unplaced_anchors !== [];
    }

    /**
     * @return array<string, int> classe courte => nombre d'objets écrits
     */
    public function counts(): array
    {
        return $this->context->writer()->counts();
    }
}
