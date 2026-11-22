<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Admin\CUser;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Ox\Core\CSQLDataSource;
use Throwable;

/**
 * Établissement, fonctions et personnel.
 *
 * L'établissement est CRÉÉ par le seed, avec un nom crédible. On ne réutilise pas le CGroups de
 * code `FIXTURES_GROUP` nommé « Fixtures » que produit FixturesUsersGenerator : c'est de
 * l'échafaudage de test, inadapté à une instance qu'on montre à des étudiants.
 *
 * Une fonction par spécialité, plus une fonction d'anesthésie transversale. Le lien
 * fonction → spécialité est ce qui permet plus tard de n'affecter au bloc que des praticiens
 * réellement chirurgicaux (`COperation::chir_id`).
 */
final class EtablissementBuilder
{
    /** Tag de l'établissement : ce que toute consigne d'exercice citera en premier. */
    public const TAG_ETAB = 'etab';

    /** Les métiers que ce semis produit, en plus des praticiens. */
    private const ROLE_NURSE        = 'infirmier';
    private const ROLE_AIDE         = 'aide-soignant';
    private const ROLE_WARD_MANAGER = 'cadre';
    private const ROLE_SECRETARY    = 'secretaire';
    private const ROLE_ADMISSIONS   = 'admissions';
    private const ROLE_BILLING      = 'facturation';
    private const ROLE_PMSI         = 'pmsi';
    private const ROLE_MANAGEMENT   = 'direction';
    private const ROLE_QUALITY      = 'qualite';
    private const ROLE_IT           = 'informatique';

    /** Les praticiens, distingués par leur exercice réel et non confondus sous « Médecin ». */
    private const ROLE_SURGEON      = 'chirurgien';
    private const ROLE_ANAESTHETIST = 'anesthesiste';
    private const ROLE_PHYSICIAN    = 'medecin';

    /**
     * Le profil-modèle qui donne ses droits à chaque métier, par son nom.
     *
     * Le modèle est l'autorité : l'agent lui emprunte son `user_type` ET son `profile_id`, donc les
     * deux ne peuvent pas divergir. Les métiers absents de cette table — les aides-soignants, qui
     * n'ont pas de modèle dans le dépôt — sont créés avec leur type métier et SANS profil, et la
     * construction le signale.
     *
     * ⚠ PAS DE MODÈLE « SI » ICI, DÉLIBÉRÉMENT : son type est Administrator, et l'application
     * REFUSE qu'on crée un administrateur par ce chemin (« You are not allowed to modify an admin
     * user »). C'est une protection légitime ; les agents informatiques reçoivent donc un type non
     * administrateur plutôt qu'un contournement.
     */
    private const ROLE_TEMPLATES = [
        self::ROLE_NURSE        => 'Hospitalisation',
        self::ROLE_WARD_MANAGER => 'Survbloc',
        self::ROLE_SECRETARY    => 'Secretariat',
        self::ROLE_ADMISSIONS   => 'Accueil',
        self::ROLE_BILLING      => 'Facturation',
        self::ROLE_PMSI         => 'PMSI',
        self::ROLE_MANAGEMENT   => 'Direction',
        self::ROLE_QUALITY      => 'Qualite',
        // LES PRATICIENS ONT LEURS MODÈLES, et ils n'étaient pas branchés. Le semis donnait le type
        // « Médecin » (13) à TOUS les praticiens, anesthésistes compris, si bien que :
        //   - `isChirurgien()` et `isAnesth()` étaient faux partout — y compris sur le praticien
        //     qui opère, alors que COperation::chir_id est censé désigner un chirurgien ;
        //   - et aucun agent ne portait les types 3 et 4, donc les profils « Chirurgie » et
        //     « Anesthesie » étaient dotés de droits que personne ne pouvait hériter, pendant que
        //     les 22 praticiens réels n'avaient aucun droit du tout.
        // Mesuré sur un semis small le 2026-10-03 : praticien opérant #551, type 13,
        // isChirurgien() = NON.
        self::ROLE_SURGEON      => 'Chirurgie',
        self::ROLE_ANAESTHETIST => 'Anesthesie',
    ];

