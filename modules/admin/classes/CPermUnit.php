<?php

/**
 * @package Hospilibre\Admin
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\Admin;

use Ox\Core\CAppUI;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;

/**
 * Le périmètre d'exercice : un agent ne touche le dossier de soins que des patients de SON unité.
 *
 * TROISIÈME NOTION DE DROIT, à côté des deux qui existaient. `CPermModule` dit à quels MODULES on
 * accède, `CPermObject` à quels OBJETS NOMMÉS — l'un et l'autre sont des listes posées d'avance par
 * un administrateur. Aucun des deux ne sait exprimer « les patients qui sont ici en ce moment »,
 * parce que cette liste change à chaque admission et à chaque transfert : la nommer serait la
 * réécrire tous les jours. Le périmètre se CALCULE donc au moment de l'accès, en comparant deux
 * faits que l'application connaît déjà — les unités où l'agent exerce, et l'unité où le patient se
 * trouve.
 *
 * ⚠ IL NE DONNE JAMAIS RIEN, IL RETIRE. `CPermObject::getPermObject()` l'interroge APRÈS avoir
 * établi le droit ordinaire, et un périmètre qui s'applique ne peut que refuser. C'est la seule
 * composition qui soit sûre : un filtre qui pourrait accorder deviendrait une porte dérobée
 * contournant la grille métier, et il suffirait d'un bogue dans le calcul pour ouvrir un dossier
 * qu'aucun administrateur n'a autorisé.
 *
 * ⚠ ET IL NE REFUSE QUE QUAND IL SAIT. Trois situations le font se taire, et ce ne sont pas des
 * oublis mais la règle même :
 *
 *   — AGENT SANS UNITÉ. Les administratifs, les anesthésistes et le compte d'administration
 *     n'exercent dans aucun service. Leur périmètre est vide, et un périmètre vide traité comme
 *     « aucun patient » les enfermerait dehors — le compte d'administration le premier. Leur
 *     restriction est ailleurs, dans la grille module par module : les admissions n'ont pas le
 *     module de soins, c'est à ce niveau que ça se joue, pas ici.
 *   — SÉJOUR SANS AUCUNE AFFECTATION. Un séjour ambulatoire ou externe n'occupe pas de lit :
 *     l'unité d'hébergement n'existe pas, elle n'est pas inconnue. Refuser faute de donnée serait
 *     refuser sur rien.
 *   — OBJET SANS SÉJOUR. Une transmission peut s'accrocher à une consultation ; la notion d'unité
 *     n'y a pas de sens.
 *
 * Dans ces trois cas le droit ordinaire décide seul, exactement comme avant.
 *
 * CE QUI OUVRE L'ACCÈS, quand le périmètre s'applique — l'un OU l'autre suffit :
 *
 *   1. LE PATIENT EST CHEZ MOI MAINTENANT. Une affectation du séjour, dans une de mes unités,
 *      dont la fenêtre contient l'instant présent. C'est ce qui permet à l'infirmière de réanimation
 *      d'ouvrir le dossier d'un patient arrivé il y a dix minutes, sur lequel elle n'a encore rien
 *      écrit.
 *   2. J'AI PRIS CE SÉJOUR EN CHARGE. J'y ai signé une observation ou une transmission, ou j'en suis
 *      le praticien responsable. C'est ce qui lui garde son dossier d'hier quand le patient est
 *      transféré en cardiologie ce matin — elle ne perd pas ce qu'elle a fait.
 *
 * LECTURE INDIVIDUELLE ET NON COLLECTIVE, et c'est un choix qui se discute. « Pris en charge »
 * s'entend ici de l'agent (j'ai écrit sur ce séjour) et non de son unité (mon service a hébergé ce
 * patient). La lecture collective serait plus permissive : tout agent de réanimation garderait tout
 * séjour passé en réanimation. Elle a sa logique — une équipe soigne, pas une personne — mais elle
 * rend l'accès définitif dès qu'un patient a traversé l'unité, ce que la première sentence du
 * besoin exclut. Le choix retenu est le plus strict des deux ; basculer tiendrait à retirer la
 * condition de date dans `involved()`.
 */
