<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Etablissement\CGroups;
use Hospilibre\Patients\CPatient;
use Hospilibre\Sante400\CIdSante400;
use Ox\Core\CAppUI;

/**
 * Attribue un IPP — identifiant permanent du patient — à chaque patient semé.
 *
 * POURQUOI CE N'EST PAS UN ORNEMENT. Un dossier hospitalier sans IPP n'est pas crédible : c'est
 * par cet identifiant, et jamais par le nom, qu'un établissement désigne un patient dans tous ses
 * échanges — laboratoire, imagerie, courrier, facturation. Un jeu de données dont les dossiers
 * n'en portent pas se voit au premier écran ouvert. Et pour tout système extérieur, un patient
 * semé sans IPP est tout simplement IMPOSSIBLE À RÉFÉRENCER : rien ne le désigne de façon stable.
 *
 * L'IPP NE VIT PAS DANS LA TABLE DU PATIENT. C'est un `CIdSante400` — un identifiant externe —
 * porté par le patient et reconnu à son `tag`. Le tag dépend de l'établissement (`$g` y est
 * remplacé par son identifiant), ce qui permet à une même instance d'héberger deux établissements
 * numérotant chacun ses patients. `CPatient::getTagIPP()` est la seule source de ce tag, et elle
 * rend `null` quand la configuration `dPpatients CPatient tag_ipp` est vide — auquel cas AUCUN
 * écran n'affiche d'IPP. C'est pourquoi la commande s'assure d'abord que cette configuration
 * existe : poser des identifiants qu'aucun écran ne montre serait un travail invisible.
 *
 * NUMÉROTATION SÉQUENTIELLE, ET NON HACHÉE. Le déterminisme vient gratuitement de l'ordre de
 * création des patients, qui est lui-même déterministe : deux semis à graine égale attribuent les
 * mêmes IPP aux mêmes patients. Un hachage de l'identité aurait l'air plus savant, mais il
 * demanderait une résolution de collisions — donc un ordre de parcours, donc exactement la
 * dépendance qu'il prétendait éviter — pour aucun gain.
 *
 * PLAGE DISJOINTE. Les patients « ancrés » d'un scénario pédagogique portent des IPP écrits à la
 * main, de l'ordre de quelques milliers. La masse démarre par défaut à 1 000 001, trois ordres de
 * grandeur au-dessus : deux personnes différentes ne peuvent pas partager un identifiant, ce qui
 * est précisément la panne que l'IPP existe pour empêcher. Le préfixe, lui, reste COMMUN aux deux
 * étages — un hôpital numérote ses patients d'une seule façon, et deux formats distincts
 * désigneraient les patients du scénario mieux qu'un surligneur.
 *
 * PURGEABLE AVEC LE RESTE. L'identifiant passe par le chemin d'écriture du seed, donc il est
 * tracé comme tout le reste et tombe dans ce que `--purge` défait. Un IPP orphelin survivant à
 * une purge ressusciterait au semis suivant en doublon d'un autre patient.
 *
 * Le registre reçoit le CHEMIN D'ÉCRITURE et non le contexte, bien que le reste des builders
 * prenne le contexte : celui-ci le porte, donc le lui passer serait circulaire.
 */
final class IppRegistry
{
    /** Préfixe par défaut. Court, lisible à l'oral, et sans ambiguïté avec un numéro de séjour. */
    public const DEFAULT_PREFIX = 'CHU-';

    /** Premier numéro de la masse. Voir « plage disjointe » en tête de classe. */
    public const DEFAULT_START = 1000001;

    /**
     * Tag par défaut, posé dans la configuration de l'instance quand elle n'en a pas.
     *
     * `$g` est remplacé par l'identifiant de l'établissement par `CPatient::getTagIPP()`.
     */
    public const DEFAULT_TAG = 'hospilibre:ipp:$g';

    /** @var SeedWriter */
    private $writer;

    /** @var string */
    private $prefix;

    /** @var int Prochain numéro à attribuer à un patient de la masse */
    private $next;

    /** @var int Premier numéro de la plage de masse, conservé pour le rapport */
    private $start;

