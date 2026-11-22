<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Jeu de noms fictifs embarqué et versionné.
 *
 * POURQUOI EMBARQUÉ : le générateur de fixtures existant tire ses prénoms de la table de
 * référence `firstname_to_gender` (CFirstNameAssociativeSex), qui peut être vide selon
 * l'installation. Le seed doit être reproductible sur une machine neuve : une source de noms
 * dépendante de la base introduirait une variabilité que la graine ne contrôle pas.
 *
 * TROIS SOURCES, dans cet ordre de priorité :
 *
 * 1. un fichier IMPOSÉ par `useFile()` — geste explicite d'un exploitant ou d'un test ;
 * 2. les PERSONAS FR du module `populate` (voir PERSONAS_DIR), source par défaut : prénoms INSEE
 *    et patronymes data.gouv, donc la distribution réelle des noms en France ;
 * 3. les listes EMBARQUÉES ci-dessous, qui garantissent qu'un dépôt fraîchement cloné produise un
 *    jeu de données sans dépendre d'aucun fichier ni d'aucune table.
 *
 * Les trois sont hors ligne et reproductibles : le semis lit le dépôt, jamais un service.
 *
 * SUR LES NOMS EMBARQUÉS : ils sont composés à la main à partir de morphèmes français, et les
 * prénoms sont des prénoms courants écrits à la main. Un prénom courant ne désigne personne ; c'est
 * la COMBINAISON avec un nom de famille qui ferait identité, et des noms de famille inventés la
 * rendent invraisemblable. Un fichier CSV issu d'un générateur peut en revanche contenir de VRAIS
 * patronymes : l'usage est courant pour un jeu de test, et le reste du dossier est fabriqué, mais
 * c'est un arbitrage à connaître — l'homonymie devient alors possible.
 *
 * VERSION : incrémenter VERSION à chaque modification du contenu. Changer une liste change
 * les données produites à graine égale ; la version rend ce changement lisible dans un
 * journal de seed, au lieu d'une divergence inexplicable entre deux ranges. Remplacer les listes
 * par un CSV a le même effet, et c'est pourquoi la source est rapportée par la commande.
 */
final class FictitiousNames
{
    public const VERSION = 2;

    public const SEX_MALE   = 'm';
    public const SEX_FEMALE = 'f';

    /**
     * ⚠ PLUS DE FICHIER D'IDENTITÉS IMPLICITE — et c'est le point de cette suppression.
     *
     * Il existait ici une constante `FILE = 'cli/resources/seed-identites.csv'` que `load()`
     * lisait DÈS QU'ELLE TROUVAIT LE FICHIER. Le fichier a été retiré du dépôt quand les identités
     * sont passées aux personas INSEE, mais le chemin de lecture est resté : n'importe quelle copie
     * de travail, image ancienne ou volume qui en gardait un exemplaire voyait donc les identités
     * anglophones — « Playdon, Kathi, Bradly », « Dunkley », « Holt » — reprendre la main SANS UN
     * MOT. Un repli muet vers le jeu qu'on venait d'abandonner est le contraire d'un repli.
     *
     * Un exploitant qui veut imposer ses propres identités passe désormais par `useFile()`, qui est
     * un geste explicite et que `source()` rapporte comme tel.
     */