final class CPermUnit
{
    /**
     * Les classes soumises au périmètre, et UNIQUEMENT elles.
     *
     * LISTE BLANCHE ET NON LISTE NOIRE : tout ce qui n'est pas nommé ici échappe au périmètre. Une
     * liste noire aurait l'inconvénient inverse — chaque classe ajoutée au logiciel se retrouverait
     * filtrée par un prédicat écrit sans la connaître, et le premier symptôme serait un refus
     * inexplicable sur un écran sans rapport.
     *
     * Noms NON QUALIFIÉS parce que c'est ce que porte `CStoredObject::$_class`, et ça évite de lier
     * ce fichier aux espaces de noms des modules cliniques.
     */
    public const SCOPED_CLASSES = [
        'CObservationMedicale',
        'CTransmissionMedicale',
    ];

    /** @var array<array-key, int[]> user_id => les services où il exerce */
    private static $units = [];

    /** @var array<array-key, array{any: bool, current: int[]}> sejour_id => où est le patient */
    private static $stays = [];

    /** @var array<array-key, bool> user_id => il est nommé sur au moins une intervention */
    private static $theatre = [];

    /** @var array<array-key, array<array-key, bool>> user_id => sejour_id => il y a participé */
    private static $involvement = [];

    /**
     * Le verdict, par utilisateur / classe / objet.
     *
     * `array-key` PARTOUT OÙ LA CLÉ EST UN IDENTIFIANT, et ce n'est pas une concession à l'analyse
     * statique : PHP NORMALISE une clé numérique en entier, si bien que `$t['123']` et `$t[123]`
     * désignent la même case. Annoncer des clés `string` décrivait donc un tableau qui n'existe
     * pas — et transtyper à l'indexation pour s'y conformer ne changeait rien, puisque le langage
     * défait le transtypage aussitôt.
     *
     * @var array<array-key, array<string, array<array-key, bool>>>
     */
    private static $decisions = [];

    /**
     * Le périmètre est-il en service sur cette instance ?
     *
     * ⚠ L'ABSENCE DE CONFIGURATION VAUT « ACTIF », et ce n'est pas une commodité : c'est la seule
     * écriture qui marche sur les instances déployées. Les valeurs par défaut des modules ne sont
     * pas lues depuis le `config.php` des modules à l'exécution — `CConfigDist` les COMPILE une fois
     * dans `includes/config_dist.php`, fichier qui est ignoré par git ET, sur les instances en
     * service, monté depuis l'hôte EN LECTURE SEULE (`<hôte>/config_dist.php`, voir
     * docker-compose.yml). Une clé ajoutée au module n'y figure donc pas, l'entrypoint ne peut pas
     * l'y écrire, et il ne régénère de toute façon le fichier que s'il est ABSENT.
     *
     * Lire « absent donc faux » aurait livré un droit de confidentialité DÉSACTIVÉ sur toutes les
     * instances, sans message et sans qu'aucun test ne s'en aperçoive — le rapport `ox-perms:scope`
     * l'a d'ailleurs montré sur l'instance de développement avant cette correction : zéro refus,
     * verdict satisfait, périmètre inopérant.
     *
     * Seul un `'0'` EXPLICITE éteint donc le périmètre, et il faut pour cela un `config_dist.php`
     * régénéré ou un `config_overload.php` — c'est-à-dire un geste délibéré.
     */
    public static function isActive(): bool
    {
        $configured = CAppUI::conf('admin CPermUnit active');

        if ($configured === null || $configured === '') {
            return true;
        }

        return (bool)$configured;
    }

    /**
     * L'accès à cet objet doit-il être REFUSÉ à cet utilisateur ?
     *
     * Vrai = refuser. Le défaut est faux : toute situation que le périmètre ne sait pas trancher
     * laisse le droit ordinaire décider.
     */
    public static function denies(CStoredObject $object, CUser $user): bool
    {
        if (!self::isActive()) {
            return false;
        }

        if (!in_array($object->_class, self::SCOPED_CLASSES, true)) {
            return false;
        }

        // OBJET NON ENREGISTRÉ : il n'a pas d'identifiant, donc pas de séjour à situer. La création
        // relève du droit sur le module, pas d'un périmètre qui n'a rien à comparer.
        if (!$object->_id || !$user->_id) {
            return false;
        }

        if (isset(self::$decisions[$user->_id][$object->_class][$object->_id])) {
            return self::$decisions[$user->_id][$object->_class][$object->_id];
        }

        $denied = self::compute($object, $user);

        self::$decisions[$user->_id][$object->_class][$object->_id] = $denied;

        return $denied;
    }

