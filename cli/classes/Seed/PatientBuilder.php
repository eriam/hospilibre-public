<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Patients\CPatient;
use Ox\Cli\Seed\Decor\GeographySource;
use Ox\Cli\Seed\Decor\IdentitySource;
use Ox\Core\CMbDT;

/**
 * Patients fictifs.
 *
 * Aucun tag : un patient est un objet de masse, et un tag n'a d'intérêt que pour ce qu'une
 * consigne d'exercice peut citer nommément. Leur traçabilité pour la purge passe par le chemin
 * d'écriture, pas par un tag. Ce qu'une consigne peut citer, c'est l'IPP — un identifiant du
 * métier, que tout système extérieur sait rapprocher, et non une étiquette interne au seed.
 *
 * LES ANCRÉS D'ABORD. Quand une liste d'identités imposées est fournie, ses patients sont créés
 * avant la masse et COMPTÉS DANS LE TOTAL : `--patients=1000` en produit mille, dont les ancrés.
 * Les ajouter par-dessus ferait mentir le dimensionnement, et un opérateur qui demande mille
 * dossiers pour une capacité donnée n'en veut pas mille vingt.
 *
 * Ils tirent leur adresse sur un FLUX DE HASARD À EUX. Sans cela, ajouter un ancré décalerait
 * tous les tirages suivants et changerait l'hôpital entier — la même graine ne rendrait plus le
 * même établissement selon qu'une liste d'ancres accompagne le semis ou non.
 *
 * `nom_jeune_fille` est notNull alors que `nom` ne l'est pas : dans ce modèle, le nom de
 * naissance est le nom pivot. Les deux sont renseignés, et diffèrent parfois pour les femmes —
 * c'est un cas que les écrans de recherche et de rapprochement d'identité doivent savoir montrer.
 */
final class PatientBuilder
{



    /** @var SeedContext */
    private $context;

    /** @var GeographySource|null */
    private $communes;

    /** @var array<string, string>|null Commune d'implantation de l'établissement */
    private $town;

    /** @var array<int, array{patient: CPatient, service: string, motif_sejour: string}> */
    private $anchored = [];

    public function __construct(SeedContext $context)
    {
        $this->context = $context;
    }

    /**
     * La population suit l'OFFRE DE SOINS de l'établissement construit : pas d'enfants sans
     * pédiatrie, et juste ce qu'il faut d'enfants quand elle existe.
     *
     * @return CPatient[]
     * @throws SeedException
     */
    public function build(Referential $referential): array
    {
        $count    = $this->context->profile()->patients();
        $patients = [];

        // Le territoire de l'établissement : ses patients en viennent, au prorata de la population
        // des communes. Un hôpital ne recrute pas dans la France entière.
        $this->communes = $this->context->decor()->geography();
        $this->town     = $referential->hospitalTown();

        $this->bindIpp($referential);

        // Part de mineurs calée sur la part de lits qui peut les recevoir : en tirer davantage
        // laisserait des enfants sans séjour possible, en tirer moins laisserait la pédiatrie vide.
        $minor_share = $referential->hostsChildren() ? $referential->paediatricBedShare() : 0;

        foreach ($this->context->anchors()->anchors() as $anchor) {
            $patients[] = $this->buildAnchored($anchor);
        }

        for ($i = count($patients); $i < $count; $i++) {
            $patients[] = $this->buildOne($minor_share);
        }

        return $patients;
    }

    /**
     * Les patients d'identité imposée, avec ce que le fichier a demandé pour leur séjour.
     *
     * Rendus à part des autres parce que le builder de séjours doit les traiter à part : un ancré
     * sans séjour est le seul dossier vide de l'établissement, et c'est justement celui qu'une
     * consigne d'exercice cite.
     *
     * @return array<int, array{patient: CPatient, service: string, motif_sejour: string}>
     */
    public function anchoredPatients(): array
    {
        return $this->anchored;
    }

    /**
     * Résout le tag d'IPP de l'établissement et réserve les numéros imposés.
     *
     * Réserver AVANT que la masse ne numérote : un ancré dont l'IPP tomberait dans la plage de la
     * masse serait sinon découvert sous la forme de deux patients partageant un identifiant.
     *
     * @throws SeedException
     */
    /**
     * Un patronyme, pondéré par sa fréquence quand la source en porte.
     *
     * EXTRAIT PARCE QU'IL EST TIRÉ DEUX FOIS : le nom de naissance, puis le nom d'usage quand la
     * patiente en a un. Les deux doivent suivre la même loi, et un appel dupliqué finit toujours
     * par diverger.
     *
     * @param array<string, int> $weights
     *
     * @throws SeedException
     */
    private function drawSurname(SeedRandom $random, IdentitySource $identities, array $weights): string
    {
        if ($weights === []) {
            return (string)$random->pick($identities->surnames());
        }

        return (string)$random->pickWeighted($weights);
    }

    private function bindIpp(Referential $referential): void
    {
        $ipp = $this->context->ipp();

        if ($ipp === null) {
            return;
        }

        $ipp->bind($referential->group());

        foreach ($this->context->anchors()->ipps() as $imposed) {
            $ipp->reserve($imposed);
        }
    }