    /**
     * SOURCE PAR DÉFAUT DES IDENTITÉS : le jeu de personas FR du module `populate`.
     *
     * POURQUOI CE CHANGEMENT. Le fichier livré jusqu'ici produisait des identités à consonance
     * anglophone — « POLLAND, CAITLIN », « Playdon, Kathi, Bradly », « Eliff, Sidonia, Niels » sur
     * 9 018 lignes — dans un dossier patient français. Le repli français codé en dur dans cette
     * classe existait, mais le fichier le masquait : c'est lui qui alimentait les 8 324 patients de
     * la cohorte de production.
     *
     * CE JEU-CI EST MEILLEUR QU'UN GÉNÉRATEUR, et pour des raisons vérifiables :
     *  - `prenoms.csv` : INSEE, « Fichier des prénoms » millésime 2021, 5 022 prénoms distincts
     *    avec leur sexe dominant, seuil de ≥ 500 occurrences sur 1900-2021 ;
     *  - `noms.csv` : data.gouv.fr « Liste de prénoms et patronymes » (extraction SIRENE), les
     *    3 000 patronymes les plus fréquents — Martin, Bernard, Thomas, Durand, Dubois, Moreau ;
     *  - les deux sous Licence Ouverte / Etalab 2.0, provenance, méthode d'agrégation et date de
     *    récupération documentées dans leur ATTRIBUTION.md, donc reproductibles.
     * C'est la distribution réelle des noms en France, pas l'approximation d'un service tiers, et
     * la question de licence est déjà tranchée dans le dépôt.
     *
     * ET C'EST LA MÊME SOURCE QUE L'AUTRE MOTEUR DE PEUPLEMENT. `CPersonaSource` (module
     * `populate`, utilisé par `simulate:`) lit déjà ces deux fichiers. Les deux moteurs partagent
     * désormais leurs identités : c'est ce qui les empêchera de redivergir, et cette divergence-là
     * s'était déjà payée une fois.
     */
    private const PERSONAS_DIR = 'modules/populate/resources/personas';

    /** Colonnes attendues dans ce fichier, lues INDÉPENDAMMENT les unes des autres. */
    private const COLUMNS = ['nom', 'prenom_f', 'prenom_m'];

    /**
     * Colonne facultative : les communes viennent normalement du dictionnaire des communes
     * françaises (cf. CommuneDictionary), qui permet en outre de les choisir AUTOUR de
     * l'établissement. Elle n'a d'utilité que si aucun dictionnaire n'est joignable.
     */
    private const OPTIONAL_COLUMNS = ['commune'];

    /**
     * @var array<string, string[]>|null Listes chargées depuis le fichier, si présent.
     *
     * Mise en cache : le fichier est lu une fois par processus. Chaque colonne donne une liste de
     * valeurs distinctes, dans l'ordre du fichier — l'ordre fait partie de la reproductibilité.
     */
    private static $loaded;

    /**
     * @var string|null Chemin de substitution.
     *
     * Existe pour les tests, qui doivent pouvoir vérifier le chargement sans déposer un fichier
     * dans le dépôt, et pour un opérateur qui voudrait un autre jeu d'identités sans y toucher. Le
     * seed ne l'emploie jamais : il lit toujours le fichier du dépôt.
     */
    private static $path;

    /**
     * @var array<string, array<string, int>>|null Poids des identités : 'nom', 'prenom_f', 'prenom_m'.
     *
     * Renseignés par `loadPersonas()` depuis la troisième colonne de `prenoms.csv`. NULS quand les
     * identités viennent d'un fichier imposé ou des listes embarquées, qui ne portent pas de
     * fréquences — le tirage est alors uniforme, et c'est l'appelant qui le décide.
     */
    private static $weights;

