<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Bloc\CPlageOp;
use Ox\Cli\Seed\Decor\SpecialityCatalogue;
use Hospilibre\Bloc\CSalle;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Hospi\CLit;
use Hospilibre\Hospi\CService;
use Hospilibre\Mediusers\CMediusers;

/**
 * Le référentiel construit, tel que les builders de séjours vont le consommer.
 *
 * Il retient les LIENS entre objets, et pas seulement les objets : quels lits appartiennent à
 * quel service, quels praticiens peuvent opérer. Sans cela, le builder de séjours devrait les
 * redécouvrir par des requêtes, et surtout rien ne l'empêcherait de placer un patient dans un
 * lit qui n'est pas du bon service — c'est exactement l'erreur que commettaient les anciens
 * générateurs, qui tiraient service et lit indépendamment.
 */
final class Referential
{
    /** @var CGroups */
    private $group;

    /** @var SpecialityCatalogue */
    private $catalogue;

    /** @var array<string, array{service: CService, spec: array, beds: CLit[]}> par slug de spécialité */
    private $services = [];

    /** @var array<string, CMediusers[]> praticiens par slug de spécialité */
    /** @var array<string, array<string, CMediusers[]>> Soignants par unité puis par métier. */
    private $caregivers = [];

    private $practitioners = [];

    /** @var CMediusers[] praticiens de fonction chirurgicale, tous services confondus */
    private $surgeons = [];

    /**
     * @var array<int, string> spécialité de chaque praticien, par identifiant.
     *
     * Sert à retrouver la spécialité qui tient une plage opératoire : `CPlageOp` ne porte pas de
     * spécialité, c'est son chirurgien qui la détermine.
     */
    private $speciality_of = [];

    /** @var CMediusers[] anesthésistes */
    private $anaesthetists = [];

    /** @var CSalle[] */
    private $rooms = [];

    /** @var CPlageOp[] */
    private $slots = [];

    /**
     * @var array<string, string>|null Commune d'implantation de l'établissement.
     *
     * Le territoire de l'hôpital : c'est de là que viennent ses patients. Sans cette information, ils
     * venaient de communes tirées uniformément dans la France entière — ou, avant le dictionnaire, de
     * quinze communes inventées.
     */
    private $hospital_town;

    /** @var array<int, true> salles du bloc d'urgence, par identifiant */
    private $emergency_rooms = [];

    /** @var array<int, true> plages du bloc d'urgence, par identifiant */
    private $emergency_slots = [];

    /**
     * @param SpecialityCatalogue $catalogue le catalogue du décor : c'est lui qui sait quelle unité
     *                                       accueille les urgences, laquelle reçoit des mineurs, et
     *                                       quels patients chacune admet
     */
    public function __construct(CGroups $group, SpecialityCatalogue $catalogue)
    {
        $this->group     = $group;
        $this->catalogue = $catalogue;
    }

    public function group(): CGroups
    {
        return $this->group;
    }

    /**
     * @param CLit[] $beds
     */
    public function addService(string $slug, CService $service, array $spec, array $beds): void
    {
        $this->services[$slug] = ['service' => $service, 'spec' => $spec, 'beds' => $beds];
    }

    /**
     * @return array<string, array{service: CService, spec: array, beds: CLit[]}>
     */
    public function services(): array
    {
        return $this->services;
    }

    /**
     * @return string[]
     */
    public function serviceSlugs(): array
    {
        return array_keys($this->services);
    }

    /**
     * @throws SeedException
     */
    public function service(string $slug): CService
    {
        if (!isset($this->services[$slug])) {
            throw new SeedException("Service inconnu du référentiel : '{$slug}'");
        }

        return $this->services[$slug]['service'];
    }

    /**
     * Lits du service, dans leur ordre de création.
     *
     * @return CLit[]
     * @throws SeedException
     */
    public function beds(string $slug): array
    {
        if (!isset($this->services[$slug])) {
            throw new SeedException("Service inconnu du référentiel : '{$slug}'");
        }

        return $this->services[$slug]['beds'];
    }