    /**
     * Un patient dont l'identité vient du fichier d'ancres, et rien d'autre.
     *
     * Le nom de naissance est posé égal au nom d'usage : le fichier n'en donne qu'un, et en
     * inventer un second ferait apparaître un nom de jeune fille que personne n'a demandé sur un
     * dossier que le scénario cite nommément.
     *
     * @param array<string, string> $anchor
     *
     * @throws SeedException
     */
    private function buildAnchored(array $anchor): CPatient
    {
        $random = $this->context->stream('patients-ancres');

        $patient                  = new CPatient();
        $patient->nom             = mb_strtoupper($anchor['nom']);
        $patient->nom_jeune_fille = mb_strtoupper($anchor['nom']);
        $patient->prenom          = $anchor['prenom'];
        $patient->naissance       = $anchor['naissance'];
        $patient->sexe            = $anchor['sexe'];

        [$patient->cp, $patient->ville] = $this->address($random);

        $this->writePatient($patient, $anchor['ipp']);

        $this->anchored[] = [
            'patient'      => $patient,
            'service'      => $anchor['service'],
            'motif_sejour' => $anchor['motif_sejour'],
        ];

        return $patient;
    }

    /**
     * Écrit le patient, puis son IPP.
     *
     * DANS CET ORDRE, ET PAS L'INVERSE : un `CIdSante400` désigne sa cible par
     * (object_class, object_id), donc il lui faut un patient déjà écrit.
     *
     * `_generate_IPP` est coupé, et c'est indispensable. `CPatient::store()` appelle sinon
     * `generateIPP()`, qui exige un `CIncrementer` configuré pour le tag et lit l'établissement de
     * la SESSION (`CGroups::loadCurrent()`) — pas celui que le seed vient de construire. Sur une
     * instance où le tag d'IPP est renseigné mais l'incrémenteur absent, le store rendrait
     * « CIncrementer_undefined » et l'écriture du patient échouerait. Le seed numérote lui-même,
     * parce qu'il est le seul à pouvoir le faire de façon REPRODUCTIBLE : un compteur d'instance
     * dépend de ce que l'instance a déjà vu.
     *
     * @param string|null $imposed IPP imposé, ou null pour numéroter la masse
     *
     * @throws SeedException
     */
    private function writePatient(CPatient $patient, ?string $imposed = null): void
    {
        $patient->_generate_IPP = false;

        $this->context->write($patient);

        $ipp = $this->context->ipp();

        if ($ipp !== null) {
            $ipp->assign($patient, $imposed);
        }
    }

    /**
     * Code postal et commune de résidence, cohérents avec l'implantation de l'établissement.
     *
     * Les communes du dictionnaire restent en CAPITALES, comme dans le fichier administratif dont
     * elles sortent : c'est ce qu'affiche un dossier hospitalier, et une jolie casse ici serait moins
     * fidèle, pas plus.
     *
     * @return array{0: string, 1: string}
     * @throws SeedException
     */
    private function address(SeedRandom $random): array
    {
        if ($this->communes !== null && $this->town !== null) {
            $commune = $this->communes->pickPatientTown($random, $this->town);

            if ($commune !== null) {
                return [$commune['code_postal'], $commune['commune']];
            }
        }

        // Sans dictionnaire : commune inventée et code postal tiré, donc sans cohérence territoriale.
        return [sprintf('%05d', $random->int(1000, 95999)), $random->pick($this->context->decor()->identities()->towns())];
    }

    /**
     * @param int $minor_share Part de mineurs dans la population, en pourcentage
     *
     * @throws SeedException
     */
    private function buildOne(int $minor_share): CPatient
    {
        $random = $this->context->stream('patients');
        $mix         = $this->context->decor()->clinicalMix();
        $identities  = $this->context->decor()->identities();

        $female = $random->chanceInThousand($mix->femaleRate());
        $sex    = $female ? FictitiousNames::SEX_FEMALE : FictitiousNames::SEX_MALE;

        // PONDÉRÉ COMME LES PRÉNOMS, avec une amplitude moindre : « Martin » compte 31 781 porteurs
        // dans l'extraction SIRENE quand la médiane des 3 000 patronymes retenus en compte 648. Le
        // tirage uniforme donnait la même chance à l'un et à l'autre — moins visible que sur les
        // prénoms, faux de la même façon.
        $surname_weights = $identities->surnameWeights();

        $birth_name = $this->drawSurname($random, $identities, $surname_weights);

        $used_name = $birth_name;
        if ($female && $random->chanceInThousand($mix->marriedNameRate())) {
            $used_name = $this->drawSurname($random, $identities, $surname_weights);
        }

        $minor = $minor_share > 0 && $random->chanceInThousand($minor_share * 10);
        $age   = $minor
            ? $random->int($mix->childMinAge(), $mix->adultAge() - 1)
            : $random->int($mix->adultAge(), $mix->maxAge());
        $days  = $random->int(0, 364);
        $birth = CMbDT::date("-{$age} years", $this->context->today());
        $birth = CMbDT::date("-{$days} days", $birth);

        $patient                  = new CPatient();
        $patient->nom             = mb_strtoupper($used_name);
        $patient->nom_jeune_fille = mb_strtoupper($birth_name);
        // TIRAGE PONDÉRÉ PAR LA FRÉQUENCE RÉELLE DU PRÉNOM. Un tirage uniforme sur les 5 022
        // prénoms de l'INSEE donne à « Randy » (1 840 naissances depuis 1900) la même chance qu'à
        // « Marie » (2 232 235) : la cohorte se remplit alors de prénoms rares, et ne ressemble pas
        // à une population française. Le repli uniforme reste possible pour une source sans
        // fréquences.
        $weights = $identities->firstNameWeights($sex);

        $patient->prenom = $weights === []
            ? $random->pick($identities->firstNames($sex))
            : (string)$random->pickWeighted($weights);
        $patient->naissance       = $birth;
        $patient->sexe            = $sex;

        [$patient->cp, $patient->ville] = $this->address($random);

        $this->writePatient($patient);

        return $patient;
    }

}
