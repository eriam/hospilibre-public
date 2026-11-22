<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Les incidents peropératoires : ce qui arrive, à quels actes, et ce que ça change DANS LES DONNÉES.
 *
 * UN INCIDENT EST UN FAIT, PAS UNE TOURNURE DE PHRASE. C'est la distinction qui gouverne ce
 * fichier : la prose d'un compte rendu peut varier à l'infini sans conséquence, mais un incident
 * change le code de l'acte, la durée d'intervention, le diagnostic relié du séjour et parfois son
 * issue. Un compte rendu qui narre une hémorragie sur un séjour montrant une sortie à J1 sans
 * transfusion est PIRE qu'un compte rendu sans incident : il apprend au lecteur que le dossier
 * ment. Les effets sont donc déclarés ici, à côté de la cause, et appliqués aux données.
 *
 * LES TAUX SONT AILLEURS, dans ClinicalMix, parce qu'ils se règlent — un enseignant peut vouloir
 * un établissement plus ou moins accidenté. Ce catalogue dit QUOI et SUR QUOI ; les coefficients
 * disent COMBIEN.
 *
 * ⚠ L'INFECTION DU SITE OPÉRATOIRE N'EST PAS ICI. Elle se manifeste à J3-J10 : elle n'allonge pas
 * l'intervention, elle prolonge le séjour et provoque parfois une reprise ou une réadmission. Elle
 * exige que le temps passe, donc sa place est dans `simulate:advance` qui fait vivre l'hôpital jour
 * par jour, et non dans un semis qui pose une photographie. Décision prise avec eriam le
 * 2026-10-03.
 */
final class SurgicalIncidents
{
    /** Changement de voie d'abord : pas une complication, un autre acte. */
    public const CONVERSION = 'conversion';

    /** Hémorragie peropératoire. */
    public const HEMORRAGIE = 'hemorragie';

    /** Perforation ou déchirure accidentelle d'un organe. */
    public const PLAIE_ORGANE = 'plaie_organe';

    /** Complication liée à l'anesthésie. */
    public const ANESTHESIE = 'anesthesie';

    /**
     * Le catalogue.
     *
     * `cim10` — le diagnostic RELIÉ à porter sur le séjour. Null pour la conversion : elle n'a pas
     *   de code, et lui en inventer un serait coder une complication qui n'a pas eu lieu.
     * `applies_to` — motif cherché dans le LIBELLÉ de l'acte, ou null si l'incident peut survenir
     *   sur n'importe quelle intervention. Une conversion ne se conçoit que sur une voie
     *   cœlioscopique ; c'est cette déclaration qui l'exprime, et non une prose dupliquée par acte.
     * `duree_facteur_pour_mille` — allongement de l'intervention, en pour mille. Une hémorragie
     *   qu'il faut contrôler ne prend pas le même temps qu'une conversion, qui refait la voie
     *   d'abord.
     * `reanimation` — l'incident peut-il conduire le patient en réanimation au décours immédiat.
     * `libelle` — ce que le fait s'appelle, pour les journaux et le rapport du semis. PAS pour un
     *   compte rendu : la prose viendra d'un corpus séparé, et ce libellé n'y a pas sa place.
     *
     * @var array<string, array{cim10: string|null, applies_to: string|null,
     *                          duree_facteur_pour_mille: int, reanimation: bool, libelle: string}>
     */
    private const CATALOGUE = [
        self::CONVERSION => [
            // Pas de code CIM-10 : une conversion n'est pas une complication. Elle s'exprime en
            // changeant le code CCAM pour celui de la voie ouverte — voir convertedCodeFor().
            'cim10'                    => null,
            'applies_to'               => 'œlioscopi',
            'duree_facteur_pour_mille' => 1600,
            'reanimation'              => false,
            'libelle'                  => 'conversion en voie ouverte',
        ],
        self::HEMORRAGIE => [
            // SANS POINT : l'application stocke le code CIM-10 sous sa forme abrégée — `A099`,
            // `C189`, `T810` — et non sous la forme pointée du dictionnaire. Le champ `code cim10`
            // de CSejour refusait `T81.0` EN SILENCE, si bien que le diagnostic relié n'était
            // jamais écrit : 346 interventions, zéro DR. Mesuré avant mise en service, en
            // comparant aux DP que le semis pose déjà. Le dictionnaire porte les deux formes :
            // `master.code` = « T81.0 », `master.abbrev` = « T810 ».
            'cim10'                    => 'T810',
            'applies_to'               => null,
            'duree_facteur_pour_mille' => 1400,
            'reanimation'              => true,
            'libelle'                  => 'hémorragie peropératoire',
        ],
        self::PLAIE_ORGANE => [
            'cim10'                    => 'T812',
            'applies_to'               => null,
            'duree_facteur_pour_mille' => 1500,
            'reanimation'              => false,
            'libelle'                  => "plaie accidentelle d'organe",
        ],
        self::ANESTHESIE => [
            // T88.5 « autres complications de l'anesthésie » et non T88.2 « choc dû à
            // l'anesthésie » : le choc est l'issue la plus grave, pas le cas courant, et un semis
            // qui coderait tous ses incidents anesthésiques en choc décrirait un établissement
            // invraisemblable.
            'cim10'                    => 'T885',
            'applies_to'               => null,
            'duree_facteur_pour_mille' => 1250,
            'reanimation'              => true,
            'libelle'                  => "complication de l'anesthésie",
        ],
    ];