    /** @var string[] Noms de famille inventés par composition */
    private const SURNAMES = [
        'Vaubertrand', 'Chaslemont', 'Ferrandois', 'Marnicourt', 'Delvanier',
        'Beaupertuis', 'Grancoulet', 'Vasselonde', 'Trémignac', 'Bourvillard',
        'Lachenaux', 'Prévotier', 'Mazanteau', 'Ronceverre', 'Estournel',
        'Valmoisier', 'Chantebrise', 'Dufrénoy', 'Merlassin', 'Aubignard',
        'Solivaux', 'Perrandet', 'Gaudissart', 'Vernoulier', 'Tessandier',
        'Molinvray', 'Chapuzel', 'Barthelmond', 'Fressinet', 'Larigaudie',
        'Nourvieux', 'Combelair', 'Sauvetrain', 'Rebillard', 'Quintelier',
        'Pomerelle', 'Anthelmoz', 'Vignerolles', 'Duchauffour', 'Lestraigne',
        'Marbouzet', 'Corbenoy', 'Vaillancourt', 'Serpaillon', 'Thibaudier',
        'Champrenaud', 'Gorsilat', 'Ulmonde', 'Braqueville', 'Nézandier',
        'Falconnier', 'Mirandoux', 'Peyrelonge', 'Cauvinard', 'Doubresse',
        'Vertuneau', 'Lassalvy', 'Grimonpré', 'Ombrelier', 'Jaubertaud',
        'Chevrenoux', 'Malaviette', 'Ronsardin', 'Teyssonne', 'Arbouillet',
        'Guillemart', 'Sancerelle', 'Tourvenel', 'Delpuech', 'Bruneval',
        'Escandier', 'Montaubry', 'Verlaquin', 'Nivollet', 'Charbonneau',
        'Pélissart', 'Rouvencel', 'Vanderghem', 'Salvanois', 'Trébuchon',
        'Aymericourt', 'Bezanceau', 'Clavaresse', 'Dorvilliers', 'Éparvier',
        'Fauconnaud', 'Girbaldier', 'Hurtebise', 'Isambourg', 'Jonquerelle',
        'Kerlanic', 'Lanteaume', 'Morvillers', 'Nortembac', 'Orsanneau',
        'Pouzargues', 'Quéribel', 'Rocheseigne', 'Sablonnière', 'Tourdelin',
        'Ussandier', 'Vaquerol', 'Welmoreau', 'Xanterrel', 'Yvenoux',
        'Zabrelier', 'Amblardin', 'Brissonnet', 'Cazanove', 'Damprigny',
        'Escoffret', 'Fontanelle', 'Gallardon', 'Hémeraux', 'Inglebert',
        'Jarvelin', 'Loupvenant', 'Mescaudier', 'Neyrolles', 'Ollivranche',
    ];

    /** @var string[] Prénoms féminins courants */
    private const FIRST_NAMES_FEMALE = [
        'Agnès', 'Alice', 'Amandine', 'Anaïs', 'Aurélie', 'Béatrice', 'Camille',
        'Carole', 'Catherine', 'Céline', 'Chantal', 'Charlotte', 'Claire', 'Clémence',
        'Colette', 'Delphine', 'Denise', 'Élodie', 'Émilie', 'Estelle', 'Fabienne',
        'Florence', 'Françoise', 'Gabrielle', 'Geneviève', 'Hélène', 'Inès',
        'Isabelle', 'Jeanne', 'Josiane', 'Julie', 'Laure', 'Léa', 'Manon',
        'Margaux', 'Marie', 'Martine', 'Mathilde', 'Michèle', 'Monique', 'Nadine',
        'Nathalie', 'Noémie', 'Océane', 'Odile', 'Pauline', 'Sabine', 'Sandrine',
        'Simone', 'Sophie', 'Sylvie', 'Valérie', 'Véronique', 'Yvette',
    ];

    /** @var string[] Prénoms masculins courants */
    private const FIRST_NAMES_MALE = [
        'Alain', 'Alexandre', 'André', 'Antoine', 'Arnaud', 'Baptiste', 'Benoît',
        'Bernard', 'Bertrand', 'Bruno', 'Cédric', 'Christian', 'Christophe',
        'Clément', 'Daniel', 'David', 'Denis', 'Didier', 'Édouard', 'Émile',
        'Étienne', 'Fabien', 'Florian', 'François', 'Frédéric', 'Gaël', 'Georges',
        'Gérard', 'Gilles', 'Guillaume', 'Henri', 'Hugo', 'Jacques', 'Jean',
        'Jérôme', 'Joël', 'Julien', 'Laurent', 'Lucas', 'Marc', 'Mathieu',
        'Maxime', 'Michel', 'Nicolas', 'Olivier', 'Pascal', 'Patrick', 'Paul',
        'Philippe', 'Pierre', 'Quentin', 'Raphaël', 'Rémi', 'Robert', 'Sébastien',
        'Serge', 'Stéphane', 'Thierry', 'Thomas', 'Vincent', 'Xavier', 'Yann',
    ];