    /** Un service de soins continus se comporte différemment dans l'application. */
    public function isIntensive(string $slug): bool
    {
        return (bool)($this->services[$slug]['spec']['intensive'] ?? false);
    }

    public function isSurgical(string $slug): bool
    {
        return (bool)($this->services[$slug]['spec']['surgical'] ?? false);
    }

    /** L'unité peut-elle héberger un séjour urgent ? */
    public function isEmergency(string $slug): bool
    {
        return $this->catalogue->isEmergency($slug);
    }

    /** Le service accueille-t-il des mineurs ? */
    public function isPaediatric(string $slug): bool
    {
        return $this->catalogue->isPaediatric($slug);
    }

    /**
     * Patients que le service admet : tranche d'âge, et sexe quand il est imposé.
     *
     * @return array{min_age: int, max_age: int, sex: string|null}
     */
    public function patientProfile(string $slug): array
    {
        return $this->catalogue->patientProfile($slug);
    }

    /**
     * L'établissement accueille-t-il des enfants ?
     *
     * De cette réponse dépend la population de patients : sans service de pédiatrie, un mineur
     * n'a rien à faire dans le fichier — il serait hospitalisé en gériatrie ou en cardiologie.
     */
    public function hostsChildren(): bool
    {
        foreach (array_keys($this->services) as $slug) {
            if ($this->isPaediatric($slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Part des lits ouverte aux mineurs, en pourcentage.
     *
     * Sert à dimensionner la part de mineurs dans la population : en tirer davantage laisserait
     * des enfants sans séjour possible, en tirer moins laisserait la pédiatrie vide.
     */
    public function paediatricBedShare(): int
    {
        $total = $this->bedCount();

        if ($total === 0) {
            return 0;
        }

        $paediatric = 0;
        foreach ($this->services as $slug => $entry) {
            if ($this->isPaediatric($slug)) {
                $paediatric += count($entry['beds']);
            }
        }

        return (int)round(100 * $paediatric / $total);
    }

    public function addPractitioner(string $slug, CMediusers $practitioner, bool $surgeon): void
    {
        $this->practitioners[$slug][] = $practitioner;
        $this->speciality_of[(int)$practitioner->_id] = $slug;

        if ($surgeon) {
            $this->surgeons[] = $practitioner;
        }
    }

    /**
     * Enregistre un soignant de l'unité : infirmier, aide-soignant, cadre, secrétaire.
     *
     * PENDANT DE addPractitioner(), et il manquait. Le personnel d'unité était créé puis oublié,
     * si bien qu'aucun écrit de dossier de soins ne pouvait être SIGNÉ par un soignant du service
     * où le patient se trouve. Or c'est précisément ce qui rend un dossier crédible — et ce qui
     * rendra vérifiable le périmètre par unité, puisqu'il faudra constater qu'une infirmière de
     * réanimation ne voit pas les transmissions de cardiologie.
     */
    public function addCaregiver(string $slug, CMediusers $caregiver, string $role): void
    {
        $this->caregivers[$slug][$role][] = $caregiver;
    }

    /**
     * Les soignants d'une unité, pour un métier donné ou tous.
     *
     * REND UN TABLEAU VIDE plutôt que de lever, contrairement à practitioners() : une unité sans
     * praticien est une incohérence du référentiel, mais une unité sans aide-soignant est
     * possible — le plancher d'effectif ne garantit que l'infirmier. L'appelant décide quoi faire
     * d'une liste vide ; lui imposer une exception l'obligerait à l'attraper partout.
     *
     * @return CMediusers[]
     */
    public function caregivers(string $slug, ?string $role = null): array
    {
        if ($role !== null) {
            return $this->caregivers[$slug][$role] ?? [];
        }

        $all = [];

        foreach ($this->caregivers[$slug] ?? [] as $list) {
            $all = array_merge($all, $list);
        }

        return $all;
    }

    /** Spécialité d'un praticien, ou null s'il n'est pas du référentiel (un anesthésiste). */
    public function specialityOf(int $practitioner_id): ?string
    {
        return $this->speciality_of[$practitioner_id] ?? null;
    }

    /**
     * Spécialités chirurgicales, dans l'ordre du catalogue.
     *
     * @return string[]
     */
    public function surgicalSpecialities(): array
    {
        $slugs = [];

        foreach (array_keys($this->services) as $slug) {
            if ($this->isSurgical($slug)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    public function addAnaesthetist(CMediusers $practitioner): void
    {
        $this->anaesthetists[] = $practitioner;
    }

    /**
     * @return CMediusers[]
     * @throws SeedException
     */
    public function practitioners(string $slug): array
    {
        if (!isset($this->practitioners[$slug]) || $this->practitioners[$slug] === []) {
            throw new SeedException("Aucun praticien pour le service '{$slug}'");
        }

        return $this->practitioners[$slug];
    }

    /**
     * @return CMediusers[]
     */
    public function surgeons(): array
    {
        return $this->surgeons;
    }

    /**
     * @return CMediusers[]
     */
    public function anaesthetists(): array
    {
        return $this->anaesthetists;
    }

    public function addRoom(CSalle $room, bool $emergency = false): void
    {
        $this->rooms[] = $room;

        if ($emergency) {
            $this->emergency_rooms[(int)$room->_id] = true;
        }
    }

    /**
     * @param array<string, string> $town
     */
    public function setHospitalTown(array $town): void
    {
        $this->hospital_town = $town;
    }

    /**
     * @return array<string, string>|null
     */
    public function hospitalTown(): ?array
    {
        return $this->hospital_town;
    }

    /** La salle est-elle celle du bloc d'urgence ? */
    public function isEmergencyRoom(int $salle_id): bool
    {
        return isset($this->emergency_rooms[$salle_id]);
    }

    /**
     * @return CSalle[]
     */
    public function rooms(): array
    {
        return $this->rooms;
    }

    public function addSlot(CPlageOp $slot, bool $emergency = false): void
    {
        $this->slots[] = $slot;

        if ($emergency) {
            $this->emergency_slots[(int)$slot->_id] = true;
        }
    }

    /**
     * La plage appartient-elle au bloc d'urgence ?
     *
     * Les vacations du bloc programmé appartiennent chacune à une spécialité ; celles du bloc
     * d'urgence appartiennent à l'équipe de garde et forment une réserve à part, que toute
     * intervention en urgence peut utiliser quelle que soit la spécialité qui l'opère.
     */
    public function isEmergencySlot(int $plage_id): bool
    {
        return isset($this->emergency_slots[$plage_id]);
    }

    /**
     * Unités capables d'héberger un séjour urgent.
     *
     * @return string[]
     */
    public function emergencySlugs(): array
    {
        $slugs = [];

        foreach (array_keys($this->services) as $slug) {
            if ($this->catalogue->isEmergency($slug)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @return CPlageOp[]
     */
    public function slots(): array
    {
        return $this->slots;
    }

    /** Nombre total de lits, toutes unités confondues. */
    public function bedCount(): int
    {
        $total = 0;
        foreach ($this->services as $entry) {
            $total += count($entry['beds']);
        }

        return $total;
    }

    /**
     * Lits des services d'hospitalisation COMPLÈTE : hors soins continus et hors accueil des
     * urgences.
     *
     * C'est sur ce périmètre que se mesure la bande d'occupation visée. Les lits de réanimation, de
     * surveillance continue et les boxes d'urgence suivent chacun une logique d'occupation
     * différente et fausseraient la mesure.
     *
     * @return CLit[]
     */
    public function wardBeds(): array
    {
        $beds = [];
        foreach ($this->services as $slug => $entry) {
            if ($this->isIntensive($slug) || $this->isEmergency($slug)) {
                continue;
            }

            foreach ($entry['beds'] as $bed) {
                $beds[] = $bed;
            }
        }

        return $beds;
    }
}