    /**
     * Vide les caches.
     *
     * POUR LES TESTS ET POUR LES TRAITEMENTS LONGS. Les caches valent le temps d'une requête HTTP,
     * où ni l'affectation du patient ni l'exercice de l'agent ne changent. Une commande qui
     * déplacerait des patients puis vérifierait des droits dans le même processus lirait un état
     * périmé — d'où cette porte, explicite.
     */
    public static function resetCache(): void
    {
        self::$units       = [];
        self::$stays       = [];
        self::$involvement = [];
        self::$theatre     = [];
        self::$decisions   = [];
    }

    private static function compute(CStoredObject $object, CUser $user): bool
    {
        // L'ADMINISTRATEUR N'A PAS D'UNITÉ et doit garder la main sur tout : c'est lui qui répare
        // quand le périmètre se trompe, et l'y soumettre rendrait la panne irréparable depuis
        // l'application.
        if ($user->isAdmin()) {
            return false;
        }

        $sejour_id = isset($object->sejour_id) ? (int)$object->sejour_id : 0;

        if (!$sejour_id) {
            return false;
        }

        $units = self::unitsOf($user->_id);

        if ($units === []) {
            // AGENT SANS UNITÉ : deux cas que tout opposait, et les confondre laissait une moitié
            // du personnel soignant hors de la règle.
            //
            // L'ANESTHÉSISTE N'EXERCE DANS AUCUN SERVICE — c'est un fait du métier, pas une donnée
            // manquante : il travaille au bloc. Son rattachement durable est donc vide, et la
            // clause « le patient est dans une de mes unités » ne peut JAMAIS jouer pour lui. Tant
            // que « sans unité » valait « dispensé », il voyait l'intégralité du dossier de soins de
            // l'établissement, et ajouter sa participation aux clauses d'ouverture ne changeait
            // rien puisqu'il n'était jamais refusé. Mesuré avant correction.
            //
            // CE QUI LE RATTACHE EXISTE DÉJÀ : l'intervention le NOMME, comme anesthésiste ou comme
            // praticien de la visite pré-anesthésique — 347 sur 347 sur le semis. Son périmètre est
            // donc celui des séjours où il est intervenu, et il n'a besoin d'aucune donnée nouvelle
            // — surtout pas du compte rendu anesthésique, qui n'existe pas en base et qui serait de
            // toute façon postérieur à l'acte, là où `anesth_id` est posé dès la programmation.
            //
            // LES AUTRES RESTENT DISPENSÉS : les administratifs et le compte d'administration
            // n'interviennent sur aucun séjour, et les enfermer dehors n'aurait aucun sens. Leur
            // restriction est la grille module par module.
            if (!self::worksInTheatre($user->_id)) {
                return false;
            }

            // ⚠ PAS D'EXEMPTION « SÉJOUR SANS AFFECTATION » DANS CETTE BRANCHE, et c'est délibéré :
            // cette exemption existe parce que la notion d'unité n'a pas de sens sur un séjour sans
            // lit. Ici la notion d'unité n'intervient pas du tout — un séjour ambulatoire opéré est
            // précisément le cas normal du bloc.
            return !self::involved($user->_id, $sejour_id);
        }

        $stay = self::stay($sejour_id);

        if (!$stay['any']) {
            return false;
        }

        if (array_intersect($units, $stay['current']) !== []) {
            return false;
        }

        return !self::involved($user->_id, $sejour_id);
    }