    /**
     * Communes inventées, REPLI DE DERNIER RECOURS.
     *
     * Elles ne servent que si aucun dictionnaire de communes n'est joignable — ni la datasource
     * `INSEE`, ni le fichier de ressources du module `openData` (cf. CommuneDictionary). Dans ce cas
     * les adresses sont fictives, mais elles perdent la cohérence géographique : un hôpital ne
     * recrute plus dans son territoire, puisqu'il n'en a plus.
     *
     * @var string[]
     */
    private const TOWNS = [
            'Valmorin', 'Brécourt', 'Courvieille', 'Montrebec', 'Aubelieu',
            'Pierregaud', 'Fontenailles', 'Larbrissac', 'Vaubourg', 'Sainte-Anselme',
            'Chaumeroy', 'Belleyrac', 'Noirvaux', 'Prélandes', 'Tourvenac',
    ];

    /**
     * Impose un autre fichier d'identités, ou rétablit le fichier du dépôt avec `null`.
     *
     * Vide le cache au passage : sans cela, un test qui change de fichier lirait le précédent.
     */
    public static function useFile(?string $path): void
    {
        self::$path    = $path;
        self::$loaded  = null;
        self::$weights = null;
    }

    /**
     * Empreinte du JEU D'IDENTITÉS employé, pour que « rejouable » veuille dire quelque chose.
     *
     * ⚠ POURQUOI UN CALCUL ET NON LA CONSTANTE VERSION. Cette classe portait déjà la règle, écrite
     * dans son propre en-tête : « incrémenter VERSION à chaque modification du contenu ». J'ai
     * modifié les deux fichiers d'identités DEUX FOIS le 2026-10-04 — ajout des fréquences aux
     * prénoms, puis aux patronymes — sans y toucher. Une règle qu'il faut penser à appliquer n'est
     * pas une garantie, c'est une intention.
     *
     * CE QUE SON ABSENCE A PRODUIT, mesuré par infra sur la production : deux semis lancés avec le
     * MÊME décor, la MÊME graine et le MÊME instant de référence ont donné 16 560 noms différents
     * sur 8 324 patients. Les dates de naissance et les sexes, eux, ne bougeaient qu'à la marge —
     * signature d'un flux aléatoire identique et d'une TABLE D'IDENTITÉS changée. Entre les deux, un
     * déploiement. Or la commande imprime « pour le rejouer à l'identique » suivi des seuls
     * paramètres : quelqu'un qui garde une cohorte comme témoin de référence conclut à une dérive
     * de données là où il y a eu une livraison.
     *
     * L'empreinte est courte à dessein : elle sert à COMPARER deux semis, pas à authentifier un
     * fichier. Huit caractères suffisent pour que deux jeux distincts ne se confondent pas dans un
     * journal lu par un humain.
     *
     * @throws SeedException
     */
    public static function fingerprint(): string
    {
        return substr(sha1(implode('|', self::fingerprintParts())), 0, 8);
    }

    /**
     * Les composants de l'empreinte, pour qu'elle soit REFAISABLE SANS LE CODE.
     *
     * ⚠ UNE EMPREINTE QU'ON NE PEUT PAS RECALCULER SANS LIRE LA SOURCE TRANSFORME UN CONTRÔLE EN
     * ACTE DE FOI. Infra a essayé sept recettes plausibles sur les fichiers extraits d'une image de
     * production sans retomber sur la bonne valeur : il manquait le troisième composant, la
     * constante VERSION, que rien dans une empreinte de huit caractères ne laisse devancer. Leur
     * conclusion — « écrire INCONNUE plutôt que recopier une étiquette peut-être fausse » — était
     * la bonne, et c'est la recette qui était en défaut, pas leur méthode.
     *
     * Les composants sont donc imprimés à côté de l'empreinte : avec eux, un relecteur qui n'a ni
     * le dépôt ni le code refait le calcul — md5 de chaque fichier, jointure par « | », sha1,
     * tronqué à huit caractères.
     *
     * @return string[]
     */
    public static function fingerprintParts(): array
    {
        $root  = dirname(__DIR__, 3);
        $parts = [];

        if (self::$path !== null) {
            $parts[] = is_file(self::$path) ? (string)md5_file(self::$path) : 'absent';
        } else {
            foreach (['prenoms.csv', 'noms.csv'] as $file) {
                $path    = $root . '/' . self::PERSONAS_DIR . '/' . $file;
                $parts[] = is_file($path) ? (string)md5_file($path) : 'absent';
            }
        }

        // Les listes codées en dur entrent aussi dans l'empreinte : elles servent de repli, et un
        // semis qui les emploie doit être distinguable d'un semis qui lit les personas.
        $parts[] = (string)self::VERSION;

        return $parts;
    }

