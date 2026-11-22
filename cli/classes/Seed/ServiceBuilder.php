<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

use Hospilibre\Hospi\CChambre;
use Hospilibre\Hospi\CLit;
use Hospilibre\Hospi\CService;

/**
 * Services, chambres et lits.
 *
 * La topologie est construite de haut en bas et les lits sont RATTACHÉS au service dans le
 * référentiel : c'est ce qui rend l'invariant 1 tenable (« tout lit d'affectation appartient à
 * une chambre du service de l'affectation »). Les anciens générateurs tiraient service et lit
 * indépendamment et violaient donc cet invariant par construction.
 *
 * Les lits sont répartis par SeedProfile::bedsPerService(), et non tirés au hasard : l'opérateur a
 * demandé un nombre précis de lits, il doit les obtenir. La répartition n'est PAS uniforme : les
 * unités d'accueil des urgences reçoivent ce que leur flux exige, le reste se partage à parts
 * égales — une part uniforme aurait donné à l'unité d'urgences trois fois les lits que son activité
 * occupe.
 */
final class ServiceBuilder
{
    /** @var SeedContext */
    private $context;

    public function __construct(SeedContext $context)
    {
        $this->context = $context;
    }

    /**
     * @throws SeedException
     */
    public function build(Referential $referential): Referential
    {
        $profile      = $this->context->profile();
        $catalogue    = $this->context->decor()->catalogue();
        $specialities = $catalogue->first($profile->services());

        $beds_per_service = $profile->bedsPerService($specialities);

        // Les chambres doubles sont réparties globalement puis consommées service par service :
        // cela évite qu'un petit service n'hérite que de chambres individuelles et qu'un autre
        // n'ait aucune chambre seule, ce qui rendrait `chambre_seule` inexploitable.
        $double_rooms_left = $profile->doubleRooms();

        foreach ($specialities as $index => $spec) {
            $service = $this->buildService($referential, $spec, $index);

            [$beds, $double_rooms_left] = $this->buildRoomsAndBeds(
                $service,
                $spec,
                $beds_per_service[$spec['slug']],
                $double_rooms_left
            );

            $referential->addService($spec['slug'], $service, $spec, $beds);
        }

        return $referential;
    }

    /**
     * @throws SeedException
     */
    private function buildService(Referential $referential, array $spec, int $index): CService
    {
        $service                    = new CService();
        $service->group_id          = $referential->group()->_id;
        $service->nom               = $spec['service'];
        $service->code              = sprintf('S%02d', $index + 1);
        $service->is_soins_continue = $spec['intensive'] ? '1' : '0';

        // Drapeaux du socle qui autorisent l'hébergement d'un séjour urgent. Sans eux,
        // `CAffectation::check()` refuse l'affectation : « Emergency stay cant be in non emergency
        // service ». C'est ce qui laissait 10 % des séjours sans lit.
        foreach ($this->context->decor()->catalogue()->serviceFlags($spec['slug']) as $flag) {
            $service->{$flag} = '1';
        }

        /** @var CService $service */
        $service = $this->context->write($service, 'service:' . $spec['slug']);

        return $service;
    }

    /**
     * @return array{0: CLit[], 1: int} les lits créés, et le solde de chambres doubles
     * @throws SeedException
     */
    private function buildRoomsAndBeds(
        CService $service,
        array $spec,
        int $bed_count,
        int $double_rooms_left
    ): array {
        $beds        = [];
        $room_number = 0;
        $created     = 0;

        while ($created < $bed_count) {
            $room_number++;
            $remaining = $bed_count - $created;

            // Une chambre double n'a de sens que s'il reste au moins deux lits à placer, sinon on
            // créerait un lit fantôme et l'établissement n'aurait pas la capacité demandée.
            $is_double = $double_rooms_left > 0 && $remaining >= 2;

            $room = $this->buildRoom($service, $spec, $room_number);

            $capacity = $is_double ? 2 : 1;
            if ($is_double) {
                $double_rooms_left--;
            }

            for ($position = 1; $position <= $capacity; $position++) {
                $beds[] = $this->buildBed($room, $spec, $room_number, $position, $capacity);
                $created++;
            }
        }

        return [$beds, $double_rooms_left];
    }

    /**
     * @throws SeedException
     */
    private function buildRoom(CService $service, array $spec, int $number): CChambre
    {
        $code = sprintf('%s-%03d', strtoupper($service->code), $number);

        $room             = new CChambre();
        $room->service_id = $service->_id;
        $room->nom        = (string)$number;
        $room->code       = $code;

        /** @var CChambre $room */
        $room = $this->context->write($room, 'chambre:' . $spec['slug'] . ':' . $number);

        return $room;
    }

    /**
     * @throws SeedException
     */
    private function buildBed(CChambre $room, array $spec, int $room_number, int $position, int $capacity): CLit
    {
        // Un lit de chambre individuelle n'a pas de suffixe : « 12 » plutôt que « 12a », qui est
        // ce qu'un soignant écrit. En chambre double, la lettre distingue les deux places.
        $name = $capacity === 1 ? (string)$room_number : $room_number . chr(96 + $position);

        $bed             = new CLit();
        $bed->chambre_id = $room->_id;
        $bed->nom        = $name;
        $bed->code       = $room->code . '-' . $position;

        /** @var CLit $bed */
        $bed = $this->context->write($bed, 'lit:' . $spec['slug'] . ':' . $name);

        return $bed;
    }
}