    /**
     * Les noms des incidents, dans un ordre stable.
     *
     * STABLE ET NON ALÉATOIRE : l'ordre de tirage fait partie de ce que la graine rejoue. Deux
     * semis de même graine doivent produire les mêmes incidents sur les mêmes interventions, et une
     * itération sur un tableau associatif ne le garantit que si l'ordre est celui de la
     * déclaration — ce qui est le cas en PHP, et ce commentaire est là pour qu'on n'y touche pas
     * en croyant trier pour faire propre.
     *
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /**
     * L'incident peut-il survenir sur cet acte ?
     */
    public static function appliesTo(string $incident, string $procedure): bool
    {
        $pattern = self::CATALOGUE[$incident]['applies_to'] ?? null;

        if ($pattern === null) {
            return true;
        }

        // Comparaison insensible à la casse ET aux accents absents : les libellés du catalogue
        // écrivent « cœlioscopie » avec la ligature, mais un libellé officiel de la CCAM peut
        // porter « coelioscopie ». Chercher la ligature seule raterait la moitié des actes.
        $haystack = self::fold($procedure);

        return strpos($haystack, self::fold($pattern)) !== false;
    }

    /**
     * Le diagnostic relié à porter sur le séjour, ou null si l'incident n'en a pas.
     */
    public static function cim10(string $incident): ?string
    {
        return self::CATALOGUE[$incident]['cim10'] ?? null;
    }

    /**
     * L'allongement de l'intervention, en pour mille de sa durée prévue.
     */
    public static function durationFactor(string $incident): int
    {
        return (int)(self::CATALOGUE[$incident]['duree_facteur_pour_mille'] ?? 1000);
    }

    public static function mayLeadToIntensiveCare(string $incident): bool
    {
        return (bool)(self::CATALOGUE[$incident]['reanimation'] ?? false);
    }

    public static function label(string $incident): string
    {
        return (string)(self::CATALOGUE[$incident]['libelle'] ?? $incident);
    }

    /**
     * Le code CCAM d'un acte converti en voie ouverte, ou null s'il n'est pas déductible.
     *
     * CE QUE CETTE MÉTHODE NE FAIT PAS, et il faut le dire : elle ne CHERCHE PAS le code de la voie
     * ouverte dans la CCAM. Déduire « HMFC004 cholécystectomie par cœlioscopie » -> « HMFA007
     * cholécystectomie par laparotomie » demande la nomenclature, et un rapprochement approximatif
     * produirait un code faux — donc un dossier qui code un acte que personne n'a fait, ce qui est
     * pire que de ne pas coder la conversion.
     *
     * On rend donc null, et l'appelant GARDE le code d'origine en portant la conversion dans la
     * durée et dans le compte rendu. C'est une limite assumée : la conversion est visible dans le
     * dossier sans être cotée, exactement comme elle l'est quand un bloc n'a pas mis à jour sa
     * cotation — situation d'ailleurs fréquente, et qui a sa valeur pédagogique.
     *
     * À faire correctement, il faudra interroger `p_acte` sur la racine du code à quatre lettres et
     * retenir la variante dont le libellé porte « par laparotomie » : faisable, et c'est un
     * chantier à part parce qu'il rend le semis dépendant du dictionnaire CCAM.
     */
    public static function convertedCodeFor(string $code): ?string
    {
        return null;
    }

    private static function fold(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $folded = strtolower((string)($folded === false ? $value : $folded));

        return (string)preg_replace('/[^a-z0-9]/', '', $folded);
    }
}