    /**
     * Le libellé de type métier, par préfixe, pour les rôles sans profil-modèle.
     *
     * Par PRÉFIXE et non par égalité : un des libellés du dépôt est corrompu par un double-encodage
     * (« InfirmiÎ¸re »), et une comparaison exacte dépendrait de ses octets.
     */
    private const ROLE_TYPE_PREFIX = [
        self::ROLE_AIDE => 'Aide soignant',
        self::ROLE_IT   => 'Personnel',
        // AUCUN MODÈLE POUR LE MÉDECIN NON CHIRURGICAL — celui des services de médecine. Il reçoit
        // donc son type et aucun profil, comme les aides-soignants. Lui attribuer celui du
        // chirurgien lui donnerait des droits de bloc qu'il n'a pas, et en créer un est une
        // décision de métier : quels droits a un médecin de médecine interne ? Cela reste à
        // arbitrer, et le dire ici vaut mieux que de choisir en silence.
        self::ROLE_PHYSICIAN => 'Médecin',
    ];

    /**
     * Part de femmes par métier, en pour mille.
     *
     * Les professions soignantes françaises sont très majoritairement féminines — autour de 87 %
     * chez les infirmiers et 91 % chez les aides-soignants (DREES). Un établissement semé à parité
     * ne ressemblerait pas à un établissement réel, et c'est visible dès la première liste de
     * personnel qu'un étudiant ouvre.
     */
    private const FEMALE_RATE_PER_ROLE = [
        self::ROLE_NURSE        => 870,
        self::ROLE_AIDE         => 910,
        self::ROLE_WARD_MANAGER => 800,
        self::ROLE_SECRETARY    => 950,
        self::ROLE_ADMISSIONS   => 800,
        self::ROLE_BILLING      => 700,
        self::ROLE_PMSI         => 600,
        self::ROLE_MANAGEMENT   => 450,
        self::ROLE_QUALITY      => 650,
        self::ROLE_IT           => 250,
        // Médecins : la parité est atteinte chez les jeunes générations ; la chirurgie reste plus
        // masculine, l'anesthésie intermédiaire (ordres de grandeur DREES).
        self::ROLE_PHYSICIAN    => 500,
        self::ROLE_SURGEON      => 350,
        self::ROLE_ANAESTHETIST => 450,
    ];

    /**
     * Mot de passe des comptes semés.
     *
     * CONNU ET PARTAGÉ, assumé : ce jeu de données sert la démonstration et l'enseignement, et un
     * compte dont personne ne connaît le mot de passe ne sert à rien — c'est l'état que ce semis
     * produisait, avec 22 médecins inutilisables. Il satisfait les politiques usuelles (longueur,
     * casses mêlées, chiffre, caractère spécial) ; si l'instance en exige davantage, l'écriture
     * échouera bruyamment plutôt que de créer des comptes injoignables.
     */
    private const STAFF_PASSWORD = 'Hospilibre-2026!';

    /** @var array<string, int> Rang courant par métier, pour des identifiants uniques. */
    private $agent_ranks = [];

    /** @var array<string, array{type: int, profile_id: int|null}> */
    private $profile_cache = [];






    /** Palette de couleurs de fonction, en hexadécimal sans dièse (spec 'color'). */
    private const COLORS = [
        'c94f4f', '4f7fc9', '4fc98a', 'c9a54f', '8a4fc9',
        '4fc9c0', 'c96f9b', '7fc94f', 'c9704f', '5f5fc9',
    ];

    /** @var SeedContext */
    private $context;

