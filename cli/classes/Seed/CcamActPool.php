<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Ox\Core\CSQLDataSource;
use Throwable;

/**
 * Le vivier d'actes : la variété vient de la CCAM, pas d'une liste écrite à la main.
 *
 * LE PROBLÈME MESURÉ. Le décor déclare soixante-dix actes pour quatorze spécialités — huit pour la
 * chirurgie viscérale. Sur un semis `small`, cela donne 694 actes cotés tirés de HUIT codes
 * différents, dont un porté par 178 interventions. Un programme opératoire qui répète le même acte
 * cent soixante-dix-huit fois ne ressemble à aucun bloc réel, et c'est le genre de détail qu'un
 * formateur voit au premier coup d'œil.
 *
 * LE DICTIONNAIRE EN CONTIENT 4 067, thérapeutiques et encore en vigueur. Les employer tous serait
 * absurde — un chirurgien viscéral ne fait pas de greffe de cornée —, mais les employer PAR FAMILLE
 * donne 1 536 actes répartis dans les quatorze spécialités, soit vingt-deux fois la variété actuelle.
 *
 * COMMENT LA FAMILLE EST DÉTERMINÉE, ET POURQUOI ELLE N'EST PAS INVENTÉE. La CCAM porte sa propre
 * hiérarchie, dans les colonnes `ARBORESCENCE1` à `ARBORESCENCE4` de `p_acte` : chapitre,
 * sous-chapitre, paragraphe. Le décor déclare déjà, pour chaque spécialité, les actes qu'elle
 * pratique ; le vivier est l'ensemble des actes thérapeutiques qui partagent le PARAGRAPHE
 * (niveaux 1+2+3) de l'un d'eux. Aucune décision clinique nouvelle n'est prise : on étend ce que le
 * décor a déjà dit, en suivant le classement officiel plutôt qu'une ressemblance de code.
 *
 * ⚠ LA HIÉRARCHIE OFFICIELLE PLUTÔT QUE LE PRÉFIXE DU CODE, et ce n'est pas une préférence
 * esthétique — les deux ont été mesurés. Un préfixe de trois lettres paraît désigner un territoire
 * anatomique, et c'est faux : `EGF` réunit les hémorroïdectomies ET la thrombectomie veineuse
 * fémoro-iliaque, qui est un acte de chirurgie vasculaire. Elle entrait donc dans le vivier du
 * chirurgien viscéral, où elle se serait vue au premier coup d'œil sur un programme opératoire. La
 * hiérarchie les sépare — hémorroïdes en 4|4|7, thrombectomie en 4|4|3 — et donne EN MÊME TEMPS
 * plus de variété : 1 536 actes contre 968 par préfixe. Il n'y avait donc pas d'arbitrage à faire
 * entre précision et quantité, contrairement à ce que je croyais avant de mesurer.
 *
 * LE PARAGRAPHE ET NON LE SOUS-CHAPITRE : au niveau 1+2, hémorroïdes et thrombectomie partagent
 * encore leur nœud. Et comme le vivier est l'UNION des paragraphes de tous les actes déclarés, des
 * familles étroites ne coûtent rien — l'appendicectomie apporte les actes de l'appendice, la
 * cholécystectomie ceux de la vésicule, et la spécialité couvre bien son champ.
 *
 * ⚠ CE QUE LE VIVIER NE SAIT PAS FAIRE. Pour les spécialités qui se définissent par le PATIENT et
 * non par l'organe — la chirurgie pédiatrique au premier chef — les familles sont anatomiquement
 * justes mais indifférentes à l'âge : le vivier contient des actes d'adulte. Le décor borne déjà
 * l'âge des patients de ces unités, ce qui limite la portée du défaut sans le supprimer. Il est
 * assumé et nommé ici plutôt que laissé à découvrir.
 *
 * ⚠ ET IL DÉPEND DU MILLÉSIME DU DICTIONNAIRE. Deux instances dont les dictionnaires CCAM diffèrent
 * ne produiront pas le même jeu de données à graine égale. C'est pourquoi `dictionarySize()` est rapporté par
 * le semis : une divergence inexpliquée entre deux cohortes doit pouvoir se rattacher à cette cause
 * au lieu de rester un mystère. Le tri par code rend l'ordre stable à dictionnaire égal.
 */
final class CcamActPool
{
    private const SOURCE = 'ccamV2';

    /** La quatrième lettre du code : « A » désigne un acte THÉRAPEUTIQUE. */
    private const THERAPEUTIC = 'A';

    /**
     * Longueur du libellé que `operations.libelle` accepte.
     *
     * Le libellé long de la CCAM monte à 450 caractères, mais DEUX actes seulement dépassent 255 sur
     * les 4 067 retenus. Plutôt que de tronquer au milieu d'un mot — ce qui donnerait un libellé
     * médical amputé, donc faux —, ces deux-là prennent leur libellé COURT, qui ne dépasse jamais
     * 79 caractères.
     */
    private const LABEL_MAX = 255;

