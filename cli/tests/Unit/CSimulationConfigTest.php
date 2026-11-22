<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\Console\Simulation\CSimulationConfig;
use Ox\Tests\UnitTestHospilibre;

class CSimulationConfigTest extends UnitTestHospilibre
{
    private const CONFIG_DIR = __DIR__ . '/../../classes/Console/Simulation/config';

    public function testProfilsNommes(): void
    {
        $this->assertSame(realpath(self::CONFIG_DIR . '/hopital_moyen.php'), realpath(CSimulationConfig::profilePath('moyen')));
        $this->assertSame(realpath(self::CONFIG_DIR . '/hopital_moyen.php'), realpath(CSimulationConfig::profilePath('on')));
        $this->assertSame('chu', CSimulationConfig::fromProfile('chu')->getName());
    }

    public function testProfilInconnuOuInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CSimulationConfig::profilePath('../../etc/passwd');
    }

    public function testProfilInexistant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CSimulationConfig::profilePath('psychiatrie');
    }

    public function testProfilMoyenResteNonType(): void
    {
        $cfg = CSimulationConfig::fromProfile('moyen');
        $this->assertFalse($cfg->isTyped());
        $this->assertSame([], $cfg->getServices());
        $this->assertSame(3, $cfg->getBlocs());          // sizes.salles_bloc
        $this->assertSame('comp', $cfg->getSejourType());
    }

    /** @dataProvider profilsCours */
    public function testProfilsDuCours(string $profil, int $litsMin, int $litsMax, int $blocs): void
    {
        $cfg = CSimulationConfig::fromProfile($profil);
        $this->assertTrue($cfg->isTyped());
        $this->assertSame($profil, $cfg->getName());
        $this->assertGreaterThanOrEqual($litsMin, $cfg->getTotalLits());
        $this->assertLessThanOrEqual($litsMax, $cfg->getTotalLits());
        $this->assertSame($blocs, $cfg->getBlocs());
        // CGroups.raison_sociale : "str maxLength|50" — un dépassement fait échouer le bootstrap.
        $this->assertLessThanOrEqual(50, mb_strlen($cfg->getHospital()['raison_sociale']), "$profil : raison sociale > 50");
        foreach (CSimulationConfig::RATE_KEYS as $key) {
            $this->assertNotSame(-1.0, $cfg->getRateFloat($key, -1.0), "$profil : rate « $key » absent");
        }
        $noms = array_column($cfg->getServices(), 'nom');
        $this->assertSame(count($noms), count(array_unique($noms)), "$profil : noms de services en double");
        foreach ($cfg->getServices() as $s) {
            $this->assertNotEmpty($s['spec_cpam'], "$profil/{$s['nom']} : aucune spécialité");
            $this->assertGreaterThan(0, $s['poids_admissions']);
        }
    }

    public function profilsCours(): array
    {
        return [
            'chu'      => ['chu', 280, 320, 6],
            'clinique' => ['clinique', 70, 90, 5],
            'ssr'      => ['ssr', 55, 65, 0],
        ];
    }

    public function testFacteursDeTaux(): void
    {
        $cfg   = CSimulationConfig::fromProfile('moyen');
        $crise = $cfg->withRateFactors(['arrivals_per_day' => 2.5, 'transfer_rate' => 2.0, 'inconnu' => 9.0]);

        $this->assertSame(15, $crise->getRateInt('arrivals_per_day', 0));               // 6 × 2,5
        $this->assertEqualsWithDelta(0.3, $crise->getRateFloat('transfer_rate', 0.0), 1e-9); // 0,15 × 2
        $this->assertSame(20, $crise->getRateInt('consults_per_day', 0));               // non cité : inchangé
        $this->assertSame(6, $cfg->getRateInt('arrivals_per_day', 0));                  // original non muté
        $this->assertSame(-1, $crise->getRateInt('inconnu', -1));                       // pas de création de clé
    }

    public function testSejoursInitiauxNonPlafonnesParLesPraticiens(): void
    {
        // CHU : 230 séjours voulus. Avant correctif, plafonné à 45 = nombre de praticiens
        // (mesure Task 0 : occupation initiale 15 % au lieu de ~77 %).
        $cfg = CSimulationConfig::fromProfile('chu');
        $this->assertSame(230, $cfg->initialSejours(291, 292, 45));
        // Bornes réelles : patients et lits disponibles.
        $this->assertSame(100, $cfg->initialSejours(100, 292, 45));
        $this->assertSame(80, $cfg->initialSejours(291, 80, 45));
        // Sans praticien, aucun séjour ne peut être posé.
        $this->assertSame(0, $cfg->initialSejours(291, 292, 0));
    }

    public function testProfilDuStateGouverne(): void
    {
        // Pas d'option --config : le profil enregistré au semis gouverne, quel que soit l'environnement.
        $this->assertSame('ssr', CSimulationConfig::resolveForAdvance(null, 'ssr')->getName());
        // Un state antérieur (« moyen ») ou absent retombe sur hopital_moyen.
        $this->assertSame('moyen', CSimulationConfig::resolveForAdvance(null, 'moyen')->getName());
        $this->assertSame('moyen', CSimulationConfig::resolveForAdvance(null, null)->getName());
        // --config explicite reste prioritaire (mesures, tests).
        $path = self::CONFIG_DIR . '/chu.php';
        $this->assertSame('chu', CSimulationConfig::resolveForAdvance($path, 'ssr')->getName());
    }

    public function testArrondiDesTaux(): void
    {
        $cfg = CSimulationConfig::fromProfile('moyen')->withRateFactors(['avg_sejour_days' => 1.3]);
        $this->assertSame(7, $cfg->getRateInt('avg_sejour_days', 0)); // 5 × 1,3 = 6,5 -> 7
    }

    public function testChargeConfigParDefautEtOverrides(): void
    {
        $path = dirname(__DIR__, 2) . '/classes/Console/Simulation/config/hopital_moyen.php';
        $cfg  = CSimulationConfig::fromFile($path);

        $this->assertSame('moyen', $cfg->getName());
        $this->assertNotEmpty($cfg->getHospital()['nom']);
        $this->assertGreaterThan(0, $cfg->getInt('services', 0));

        $over = $cfg->withOverrides(['nom' => 'CH Test', 'services' => 2]);
        $this->assertSame('CH Test', $over->getHospital()['nom']);
        $this->assertSame(2, $over->getInt('services', 0));
        // l'original n'est pas muté
        $this->assertNotSame('CH Test', $cfg->getHospital()['nom']);
    }

    public function testDebits(): void
    {
        $cfg = CSimulationConfig::fromFile(
            __DIR__ . '/../../classes/Console/Simulation/config/hopital_moyen.php'
        );

        // Valeurs par défaut de la config « moyen ».
        $this->assertSame(6, $cfg->getRateInt('arrivals_per_day', 0));
        $this->assertSame(5, $cfg->getRateInt('avg_sejour_days', 0));
        $this->assertSame(0.15, $cfg->getRateFloat('transfer_rate', 0.0));

        // Clé absente -> défaut.
        $this->assertSame(99, $cfg->getRateInt('inconnu', 99));
        $this->assertSame(1.5, $cfg->getRateFloat('inconnu', 1.5));
    }
}