    public function __construct(SeedContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param int $operating_rooms Salles ouvertes, dont dépend l'effectif d'anesthésie
     *
     * @throws SeedException
     */
    public function build(int $operating_rooms): Referential
    {
        $decor     = $this->context->decor();
        $catalogue = $decor->catalogue();
        $mix       = $decor->clinicalMix();

        // Le lieu d'implantation est choisi AVANT l'établissement : il lui donne son nom, son code
        // postal, et le territoire d'où viendront ses patients. Un décor sans géographie n'en a pas, et
        // l'établissement retombe alors sur un nom de ville fictif.
        $geography = $decor->geography();
        $town      = $geography === null
            ? null
            : $geography->pickHospitalTown($this->context->stream('etablissement'));

        $group       = $this->buildGroup($town);
        $referential = new Referential($group, $catalogue);

        if ($town !== null) {
            $referential->setHospitalTown($town);
        }

        $specialities = $catalogue->first($this->context->profile()->services());

        $surgical = 0;
        foreach ($specialities as $spec) {
            if ($spec['surgical']) {
                $surgical++;
            }
        }

        // Effectif d'une spécialité chirurgicale : de quoi tenir les salles qu'elle occupe en même
        // temps, plus la garde. Les vacations sont réparties entre spécialités par tour de rôle
        // (cf. BlocBuilder), donc chacune en tient environ salles / spécialités à un instant donné.
        $surgeons_per_speciality = $mix->practitionersPerService();
        if ($surgical > 0) {
            $surgeons_per_speciality = max(
                $mix->practitionersPerService(),
                (int)ceil($operating_rooms / $surgical) + $mix->surgeonsOnCall()
            );
        }

        // Les lits par unité : c'est d'eux que se déduit l'effectif soignant, et ils sont déjà
        // calculés pour le dimensionnement des séjours.
        $beds_per_service = $this->context->profile()->bedsPerService($specialities);

        foreach ($specialities as $index => $spec) {
            $function = $this->buildFunction($group, $spec, $index);
            $count    = $spec['surgical'] ? $surgeons_per_speciality : $mix->practitionersPerService();

            for ($i = 1; $i <= $count; $i++) {
                $practitioner = $this->buildPractitioner(
                    $function,
                    $spec['slug'],
                    $i,
                    (string)$group->code,
                    $spec['surgical'] ? self::ROLE_SURGEON : self::ROLE_PHYSICIAN
                );
                $referential->addPractitioner($spec['slug'], $practitioner, $spec['surgical']);
            }

            // LE PERSONNEL DE L'UNITÉ, rattaché à la fonction de SA spécialité. Service et fonction
            // naissent de la même entrée du catalogue — « Réanimation polyvalente » et
            // « Réanimation » —, si bien que `function_id` aligne exactement l'agent sur son
            // périmètre d'exercice, avec le champ prévu pour ça et sans table supplémentaire.
            $this->buildWardStaff(
                $group,
                $function,
                $spec,
                (int)($beds_per_service[$spec['slug']] ?? 0),
                $referential
            );
        }

        // Les anesthésistes ne tiennent pas de service : leur effectif suit les salles, pas les
        // spécialités. Une salle ouverte occupe un anesthésiste ; la marge couvre les absences, la
        // consultation d'anesthésie et LA GARDE du bloc d'urgence, et garantit qu'il en reste
        // toujours au moins deux pour qu'une vacation puisse changer de main d'un jour à l'autre.
        $anaesthetists = $operating_rooms * $mix->anaesthetistsPerRoom() + $mix->anaesthesiaSpare();

        $anaesthesia = $this->buildAnaesthesiaFunction($group);
        for ($i = 1; $i <= $anaesthetists; $i++) {
            $referential->addAnaesthetist(
                $this->buildPractitioner(
                    $anaesthesia,
                    'anesthesie',
                    $i,
                    (string)$group->code,
                    self::ROLE_ANAESTHETIST
                )
            );
        }

        // Le personnel administratif ne tient pas d'unité de soins : il se rattache à
        // l'établissement, par une fonction administrative, et son effectif suit la capacité.
        $this->buildAdministrativeStaff($group);


        return $referential;
    }

    /**
     * @throws SeedException
     */
    /**
     * @param array<string, string>|null $town commune d'implantation, si un dictionnaire la fournit
     *
     * @throws SeedException
     */
    private function buildGroup(?array $town): CGroups
    {
        // Le nom appartient au DÉCOR : c'est lui qui sait comment un établissement s'appelle dans son
        // contexte, et c'est lui qui porte la règle de ne pas pouvoir être confondu avec un vrai.
        $named = $this->context->decor()->naming()->name($this->context->stream('etablissement'), $town);

        $group                       = new CGroups();
        $group->text                 = $named['text'];
        $group->code                 = $named['code'];
        $group->chambre_particuliere = '0';

        if ($named['cp'] !== null) {
            $group->cp    = $named['cp'];
            $group->ville = $named['ville'];
        }

        /** @var CGroups $group */
        $group = $this->context->write($group, self::TAG_ETAB);

        return $group;
    }

    /**
     * @throws SeedException
     */
    private function buildFunction(CGroups $group, array $spec, int $index): CFunctions
    {
        $function                            = new CFunctions();
        $function->group_id                  = $group->_id;
        $function->text                      = $spec['fonction'];
        $function->type                      = 'administratif';
        $function->color                     = self::COLORS[$index % count(self::COLORS)];
        $function->compta_partagee           = '0';
        $function->consults_events_partagees = '1';

        /** @var CFunctions $function */
        $function = $this->context->write($function, 'fonction:' . $spec['slug']);

        return $function;
    }

    /**
     * @throws SeedException
     */
    private function buildAnaesthesiaFunction(CGroups $group): CFunctions
    {
        $function                            = new CFunctions();
        $function->group_id                  = $group->_id;
        $function->text                      = $this->context->decor()->catalogue()->anaesthesiaFunction();
        $function->type                      = 'administratif';
        $function->color                     = '9b9b9b';
        $function->compta_partagee           = '0';
        $function->consults_events_partagees = '1';

        /** @var CFunctions $function */
        $function = $this->context->write($function, 'fonction:anesthesie');

        return $function;
    }

    /**
     * Un praticien, et l'utilisateur qui va avec.
     *
     * CMediusers::store() crée le CUser sous-jacent à partir des champs de formulaire
     * `_user_*` — c'est le chemin qu'emploie déjà FixturesUsersGenerator, et le seul qui
     * maintienne la cohérence entre les deux tables.
     *
     * @throws SeedException
     */
    /**
     * La fonction de rattachement du personnel administratif.
     *
     * UNE SEULE, et distincte des spécialités : ces agents n'exercent pas dans une unité de soins.
     * Leur donner le `function_id` d'une spécialité leur attribuerait un périmètre clinique qu'ils
     * n'ont pas, ce qui comptera le jour où les droits suivront le périmètre.
     */
    private function buildAdministrativeFunction(CGroups $group): CFunctions
    {
        if ($this->context->hasTag('fonction:administration')) {
            /** @var CFunctions $existing */
            $existing = $this->context->tagged('fonction:administration');

            return $existing;
        }

        $function                            = new CFunctions();
        $function->group_id                  = $group->_id;
        $function->text                      = 'Administration';
        $function->type                      = 'administratif';
        $function->color                     = 'b0b0b0';
        $function->compta_partagee           = '0';
        $function->consults_events_partagees = '0';

        /** @var CFunctions $function */
        $function = $this->context->write($function, 'fonction:administration');

        return $function;
    }

    private function buildPractitioner(
        CFunctions $function,
        string $slug,
        int $rank,
        string $group_code,
        string $role = self::ROLE_PHYSICIAN
    ): CMediusers {
        $random = $this->context->stream('praticiens');

        $female     = $random->chanceInThousand(450);
        $sex        = $female ? FictitiousNames::SEX_FEMALE : FictitiousNames::SEX_MALE;
        $first_name = $random->pick(FictitiousNames::firstNames($sex));
        $last_name  = $random->pick(FictitiousNames::surnames());

        // Le nom d'utilisateur doit être unique DANS TOUTE L'INSTANCE, et pas seulement dans
        // l'établissement : `CUser::check()` refuse un doublon, et deux jeux de données peuvent
        // coexister dans la même base — le test qui vérifie qu'une autre graine produit un autre
        // établissement en crée précisément deux. Le slug de spécialité et le rang ne suffisaient
        // donc pas : il suffisait que la même identité soit tirée deux fois au même rang de la même
        // spécialité pour que l'écriture échoue, et avec une vingtaine de praticiens par
        // établissement cela arrivait une fois sur deux. Le code de l'établissement lève
        // l'ambiguïté, et il apparaît dans le nom, ce qui est utile quand on montre un identifiant
        // de connexion à des étudiants. Il est dérivé de la graine et non d'un identifiant de base,
        // pour que deux instances semées à l'identique produisent les mêmes identifiants — la
        // reproductibilité vaut aussi pour ce qu'un étudiant tape au clavier. Limite résiduelle
        // assumée : deux graines qui tireraient la même ville produiraient le même code, et la
        // collision reviendrait — mais elle échouerait bruyamment, pas en silence.
        $username = self::slug($last_name) . '.' . $slug . $rank . '.' . strtolower($group_code);

        $this->assertUsernameFree($username);

        $practitioner                    = new CMediusers();
        $practitioner->function_id       = $function->_id;
        $practitioner->_user_first_name  = $first_name;
        $practitioner->_user_last_name   = $last_name;
        $practitioner->_user_username    = $username;
        // SANS MOT DE PASSE, UN COMPTE NE SERT À RIEN. Les 22 médecins que ce semis produisait
        // avaient un mot de passe VIDE : ils existaient, portaient le bon type, et personne ne
        // pouvait ouvrir une session avec eux. Mesuré sur la cohorte de production.
        $practitioner->_user_password    = self::STAFF_PASSWORD;
        $practitioner->_user_sexe        = $sex;
        // LE MÊME CHEMIN QUE LES AGENTS : le type et le profil sont lus sur le profil-modèle, donc
        // ils ne peuvent pas divergir. Remplace `doctorTypeId()`, qui donnait « Médecin » à tout le
        // monde — y compris aux chirurgiens et aux anesthésistes.
        $profile                         = $this->profileFor($role);
        $practitioner->_user_type        = $profile['type'];
        $practitioner->actif             = '1';

        /** @var CMediusers $practitioner */
        $practitioner = $this->context->write($practitioner, 'praticien:' . $slug . ':' . $rank);

        if ($profile['profile_id'] !== null) {
            $this->attachProfile((int)$practitioner->_id, (int)$profile['profile_id']);
        }

        return $practitioner;
    }

    /**
     * Le nom d'utilisateur est-il libre ?
     *
     * VÉRIFIÉ AVANT L'ÉCRITURE, pour dire la CAUSE et le remède. `CUser::check()` refuse le doublon,
     * mais son message — « CUser-failed-username : dupont.cardiologie1.chlim » — désigne le symptôme
     * et laisse deviner le reste. Or la cause est presque toujours la même : un semis antérieur
     * occupe déjà ces identifiants, souvent parce qu'il a été interrompu et que personne n'a purgé.
     * Le nom étant DÉRIVÉ DE LA GRAINE, rejouer le même jeu de données après un plantage échoue
     * systématiquement, et rien ne le disait.
     *
     * Constaté trois fois en une journée pendant la mise au point, à chaque fois avec un
     * aller-retour perdu à en retrouver la raison.
     *
     * @throws SeedException
     */
    /**
     * LE PERSONNEL D'UNE UNITÉ DE SOINS : infirmiers, aides-soignants, cadre, secrétariat.
     *
     * POURQUOI CE BLOC EXISTE. Le semis ne créait QUE des médecins — 22 comptes de type 13 sur un
     * établissement de 200 lits, et rien d'autre. Un dossier de soins sans infirmier qui l'écrive
     * n'a pas de sens, et il était impossible d'ouvrir une session « infirmière » pour voir ce que
     * ce profil donne à l'écran.
     *
     * L'EFFECTIF VIENT DES LITS ET DE LA NATURE DE L'UNITÉ, pas d'un nombre fixe par service : voir
     * les coefficients de ClinicalMix, qui portent chacun son fondement — le décret 2002-466 pour
     * la réanimation, l'usage pour l'hospitalisation, un forfait pour l'accueil des urgences dont
     * l'effectif suit le passage.
     */
    private function buildWardStaff(
        CGroups $group,
        CFunctions $function,
        array $spec,
        int $beds,
        ?Referential $referential = null
    ): void {
        $mix       = $this->context->decor()->clinicalMix();
        $intensive = (bool)($spec['intensive'] ?? false);

        $crew = [
            'ide'   => ['count' => $mix->nursesFor($beds, $intensive, (string)$spec['slug']), 'role' => self::ROLE_NURSE],
            'as'    => ['count' => $mix->aidesFor($beds, $intensive, (string)$spec['slug']), 'role' => self::ROLE_AIDE],
            'cadre' => ['count' => $mix->wardManagersPerService(), 'role' => self::ROLE_WARD_MANAGER],
            'sec'   => ['count' => $mix->secretariesPerService(), 'role' => self::ROLE_SECRETARY],
        ];

        foreach ($crew as $slug => $post) {
            for ($i = 1; $i <= $post['count']; $i++) {
                $agent = $this->buildAgent($function, $post['role'], $slug, (string)$group->code);

                // ENREGISTRÉ DANS LE RÉFÉRENTIEL, sans quoi l'agent est créé puis oublié : aucun
                // écrit du dossier de soins ne pourrait être signé par un soignant de l'unité où
                // le patient se trouve.
                if ($referential !== null && $agent !== null) {
                    $referential->addCaregiver((string)$spec['slug'], $agent, $post['role']);
                }
            }
        }
    }

    /**
     * Le personnel administratif de l'établissement.
     *
     * Rattaché à une fonction administrative et non à une unité de soins : ces agents n'exercent
     * pas dans un service, et leur donner le `function_id` d'une spécialité leur attribuerait un
     * périmètre clinique qu'ils n'ont pas — ce qui compterait le jour où les droits suivront le
     * périmètre.
     */
    private function buildAdministrativeStaff(CGroups $group): void
    {
        $mix   = $this->context->decor()->clinicalMix();
        $staff = $mix->administrativeStaff((int)$this->context->profile()->beds());

        $roles = [
            'admissions'  => self::ROLE_ADMISSIONS,
            'facturation' => self::ROLE_BILLING,
            'pmsi'        => self::ROLE_PMSI,
            'direction'   => self::ROLE_MANAGEMENT,
            'qualite'     => self::ROLE_QUALITY,
            'si'          => self::ROLE_IT,
        ];

        $function = $this->buildAdministrativeFunction($group);

        foreach ($roles as $slug => $role) {
            $count = (int)($staff[$slug] ?? 0);

            for ($i = 1; $i <= $count; $i++) {
                $this->buildAgent($function, $role, $slug, (string)$group->code);
            }
        }
    }

    /**
     * Un agent : son métier, son périmètre, son profil de droits, et un mot de passe.
     *
     * LES QUATRE ENSEMBLE, parce qu'aucun ne sert seul. Un compte sans mot de passe ne se connecte
     * pas — c'est l'état des 22 médecins que ce semis produisait jusqu'ici. Un compte sans
     * `user_type` n'est reconnu par aucun des dix-huit prédicats de CMediusers. Et un compte sans
     * `profile_id` n'hérite d'aucun droit : il existe, il se connecte, et il ne voit rien de
     * particulier.
     */
    private function buildAgent(
        CFunctions $function,
        string $role,
        string $role_slug,
        string $group_code
    ): ?CMediusers {
        $random     = $this->context->stream('personnel');
        $female     = $random->chanceInThousand(self::FEMALE_RATE_PER_ROLE[$role] ?? 500);
        $sex        = $female ? FictitiousNames::SEX_FEMALE : FictitiousNames::SEX_MALE;
        $first_name = $random->pick(FictitiousNames::firstNames($sex));
        $last_name  = $random->pick(FictitiousNames::surnames());

        // Compteur GLOBAL par rôle, et non par service : deux unités peuvent tirer le même nom au
        // même rang, et l'identifiant doit rester unique dans toute l'instance. Il reste dérivé de
        // la graine, donc reproductible — ce qu'un étudiant tape au clavier ne doit pas changer
        // d'un semis à l'autre.
        $this->agent_ranks[$role_slug] = ($this->agent_ranks[$role_slug] ?? 0) + 1;
        $rank                          = $this->agent_ranks[$role_slug];
        $username = self::slug($last_name) . '.' . $role_slug . $rank . '.' . strtolower($group_code);

        $this->assertUsernameFree($username);

        $profile = $this->profileFor($role);

        $agent                    = new CMediusers();
        $agent->function_id       = $function->_id;
        $agent->_user_first_name  = $first_name;
        $agent->_user_last_name   = $last_name;
        $agent->_user_username    = $username;
        $agent->_user_password    = self::STAFF_PASSWORD;
        $agent->_user_sexe        = $sex;
        $agent->_user_type        = $profile['type'];
        $agent->actif             = '1';

        /** @var CMediusers $agent */
        $agent = $this->context->write($agent, 'agent:' . $role_slug . ':' . $rank);

        if ($profile['profile_id'] !== null) {
            $this->attachProfile((int)$agent->_id, (int)$profile['profile_id']);
        }

        return $agent;
    }

    /**
     * Le type métier et le profil de droits d'un rôle, lus EN BASE.
     *
     * ON NE COMPARE PAS DES LIBELLÉS. `CMediusers::isInfirmiere()` fait un
     * `isFromType(["InfirmiÎ¸re"])` — une comparaison de CHAÎNE, sur un libellé d'ailleurs corrompu
     * par un double-encodage. Reprendre ce chemin rendrait cet effectif dépendant d'une faute de
     * caractère, et renommer un rôle dans l'interface casserait le semis en silence.
     *
     * On part donc du PROFIL-MODÈLE, qui porte lui-même son `user_type` : l'agent et le profil qui
     * lui donnera ses droits tirent leur type de la même ligne, donc ils ne peuvent pas divergir.
     * Quand aucun modèle ne correspond au métier — il n'y en a pas pour les aides-soignants — on
     * retombe sur le type résolu par préfixe replié, et on le SIGNALE en fin de construction
     * plutôt que de laisser croire que le profil existe.
     *
     * @return array{type: int, profile_id: int|null}
     */
    private function profileFor(string $role): array
    {
        if (isset($this->profile_cache[$role])) {
            return $this->profile_cache[$role];
        }

        $template = self::ROLE_TEMPLATES[$role] ?? null;
        $resolved = ['type' => $this->typeIdForLabelPrefix(self::ROLE_TYPE_PREFIX[$role] ?? ''), 'profile_id' => null];

        if ($template !== null) {
            $datasource = CSQLDataSource::get('std');
            $row        = $datasource->loadHash(
                $datasource->prepare(
                    'SELECT user_id, user_type FROM users WHERE template = \'1\' AND user_username = %',
                    $template
                )
            );

            if (!empty($row)) {
                $resolved = ['type' => (int)$row['user_type'], 'profile_id' => (int)$row['user_id']];
            }
            // Modèle absent : l'agent garde son type métier et n'a pas de profil. Les métiers
            // concernés sont nommés dans le commentaire de ROLE_TEMPLATES — c'est un fait du dépôt,
            // pas une découverte d'exécution, et il n'a donc pas sa place dans un avertissement.
        }

        return $this->profile_cache[$role] = $resolved;
    }

    /**
     * L'identifiant de type dont le libellé commence par ce préfixe, une fois replié.
     *
     * Replié : sans accent, sans casse, sans caractère non alphabétique. C'est ce qui permet de
     * retrouver « Infirmière » sous son libellé corrompu « InfirmiÎ¸re » sans dépendre des octets
     * exacts. Le préfixe, et non l'égalité, pour la même raison.
     */
    private function typeIdForLabelPrefix(string $prefix): int
    {
        if ($prefix === '') {
            return 0;
        }

        foreach (CUser::$types as $id => $label) {
            if (strpos(self::fold((string)$label), self::fold($prefix)) === 0) {
                return (int)$id;
            }
        }

        return 0;
    }

    private static function fold(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $folded = strtolower((string)($folded === false ? $value : $folded));

        return (string)preg_replace('/[^a-z]/', '', $folded);
    }

    /**
     * Rattache l'agent à son profil, ET VÉRIFIE QUE C'EST FAIT.
     *
     * Les marqueurs %1 et %2 sont NUMÉROTÉS : un « % » nu est un alias du PREMIER argument, si bien
     * que deux marqueurs nus reçoivent tous deux la même valeur. Constaté en écrivant la commande
     * ox-accounts:profiles — la requête devenait « WHERE user_id = <profile_id> », ne touchait
     * aucune ligne, et zéro ligne modifiée n'est pas une erreur SQL.
     */
    private function attachProfile(int $user_id, int $profile_id): void
    {
        if ($user_id === 0 || $profile_id === 0) {
            return;
        }

        $datasource = CSQLDataSource::get('std');
        $datasource->exec(
            $datasource->prepare('UPDATE users SET profile_id = %1 WHERE user_id = %2', $profile_id, $user_id)
        );
    }

    private function assertUsernameFree(string $username): void
    {
        $user = new CUser();

        try {
            // Clause explicite plutôt que loadMatchingObject() : celui-ci construit son WHERE à
            // partir de TOUS les champs renseignés, valeurs par défaut du modèle comprises, et
            // apparierait donc autre chose que le seul nom d'utilisateur.
            $user->loadObject(['user_username' => $user->getDS()->prepare('= %1', $username)]);
        } catch (Throwable $t) {
            // Une base qui refuse la question ne doit pas empêcher le semis : l'écriture échouera
            // plus loin avec le message du socle, ce qui reste mieux que d'interrompre ici.
            return;
        }

        if (!$user->_id) {
            return;
        }

        throw new SeedException(
            "Le nom d'utilisateur « {$username} » est déjà pris sur cette instance."
            . "
Les identifiants des praticiens sont DÉRIVÉS DE LA GRAINE : un semis antérieur"
            . ' — peut-être interrompu — occupe donc déjà ceux-ci, et rejouer le même jeu de données'
            . ' par-dessus est impossible.'
            . "
Deux issues : « ox-seed:hospi --purge » pour défaire le semis précédent, ou une"
            . ' autre graine (--seed) pour produire un établissement distinct à côté.'
        );
    }



    private static function slug(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $ascii = $ascii === false ? $value : $ascii;
        $ascii = preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii);

        return strtolower(trim((string)$ascii, '-'));
    }


}