    /** @var string|null Tag résolu pour l'établissement, `$g` déjà substitué */
    private $tag;

    /** @var array<string, int> IPP attribué => identifiant du patient qui le porte */
    private $issued = [];

    /** @var bool Simulation : rien n'est en base, le tag ne peut pas être résolu */
    private $simulated;

    public function __construct(SeedWriter $writer, string $prefix, int $start, bool $simulated = false)
    {
        $this->writer    = $writer;
        $this->prefix    = $prefix;
        $this->next      = $start;
        $this->start     = $start;
        $this->simulated = $simulated;
    }

    /**
     * Résout le tag de l'établissement. À appeler une fois le référentiel écrit.
     *
     * EN SIMULATION, le tag n'est PAS résolu. L'établissement d'un dry-run n'existe pas en base :
     * son identifiant est synthétique, et `CPatient::getTagIPP()` irait charger un établissement
     * qui n'est pas celui-là — ou aucun. Un tag de convention suffit, puisque le seul intérêt du
     * dry-run ici est de COMPTER les identifiants qu'un vrai semis écrirait ; les omettre ferait
     * sous-estimer le volume d'un objet par patient.
     *
     * @throws SeedException si l'instance n'affiche pas d'IPP
     */
    public function bind(CGroups $group): void
    {
        if ($this->simulated) {
            $this->tag = 'simulation';

            return;
        }

        $tag = CPatient::getTagIPP($group->_id);

        if (!$tag) {
            throw new SeedException(
                'Cette instance n\'affiche pas d\'IPP : la configuration'
                . ' « dPpatients CPatient tag_ipp » est vide, et CPatient::getTagIPP() rend donc'
                . " null. Les identifiants seraient écrits sans qu'aucun écran ne les montre."
                . "\nRelancer sans --no-ipp laisse la commande poser le tag «  "
                . self::DEFAULT_TAG . " » dans includes/config.php, ou le renseigner à la main"
                . ' dans la configuration du module dPpatients.'
            );
        }

        $this->tag = $tag;
    }

    /** Tag effectivement employé, pour le rapport. */
    public function tag(): ?string
    {
        return $this->tag;
    }

    /**
     * Réserve un IPP imposé par un patient ancré, avant que la masse ne commence à numéroter.
     *
     * Réserver n'écrit rien : cela interdit seulement à la masse de réattribuer ce numéro. Un
     * ancré dont l'IPP tomberait dans la plage de la masse est refusé ici plutôt que découvert
     * plus tard sous la forme de deux patients partageant un identifiant.
     *
     * @throws SeedException
     */
    public function reserve(string $ipp): void
    {
        if (isset($this->issued[$ipp])) {
            throw new SeedException(
                "L'IPP '{$ipp}' est imposé deux fois : deux patients distincts porteraient le même"
                . ' identifiant, ce que l\'IPP existe précisément pour empêcher.'
            );
        }

        $this->issued[$ipp] = 0;
    }

    /**
     * Attribue et écrit l'IPP d'un patient déjà écrit.
     *
     * @param string|null $imposed IPP imposé (patient ancré), ou null pour numéroter la masse
     *
     * @return string l'IPP attribué
     * @throws SeedException
     */
    public function assign(CPatient $patient, ?string $imposed = null): string
    {
        if ($this->tag === null) {
            throw new SeedException('IppRegistry::bind() n\'a pas été appelé : aucun tag connu.');
        }

        if (!$patient->_id) {
            // Un identifiant externe désigne son objet par (object_class, object_id) : posé avant
            // l'écriture du patient, il pointerait dans le vide.
            throw new SeedException('IPP impossible : le patient n\'est pas encore écrit.');
        }

        $ipp = $imposed ?? $this->nextFree();

        if (($this->issued[$ipp] ?? 0) !== 0) {
            throw new SeedException("L'IPP '{$ipp}' est déjà porté par le patient #{$this->issued[$ipp]}.");
        }

        $idex               = new CIdSante400();
        $idex->object_class = $patient->_class;
        $idex->object_id    = $patient->_id;
        $idex->tag          = $this->tag;
        $idex->id400        = $ipp;

        $this->writer->write($idex);

        $this->issued[$ipp] = (int)$patient->_id;

        // Renseigné aussi en mémoire : les builders suivants — et l'export — lisent l'IPP du
        // patient sans avoir à recharger ses identifiants externes un par un.
        $patient->_IPP = $ipp;

        return $ipp;
    }