    /** @var array<string, array<string, string>> Viviers déjà constitués, par spécialité et sexe. */
    private static $pools = [];

    /** @var int|null Nombre d'actes thérapeutiques en vigueur dans le dictionnaire. */
    private static $dictionary_size;

    /** @var array<string, bool> Actes pouvant porter une cotation d'anesthésie en phase 0. */
    private static $anaesthesia = [];

    public static function forget(): void
    {
        self::$pools           = [];
        self::$dictionary_size = null;
        self::$anaesthesia     = [];
    }

    /**
     * Le vivier d'une spécialité : code CCAM => libellé.
     *
     * @param string[]    $seed_codes Codes des actes que le décor déclare pour cette spécialité
     * @param string|null $sex        Sexe du patient : « 1 » homme, « 2 » femme, null indifférent
     *
     * @return array<string, string>
     */
    public static function forSpeciality(string $speciality, array $seed_codes, ?string $sex = null): array
    {
        $key = $speciality . '/' . ($sex ?? '0');

        if (isset(self::$pools[$key])) {
            return self::$pools[$key];
        }

        $ds = self::datasource();

        if ($ds === null) {
            return self::$pools[$key] = [];
        }

        $conditions = self::familyConditions($ds, $seed_codes);

        if ($conditions === []) {
            return self::$pools[$key] = [];
        }

        // LE SEXE EST FILTRÉ PAR LE DICTIONNAIRE LUI-MÊME : `SEXE` vaut « 1 » pour les actes
        // réservés à l'homme, « 2 » à la femme, « 0 » pour les autres — 558 actes sur 8 538 sont
        // ainsi restreints. S'en remettre au libellé aurait été un pari ; la colonne est un fait.
        $sex_clause = '';

        if ($sex === '1' || $sex === '2') {
            $sex_clause = " AND SEXE IN ('0', " . $ds->prepare('%1', $sex) . ')';
        }

        // ⚠ LA PHASE 0 SUR L'ACTIVITÉ 1 EST UNE CONDITION D'EXISTENCE, pas un raffinement. Le semis
        // cote ses actes en phase « 0 », la phase unique, et `CActeCCAM` va chercher
        // `activites[1]->phases[0]` pour en calculer le tarif : un acte en PLUSIEURS TEMPS, dont les
        // phases commencent à 1, fait donc échouer l'écriture sur « Undefined array key 0 ». Mesuré
        // en vrai : le semis s'est arrêté net au premier acte de ce genre tiré du vivier.
        //
        // Le commentaire de `writeActe()` l'avait anticipé — « les actes en plusieurs temps sont
        // rares et AUCUN DU DÉCOR n'en a » — et c'est exactement ce que le vivier change : il en
        // apporte. Neuf actes sur 4 067 sont dans ce cas ; les écarter ne coûte rien et les garder
        // coûtait tout le semis.
        $query = "SELECT CODE, LIBELLELONG, LIBELLECOURT FROM p_acte a
                   WHERE SUBSTRING(CODE, 4, 1) = " . $ds->prepare('%1', self::THERAPEUTIC) . "
                     AND " . self::liveClause() . "
                     AND (" . implode(' OR ', $conditions) . ")
                     AND EXISTS (SELECT 1 FROM p_phase p
                                  WHERE p.CODEACTE = a.CODE AND p.ACTIVITE = '1' AND p.PHASE = '0')
                     {$sex_clause}
                   ORDER BY CODE";

        $rows = $ds->loadList($query);

        if (!is_array($rows)) {
            return self::$pools[$key] = [];
        }

        $pool = [];

        foreach ($rows as $_row) {
            $pool[(string)$_row['CODE']] = self::label($_row);
        }

        return self::$pools[$key] = $pool;
    }

    /**
     * Cet acte peut-il porter une cotation d'ANESTHÉSIE telle que le semis l'écrit ?
     *
     * DEUX CONDITIONS ET NON UNE, et la seconde manquait. Il faut que l'acte déclare l'activité 4,
     * ce que le semis vérifiait déjà, mais AUSSI que cette activité ait une phase 0 — faute de quoi
     * l'écriture échoue exactement comme pour le geste. Huit actes en vigueur déclarent l'activité
     * d'anesthésie sans phase 0 ; ils ne se rencontraient pas dans les soixante-dix actes écrits à
     * la main, et le vivier les met sur le chemin.
     *
     * UNE REQUÊTE PLUTÔT QUE LE CHARGEMENT DU CODE : `CCodeCCAM::loadRefActivites()` instancie
     * l'arborescence et les activités pour répondre à une question d'existence, et le semis la pose
     * une fois par intervention.
     */
    public static function codesAnaesthesia(string $code): bool
    {
        if (isset(self::$anaesthesia[$code])) {
            return self::$anaesthesia[$code];
        }

        $ds = self::datasource();

        if ($ds === null) {
            return self::$anaesthesia[$code] = false;
        }

        $found = $ds->loadResult(
            $ds->prepare(
                "SELECT 1 FROM p_phase WHERE CODEACTE = %1 AND ACTIVITE = '4' AND PHASE = '0' LIMIT 1",
                $code
            )
        );

        return self::$anaesthesia[$code] = (bool)$found;
    }