    /**
     * D'où viennent les identités : le fichier, ou les listes embarquées.
     *
     * Rapporté par la commande, parce que cela change les données produites à graine égale.
     *
     * @throws SeedException
     */
    public static function source(): string
    {
        $file = self::load();

        if ($file === null) {
            return 'listes embarquées (v' . self::VERSION . ', empreinte ' . self::fingerprint() . ')';
        }

        // ⚠ NOMMER LA VRAIE SOURCE. Cette méthode annonçait « fichier
        // cli/resources/seed-identites.csv » DANS TOUS LES CAS où un jeu était chargé, personas
        // comprises — c'est-à-dire qu'elle désignait le jeu anglophone supprimé alors que le semis
        // lisait les personas INSEE. Le rapport du semis affirmait donc le contraire de ce qui se
        // passait, et c'est par lui qu'on croyait les noms anglophones encore en service.
        $origin = self::$path !== null
            ? 'fichier imposé ' . self::$path
            : 'personas FR ' . self::PERSONAS_DIR;

        // LES COMPOSANTS À CÔTÉ DE L'EMPREINTE : c'est ce qui la rend refaisable par un relecteur
        // qui n'a ni le dépôt ni le code — md5 de prenoms.csv, md5 de noms.csv, VERSION, joints par
        // « | », puis sha1 tronqué à huit caractères.
        return $origin . ' (' . count($file['nom']) . ' noms, '
            . count($file['prenom_f']) . ' prénoms f, ' . count($file['prenom_m']) . ' prénoms m, '
            . 'empreinte ' . self::fingerprint()
            . ' = sha1[0:8] de « ' . implode(' | ', self::fingerprintParts()) . ' »)';
    }

    /**
     * @return string[]
     * @throws SeedException
     */
    /**
     * Poids des patronymes, ou tableau vide si la source n'en porte pas.
     *
     * MÊME RAISON QUE POUR LES PRÉNOMS, avec une amplitude moindre : « Martin » compte 31 781
     * porteurs dans l'extraction SIRENE quand la médiane des 3 000 retenus en compte 648 — un
     * facteur cinquante, là où « Marie » pèse mille deux cents fois « Randy ». Le tirage uniforme
     * était donc moins visiblement faux sur les patronymes, et faux de la même façon.
     *
     * @return array<string, int>
     * @throws SeedException
     */
    public static function surnameWeights(): array
    {
        self::load();

        return self::$weights['nom'] ?? [];
    }

    public static function surnames(): array
    {
        $file = self::load();

        return $file === null ? self::SURNAMES : $file['nom'];
    }

    /**
     * Communes des patients.
     *
     * Ici et non dans PatientBuilder, parce que c'est une donnée identifiante de la même nature que
     * les noms : elle vient de la même source, et elle doit pouvoir venir du même fichier.
     *
     * @return string[]
     * @throws SeedException
     */
    public static function towns(): array
    {
        $file = self::load();

        return empty($file['commune']) ? self::TOWNS : $file['commune'];
    }