    /** Nombre d'IPP écrits. */
    public function count(): int
    {
        return count(array_filter($this->issued));
    }

    /**
     * Préfixe, prochain numéro libre, et premier numéro de la plage — pour le rapport.
     *
     * Le premier numéro est rendu avec les deux autres pour que l'appelant puisse dire si la masse
     * a numéroté QUOI QUE CE SOIT : une cohorte entièrement ancrée n'en consomme aucun, et annoncer
     * alors « masse jusqu'à CHU-1000000 » désignerait un numéro qui n'existe pas.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    public function massRange(): array
    {
        return [$this->prefix, $this->next, $this->start];
    }

    /**
     * Prochain numéro libre de la masse.
     *
     * Saute ce qu'un ancré occupe déjà : un scénario a le droit d'imposer un IPP n'importe où, et
     * c'est à la masse de s'écarter, pas à lui.
     */
    private function nextFree(): string
    {
        do {
            $ipp = $this->prefix . $this->next;
            $this->next++;
        } while (isset($this->issued[$ipp]));

        return $ipp;
    }

    /**
     * La configuration de l'instance affiche-t-elle les IPP ?
     *
     * Lue sur le global et non sur `getTagIPP()`, qui exige un établissement et met son résultat
     * en cache — la commande a besoin de le savoir AVANT d'avoir construit quoi que ce soit.
     */
    public static function instanceDisplaysIpp(): bool
    {
        return (string)CAppUI::conf('dPpatients CPatient tag_ipp') !== '';
    }

    /**
     * Pose le tag d'IPP dans la configuration de l'instance, si elle n'en a pas.
     *
     * Écrit par AJOUT dans `includes/config.php`, et non par `CMbConfig::update()` : celui-ci
     * fusionne avec le `$dPconfig` global, c'est-à-dire qu'il recopierait tout `config_dist.php`
     * dans le fichier d'instance en y figeant des valeurs par défaut qui doivent rester dans la
     * distribution. Même raisonnement, et même précédent, que `ox-install:externals --declare`.
     *
     * Ne touche à RIEN quand la configuration est déjà renseignée : une instance qui a sa propre
     * convention d'IPP la garde, et le seed s'y conforme.
     *
     * @return string|null le tag posé, ou null si la configuration en avait déjà un
     * @throws SeedException
     */
    public static function declareTagInConfig(string $root): ?string
    {
        if (self::instanceDisplaysIpp()) {
            return null;
        }

        $file = rtrim($root, '/') . '/includes/config.php';

        if (!is_file($file) || !is_writable($file)) {
            throw new SeedException(
                "Le fichier de configuration {$file} est absent ou non inscriptible : impossible d'y"
                . ' poser le tag d\'IPP. Le renseigner à la main dans la configuration du module'
                . ' dPpatients, ou relancer avec --no-ipp.'
            );
        }

        $block = "\n\n// Tag d'IPP posé par ox-seed:hospi le " . date('Y-m-d H:i') . ".\n"
            . "// Sans lui, CPatient::getTagIPP() rend null et AUCUN écran n'affiche d'IPP.\n"
            . "\$dPconfig['dPpatients']['CPatient']['tag_ipp'] = "
            . var_export(self::DEFAULT_TAG, true) . ";\n";

        if (file_put_contents($file, $block, FILE_APPEND) === false) {
            throw new SeedException("Écriture impossible dans {$file}.");
        }

        // Posé aussi en mémoire : la suite de CETTE exécution lit la configuration par
        // CAppUI::conf(), qui consulte le global et non le fichier.
        $GLOBALS['dPconfig']['dPpatients']['CPatient']['tag_ipp'] = self::DEFAULT_TAG;

        return self::DEFAULT_TAG;
    }
}