    /**
     * L'agent intervient-il au bloc, sans tenir de service ?
     *
     * LE PRÉDICAT EST « IL EST NOMMÉ SUR UNE INTERVENTION », et non « sa fonction s'appelle
     * Anesthésie ». Le libellé d'une fonction est du texte libre que chaque établissement écrit à
     * sa façon ; le nommer en dur ferait dépendre un droit de confidentialité d'une chaîne de
     * caractères saisie à l'écran. Être inscrit sur une intervention est un fait, pas un intitulé.
     *
     * CONSÉQUENCE ASSUMÉE : un anesthésiste qui vient d'arriver et n'est encore programmé sur
     * aucune intervention est DISPENSÉ, comme un administratif. C'est le bon côté de l'erreur —
     * le périmètre ne refuse que quand il sait —, et la situation se résout d'elle-même dès sa
     * première programmation.
     */
    private static function worksInTheatre(string $user_id): bool
    {
        if (isset(self::$theatre[$user_id])) {
            return self::$theatre[$user_id];
        }

        $ds = CSQLDataSource::get('std');

        $query = $ds->prepare(
            "SELECT 1 FROM operations WHERE anesth_id = %1 OR prat_visite_anesth_id = %1
          UNION SELECT 1 FROM consultation_anesth ca
                  JOIN consultation c ON c.consultation_id = ca.consultation_id
                  JOIN plageconsult p ON p.plageconsult_id = c.plageconsult_id
                 WHERE p.chir_id = %1
          LIMIT 1",
            $user_id
        );

        return self::$theatre[$user_id] = (bool)$ds->loadResult($query);
    }

    /**
     * Les services où l'agent exerce durablement.
     *
     * « date IS NULL » DIT L'APPARTENANCE, et ce n'est pas un détournement de colonne :
     * `CAffectationUserService` porte les deux sémantiques par conception, et son propre accesseur
     * en fait la preuve — `listUsersService()` filtre sur une date nulle pour rendre les agents du
     * service, tandis que `loadResponsableJour()` interroge une date précise pour le responsable du
     * jour. Une ligne datée est un tour de garde ; une ligne non datée, un rattachement.
     *
     * @return int[]
     */
    private static function unitsOf(string $user_id): array
    {
        if (isset(self::$units[$user_id])) {
            return self::$units[$user_id];
        }

        $ds = CSQLDataSource::get('std');

        $query = $ds->prepare(
            "SELECT DISTINCT service_id FROM affectation_user_service
              WHERE user_id = %1 AND date IS NULL AND service_id IS NOT NULL",
            $user_id
        );

        // `loadColumn` ET NON `loadHashList` : ce dernier lit `$hash[1]`, la DEUXIÈME colonne, et
        // rendrait des valeurs nulles sur une requête qui n'en a qu'une.
        $rows = $ds->loadColumn($query);

        // UN ÉCHEC SQL REND `false`, PAS UN TABLEAU VIDE. Le confondre avec « aucune unité »
        // tomberait du bon côté ici — périmètre vide, donc aucun refus — mais par accident ; on
        // l'écrit pour que ça reste vrai si la règle du périmètre vide change un jour.
        if (!is_array($rows)) {
            return self::$units[$user_id] = [];
        }

        return self::$units[$user_id] = array_map('intval', $rows);
    }

    /**
     * Où est le patient de ce séjour, et y a-t-il jamais été quelque part ?
     *
     * LES DEUX RENSEIGNEMENTS EN UNE SEULE REQUÊTE, parce qu'ils ne sont pas interchangeables :
     * « aucune affectation courante » et « aucune affectation du tout » mènent à des décisions
     * opposées. Le premier veut dire que le patient est sorti de l'unité — le périmètre s'applique,
     * seule la participation ouvre encore l'accès. Le second, que le séjour n'occupe pas de lit :
     * le périmètre se tait. Les confondre refuserait tout l'ambulatoire à tout le monde.
     *
     * @return array{any: bool, current: int[]}
     */
    private static function stay(int $sejour_id): array
    {
        if (isset(self::$stays[$sejour_id])) {
            return self::$stays[$sejour_id];
        }

        $ds = CSQLDataSource::get('std');

        $query = $ds->prepare(
            "SELECT service_id, (%2 BETWEEN entree AND sortie) AS courante
               FROM affectation WHERE sejour_id = %1",
            $sejour_id,
            CMbDT::dateTime()
        );

        $rows = $ds->loadList($query);

        // ⚠ UN ÉCHEC SQL REND `false`, et ici la confusion serait GRAVE : `false !== []` est vrai,
        // si bien qu'une requête en échec se lirait « ce séjour a des affectations » et ferait
        // appliquer le périmètre sur une liste d'unités vide — donc refuser tout, à tout le monde,
        // à cause d'une panne de base. L'échec doit se lire « je ne sais pas ».
        if (!is_array($rows)) {
            return self::$stays[$sejour_id] = ['any' => false, 'current' => []];
        }

        $current = [];

        foreach ($rows as $_row) {
            if ($_row['courante']) {
                $current[] = (int)$_row['service_id'];
            }
        }

        return self::$stays[$sejour_id] = [
            'any'     => $rows !== [],
            'current' => array_values(array_unique($current)),
        ];
    }

