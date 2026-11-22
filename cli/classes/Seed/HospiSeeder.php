<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Enchaîne les builders de l'anneau « noyau hospitalisation » et rend de quoi rendre compte.
 *
 * L'ordre est du code lisible, et non un effet de bord d'un tri : référentiel, puis patients,
 * puis séjours. Chaque étage dépend strictement du précédent, et c'est pourquoi il reste imposé ici
 * plutôt que laissé au décor — celui-ci choisit QUELS builders, pas dans quel ordre.
 */
final class HospiSeeder
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
    public function run(): SeedReport
    {
        $builders = $this->context->decor()->builders();

        $referential = $builders->referential($this->context)->build();

        $this->assertAnchoredServicesExist($referential);

        // Les patients sont tirés APRÈS le référentiel, et à partir de lui : la population dépend
        // de l'offre de soins — un établissement sans pédiatrie n'a pas de patients mineurs.
        $patient_builder = $builders->patients($this->context);
        $patients        = $patient_builder->build($referential);
        $this->context->writer()->flush();

        $sejour_builder = $builders->sejours($this->context, $referential);

        // Les ancrés sont déclarés AVANT le placement, et non traités après : leur donner un séjour
        // en supplément une fois la masse placée ferait un séjour de trop par ancré, hors de tout
        // dimensionnement. Ils prennent des séjours du lot, en priorité.
        $sejour_builder->anchor($patient_builder->anchoredPatients());

        $sejours = $sejour_builder->build($patients);

        return new SeedReport(
            $this->context,
            $referential,
            count($patients),
            $sejours,
            $sejour_builder->bedFallbacks(),
            $sejour_builder->unscheduledOperations(),
            $sejour_builder->occupiedBedsAt($this->context->now()),
            $sejour_builder->occupiedIcuBedsAt($this->context->now()),
            $sejour_builder->profileFallbacks(),
            $sejour_builder->occupiedEmergencyBedsAt($this->context->now()),
            $sejour_builder->operations(),
            $sejour_builder->coding(),
            $this->context->ipp(),
            $this->context->anchors(),
            $sejour_builder->unplacedAnchors(),
            $sejour_builder->anchorProfileOverrides(),
            $sejour_builder->anchorsWithoutMatchingUnit(),
            $sejour_builder->anaesthesia(),
            $sejour_builder->reportCounters(),
            $sejour_builder->incidents(),
            $sejour_builder->documentCounters(),
            $sejour_builder->vitalsCounters()
        );
    }

    /**
     * Un service imposé par le fichier d'ancres doit exister dans le décor.
     *
     * Vérifié ICI, entre le référentiel et les patients : c'est le premier instant où les services
     * sont connus, et le dernier avant que quoi que ce soit de massif ne soit écrit. Découvrir la
     * faute de frappe après vingt mille séjours obligerait à purger pour réessayer.
     *
     * @throws SeedException
     */
    private function assertAnchoredServicesExist(Referential $referential): void
    {
        $requested = $this->context->anchors()->requestedServices();

        if ($requested === []) {
            return;
        }

        $known   = $referential->serviceSlugs();
        $unknown = array_diff($requested, $known);

        if ($unknown === []) {
            return;
        }

        throw new SeedException(
            'Service(s) inconnu(s) dans ' . $this->context->anchors()->path() . ' : '
            . implode(', ', $unknown)
            . "\nServices ouverts par ce décor : " . implode(', ', $known)
            . "\nLaisser la colonne « service » vide confie le choix au seed, ce qui est"
            . ' préférable quand le scénario ne l\'impose pas.'
        );
    }
}