    /**
     * Les personas FR : prénoms INSEE avec leur sexe, patronymes data.gouv.
     *
     * Rend la même forme que le lecteur de CSV — `nom`, `prenom_f`, `prenom_m` — pour que le reste
     * de la classe ne connaisse qu'une structure. Les deux fichiers ont des formats distincts :
     * `prenoms.csv` donne `Prénom;sexe`, `noms.csv` un patronyme par ligne.
     *
     * Rend null si l'un des deux manque : une liste de prénoms sans patronymes, ou l'inverse, ne
     * donnerait pas des identités cohérentes, et un demi-jeu est plus trompeur qu'aucun — on
     * retombe alors sur les listes codées en dur, qui sont françaises.
     *
     * @return array<string, string[]>|null
     */
    private static function loadPersonas(string $directory): ?array
    {
        $first_names_file = $directory . '/prenoms.csv';
        $surnames_file    = $directory . '/noms.csv';

        if (!is_file($first_names_file) || !is_file($surnames_file)) {
            return null;
        }

        $lists = ['nom' => [], 'prenom_f' => [], 'prenom_m' => []];

        // ⚠ LE PATRONYME PORTE DÉSORMAIS SON POIDS EN DEUXIÈME COLONNE, et le lire comme un nom
        // entier donnerait « Martin;31781 » dans le dossier du patient. Le format a changé, donc ce
        // lecteur doit changer avec lui — et tolérer l'ancien, où la ligne EST le patronyme.
        self::$weights['nom'] = [];

        foreach (self::readLines($surnames_file) as $line) {
            $parts = explode(';', $line);
            $name  = trim($parts[0] ?? '');

            if ($name === '') {
                continue;
            }

            $lists['nom'][$name]        = true;
            self::$weights['nom'][$name] = max(1, (int)trim($parts[1] ?? '1'));
        }

        // LE POIDS EST LA TROISIÈME COLONNE, et il change tout : sans lui, le tirage est UNIFORME
        // sur 5 022 prénoms, si bien que « Randy » (1 840 naissances depuis 1900) sort aussi souvent
        // que « Marie » (2 232 235). Mille deux cents fois trop. Une cohorte ainsi tirée est pleine
        // de prénoms rares — souvent d'origine étrangère, que l'INSEE enregistre à juste titre
        // puisque des Français les portent — et ne ressemble pas à une population française.
        // Constaté à l'écran par eriam, deux fois, avant que je ne comprenne que la source n'était
        // pas en cause : c'est la façon d'y puiser qui l'était.
        self::$weights['prenom_f'] = [];
        self::$weights['prenom_m'] = [];

        foreach (self::readLines($first_names_file) as $line) {
            $parts = explode(';', $line);
            $name  = trim($parts[0] ?? '');
            $sex   = strtolower(trim($parts[1] ?? ''));
            // Poids absent (ancien fichier à deux colonnes) : 1 pour tous, ce qui redonne le
            // tirage uniforme. Un fichier non pondéré reste donc lisible, en moins bon.
            $weight = max(1, (int)trim($parts[2] ?? '1'));

            if ($name === '') {
                continue;
            }

            if ($sex === self::SEX_FEMALE) {
                $lists['prenom_f'][$name]    = true;
                self::$weights['prenom_f'][$name] = $weight;
            } elseif ($sex === self::SEX_MALE) {
                $lists['prenom_m'][$name]    = true;
                self::$weights['prenom_m'][$name] = $weight;
            }
        }

        foreach ($lists as $key => $values) {
            // Clés de tableau pour dédoublonner, puis valeurs : l'ordre du fichier est conservé, et
            // il fait partie de ce que la graine rejoue à l'identique.
            $lists[$key] = array_keys($values);
        }

        if ($lists['nom'] === [] || $lists['prenom_f'] === [] || $lists['prenom_m'] === []) {
            return null;
        }

        return $lists;
    }