    /**
     * Nombre d'actes thérapeutiques en vigueur dans le dictionnaire.
     *
     * RAPPORTÉ PAR LE SEMIS : c'est l'empreinte du millésime, et ce qui permettra d'expliquer une
     * divergence entre deux cohortes de même graine.
     */
    public static function dictionarySize(): int
    {
        if (self::$dictionary_size !== null) {
            return self::$dictionary_size;
        }

        $ds = self::datasource();

        if ($ds === null) {
            return self::$dictionary_size = 0;
        }

        $count = $ds->loadResult(
            "SELECT COUNT(*) FROM p_acte WHERE SUBSTRING(CODE, 4, 1) = "
            . $ds->prepare('%1', self::THERAPEUTIC) . ' AND ' . self::liveClause()
        );

        return self::$dictionary_size = (int)$count;
    }

    /**
     * Les actes RETIRÉS de la CCAM sont écartés.
     *
     * ⚠ « 00000000 » SIGNIFIE « SANS DATE DE FIN », et c'est le piège de cette colonne : elle est
     * un `char(8)`, pas une date, et la valeur de remplissage est huit zéros. Un filtre naïf
     * « DATEFIN > aujourd'hui » écarte donc TOUS les actes en vigueur et ne garde que les actes
     * retirés — exactement l'inverse de son intention. Mesuré : il rendait zéro acte sur 4 090.
     */
    private static function liveClause(): string
    {
        return "(DATEFIN = '00000000' OR DATEFIN = '' OR DATEFIN IS NULL)";
    }

    /**
     * Le libellé de l'acte, choisi pour tenir dans la colonne sans être amputé.
     *
     * @param array<string, string> $row
     */
    private static function label(array $row): string
    {
        $long = trim((string)($row['LIBELLELONG'] ?? ''));

        if ($long !== '' && mb_strlen($long) <= self::LABEL_MAX) {
            return $long;
        }

        $short = trim((string)($row['LIBELLECOURT'] ?? ''));

        return $short !== '' ? $short : $long;
    }

    /**
     * Les paragraphes de la CCAM auxquels appartiennent les actes déclarés par le décor.
     *
     * UNE REQUÊTE POUR LES TROUVER, ET C'EST LE POINT : la hiérarchie n'est pas déductible du code,
     * il faut la LIRE dans le dictionnaire. C'est ce qui distingue cette approche d'une heuristique
     * sur les lettres du code, et c'est ce qui lui permet de séparer deux actes que leur code
     * rapproche.
     *
     * @param string[] $seed_codes
     *
     * @return string[] Clauses SQL, une par paragraphe
     */
    private static function familyConditions(CSQLDataSource $ds, array $seed_codes): array
    {
        $codes = [];

        foreach ($seed_codes as $code) {
            $code = (string)$code;

            if (preg_match('/^[A-Z]{4}\d{3}$/', $code)) {
                $codes[] = $ds->prepare('%1', $code);
            }
        }

        if ($codes === []) {
            return [];
        }

        $paths = $ds->loadList(
            'SELECT DISTINCT ARBORESCENCE1 a1, ARBORESCENCE2 a2, ARBORESCENCE3 a3
               FROM p_acte WHERE CODE IN (' . implode(', ', $codes) . ')'
        );

        if (!is_array($paths)) {
            return [];
        }

        $conditions = [];

        foreach ($paths as $_path) {
            // UN NŒUD VIDE NE DOIT PAS OUVRIR LE DICTIONNAIRE ENTIER : une ligne mal importée dont
            // l'arborescence serait vide produirait une clause vraie pour des milliers d'actes.
            if (($_path['a1'] ?? '') === '' || ($_path['a2'] ?? '') === '' || ($_path['a3'] ?? '') === '') {
                continue;
            }

            $conditions[] = '(ARBORESCENCE1 = ' . $ds->prepare('%1', $_path['a1'])
                . ' AND ARBORESCENCE2 = ' . $ds->prepare('%1', $_path['a2'])
                . ' AND ARBORESCENCE3 = ' . $ds->prepare('%1', $_path['a3']) . ')';
        }

        return $conditions;
    }

    private static function datasource(): ?CSQLDataSource
    {
        try {
            $ds = CSQLDataSource::get(self::SOURCE, true);

            if ($ds === null || !$ds->hasTable('p_acte')) {
                return null;
            }

            return $ds;
        } catch (Throwable $e) {
            return null;
        }
    }
}
