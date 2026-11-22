<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

use Ox\Cli\Seed\CommuneDictionary;
use Ox\Cli\Seed\FrenchCoding;
use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\Specialities;

/**
 * Le décor par défaut : un centre hospitalier français généraliste.
 *
 * C'est celui que tous les profils livrés emploient, et celui que les tests d'invariants prennent pour
 * référence. Il assemble les pièces existantes — catalogue français, proportions par défaut, identités
 * du dépôt, dictionnaire des communes, nommage « Centre Hospitalier X — Ville » — sans rien ajouter.
 *
 * Son intérêt n'est pas ce qu'il fait, mais ce qu'il PROUVE : que le moteur ne connaît plus le décor
 * autrement que par ces six interfaces.
 */
final class FrenchHospitalDecor implements SeedDecor
{
    public const NAME = 'hopital-france';

    /** @var SpecialityCatalogue|null */
    private $catalogue;

    /** @var ClinicalMix|null */
    private $mix;

    /** @var ClinicalCoding|null */
    private $coding;

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Centre hospitalier français généraliste : urgences, hospitalisation, soins continus,'
            . ' bloc programmé et bloc de garde.';
    }

    /**
     * @throws SeedException
     */
    public function catalogue(): SpecialityCatalogue
    {
        return $this->catalogue = $this->catalogue ?? Specialities::catalogue();
    }

    /**
     * @throws SeedException
     */
    public function clinicalMix(): ClinicalMix
    {
        return $this->mix = $this->mix ?? new ClinicalMix();
    }

    public function identities(): IdentitySource
    {
        return new FileIdentities();
    }

    public function geography(): ?GeographySource
    {
        return new CommuneDictionary();
    }

    /**
     * @throws SeedException
     */
    public function coding(): ?ClinicalCoding
    {
        return $this->coding = $this->coding ?? FrenchCoding::coding();
    }

    public function naming(): EstablishmentNaming
    {
        return new PatronNaming(
            'Centre Hospitalier',
            ['Sainte-Aldegonde', 'Saint-Elme', 'Sainte-Vérane', 'Saint-Lubin', 'Sainte-Ombeline'],
            'CH'
        );
    }

    public function builders(): SeedBuilders
    {
        return new StandardBuilders();
    }
}