    /**
     * L'agent a-t-il pris ce séjour en charge ?
     *
     * SIX TRACES, et une seule suffit : avoir signé une observation, avoir signé une transmission,
     * être le praticien responsable du séjour, être nommé sur une de ses interventions — comme
     * ANESTHÉSISTE ou comme praticien de la VISITE pré-anesthésique —, ou avoir mené la
     * CONSULTATION pré-anesthésique. Ce sont les traces que l'application porte déjà ; en inventer
     * une septième demanderait une table d'intervenants que le logiciel n'a pas.
     *
     * ⚠ LA CONSULTATION EST UNE TRACE À PART ENTIÈRE, et l'oublier laissait un trou précis. Le droit
     * français sépare deux actes (art. D.6124-91 CSP) : la CONSULTATION pré-anesthésique, plusieurs
     * jours avant une intervention réglée, et la VISITE, dans les heures qui précèdent. Ce ne sont
     * pas les mêmes praticiens dans le cas courant — l'anesthésiste qui consulte n'est pas forcément
     * celui qui endort. Or seule la visite laisse une marque SUR l'intervention
     * (`prat_visite_anesth_id`) ; celui qui a consulté n'y figure nulle part. Sans cette sixième
     * trace, le périmètre refusait donc à un anesthésiste le dossier du patient qu'il venait de voir
     * en consultation.
     *
     * Le chemin passe par la plage : `consultation_anesth` -> `consultation` -> `plageconsult`, dont
     * `chir_id` porte le praticien. `CConsultation` n'a pas de champ de praticien propre — c'est sa
     * plage qui le désigne.
     *
     * LES DEUX DERNIÈRES SONT CE QUI DONNE UN PÉRIMÈTRE AUX ANESTHÉSISTES. Ils ne tiennent aucun
     * service : leur rattachement durable est vide, si bien que la première clause du périmètre —
     * « le patient est dans une de mes unités » — ne peut jamais jouer pour eux, et qu'ils
     * échappaient donc entièrement à la règle. L'intervention, elle, les nomme : mesuré 347 sur 347
     * sur le semis. Leur périmètre n'a besoin d'aucune donnée nouvelle, et surtout pas du compte
     * rendu anesthésique — qui n'existe pas encore en base (zéro ligne dans `compte_rendu` comme
     * dans `consultation_anesth`) et qui ne serait de toute façon qu'une trace de plus, postérieure
     * à l'acte, là où `anesth_id` est posé dès la programmation.
     *
     * UNION ET NON TROIS REQUÊTES : la réponse cherchée est booléenne, et `LIMIT 1` permet au
     * serveur de s'arrêter à la première trace trouvée.
     */
    private static function involved(string $user_id, int $sejour_id): bool
    {
        if (isset(self::$involvement[$user_id][$sejour_id])) {
            return self::$involvement[$user_id][$sejour_id];
        }

        $ds = CSQLDataSource::get('std');

        $query = $ds->prepare(
            "SELECT 1 FROM sejour WHERE sejour_id = %1 AND praticien_id = %2
          UNION SELECT 1 FROM observation_medicale WHERE sejour_id = %1 AND user_id = %2
          UNION SELECT 1 FROM transmission_medicale WHERE sejour_id = %1 AND user_id = %2
          UNION SELECT 1 FROM operations WHERE sejour_id = %1 AND anesth_id = %2
          UNION SELECT 1 FROM operations WHERE sejour_id = %1 AND prat_visite_anesth_id = %2
          UNION SELECT 1 FROM consultation_anesth ca
                  JOIN consultation c ON c.consultation_id = ca.consultation_id
                  JOIN plageconsult p ON p.plageconsult_id = c.plageconsult_id
                 WHERE ca.sejour_id = %1 AND p.chir_id = %2
          LIMIT 1",
            $sejour_id,
            $user_id
        );

        $involved = (bool)$ds->loadResult($query);

        return self::$involvement[$user_id][$sejour_id] = $involved;
    }
}