    /**
     * Lignes non vides d'un fichier, commentaires `#` écartés.
     *
     * @return string[]
     */
    private static function readLines(string $path): array
    {
        $lines = [];

        foreach ((array)file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string)$line);

            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Listes du fichier d'identités, ou null s'il n'y en a pas.
     *
     * @return array<string, string[]>|null
     * @throws SeedException
     */
    private static function load(): ?array
    {
        if (self::$loaded !== null) {
            return self::$loaded === [] ? null : self::$loaded;
        }

        $root = dirname(__DIR__, 3);

        // ORDRE DÉLIBÉRÉ, ET IL N'A PLUS QUE TROIS ÉTAGES : une substitution EXPLICITE l'emporte
        // (tests, exploitant), sinon les personas FR du dépôt, et en dernier recours les listes
        // codées en dur de cette classe — qui sont françaises. Il y en avait un quatrième, la
        // lecture d'un `cli/resources/seed-identites.csv` trouvé par hasard : voir l'en-tête à
        // l'emplacement de l'ancienne constante FILE.
        if (self::$path === null) {
            $personas = self::loadPersonas($root . '/' . self::PERSONAS_DIR);

            if ($personas !== null) {
                return self::$loaded = $personas;
            }

            self::$loaded = [];

            return null;
        }

        $path = self::$path;

        if (!is_file($path)) {
            throw new SeedException(
                "Fichier d'identités imposé introuvable : {$path}. Une substitution explicite ne "
                . "retombe PAS en silence sur un autre jeu — l'opérateur a demandé celui-ci."
            );
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new SeedException("Fichier d'identités illisible : {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            fclose($handle);

            throw new SeedException("Fichier d'identités vide : {$path}");
        }

        $header  = array_map(static function ($name) {
            return strtolower(trim((string)$name));
        }, $header);
        $columns = [];

        foreach (self::COLUMNS as $column) {
            $index = array_search($column, $header, true);

            if ($index === false) {
                fclose($handle);

                throw new SeedException(
                    "Colonne '{$column}' absente de {$path} : les colonnes attendues sont "
                    . implode(', ', self::COLUMNS) . '.'
                );
            }

            $columns[$column] = (int)$index;
        }

        foreach (self::OPTIONAL_COLUMNS as $column) {
            $index = array_search($column, $header, true);

            if ($index !== false) {
                $columns[$column] = (int)$index;
            }
        }

        $lists = array_fill_keys(array_keys($columns), []);
        $seen  = array_fill_keys(array_keys($columns), []);

        while (($row = fgetcsv($handle)) !== false) {
            foreach ($columns as $column => $index) {
                $value = trim((string)($row[$index] ?? ''));

                // Valeurs DISTINCTES et dans l'ordre du fichier : un doublon ne doit pas doubler la
                // probabilité d'un nom, et l'ordre fait partie de ce que la graine rejoue.
                if ($value === '' || isset($seen[$column][$value])) {
                    continue;
                }

                $seen[$column][$value]  = true;
                $lists[$column][]       = $value;
            }
        }

        fclose($handle);

        foreach ($lists as $column => $values) {
            if ($values === []) {
                throw new SeedException("Colonne '{$column}' vide dans {$path}");
            }
        }

        return self::$loaded = $lists;
    }

    /**
     * @return string[]
     * @throws SeedException
     */
    /**
     * Poids des prénoms d'un sexe, ou tableau vide si la source n'en porte pas.
     *
     * RENDUS À PART DE LA LISTE pour que l'appelant puisse choisir : un tirage pondéré quand les
     * fréquences existent, uniforme sinon. Les mêler aurait obligé tous les appelants à connaître
     * les deux formes.
     *
     * @return array<string, int>
     * @throws SeedException
     */
    public static function firstNameWeights(string $sex): array
    {
        self::load();

        $key = $sex === self::SEX_FEMALE ? 'prenom_f' : 'prenom_m';

        return self::$weights[$key] ?? [];
    }

    public static function firstNames(string $sex): array
    {
        $file = self::load();

        switch ($sex) {
            case self::SEX_FEMALE:
                return $file === null ? self::FIRST_NAMES_FEMALE : $file['prenom_f'];

            case self::SEX_MALE:
                return $file === null ? self::FIRST_NAMES_MALE : $file['prenom_m'];

            default:
                throw new SeedException(
                    "Sexe inconnu '{$sex}' (attendu '" . self::SEX_MALE . "' ou '" . self::SEX_FEMALE . "')"
                );
        }
    }

    /**
     * Nombre de combinaisons prénom + nom disponibles pour un sexe.
     *
     * Sert à avertir l'opérateur : au-delà, les homonymies parfaites deviennent inévitables.
     * Ce n'est pas un défaut en soi (un hôpital a de vrais homonymes, et c'est même un cas
     * pédagogique utile), mais ça doit être un choix conscient et non une surprise.
     *
     * @throws SeedException
     */
    public static function combinations(string $sex): int
    {
        return count(self::firstNames($sex)) * count(self::surnames());
    }
}
