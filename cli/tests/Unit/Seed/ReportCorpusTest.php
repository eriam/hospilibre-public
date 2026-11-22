<?php

/**
 * @package Hospilibre\Cli\Tests
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use Ox\Cli\Seed\ReportCorpus;
use Ox\Cli\Seed\SeedException;
use Ox\Cli\Seed\SurgicalIncidents;
use Ox\Tests\UnitTestHospilibre;

/**
 * Les refus du corpus, qui sont sa seule garantie.
 *
 * CE QUI EST ÉPROUVÉ ICI N'EST PAS LE CHARGEMENT MAIS LE REFUS. Un corpus qui se charge ne prouve
 * rien ; ce qui protège le dossier patient, c'est qu'un corpus porteur de faits soit REJETÉ. Et
 * cette garantie compte d'autant plus que le corpus sera un jour produit par un modèle de langage,
 * qui inventera des âges et des dates si rien ne l'en empêche.
 */
class ReportCorpusTest extends UnitTestHospilibre
{
    /** @var string[] */
    private $written = [];

    protected function tearDown(): void
    {
        ReportCorpus::useFile(null);

        foreach ($this->written as $path) {
            @unlink($path);
        }

        $this->written = [];

        parent::tearDown();
    }

    /**
     * LE CORPUS DU DÉPÔT DOIT PASSER SES PROPRES CONTRÔLES.
     *
     * Sans ce test, une faute de frappe dans le fichier livré ne se verrait qu'au prochain semis —
     * soit, selon le profil, après plusieurs minutes d'écriture à défaire.
     */
    public function testShippedCorpusLoads(): void
    {
        $this->assertTrue(ReportCorpus::isAvailable(), 'Le corpus du dépôt doit être chargeable');
        $this->assertGreaterThan(0, ReportCorpus::combinations());
        $this->assertNotSame([], ReportCorpus::section('fermetures'));
    }

    /**
     * CHAQUE INCIDENT DU CATALOGUE A SA PROSE, sans quoi un dossier porterait un incident dont le
     * compte rendu ne dirait rien.
     */
    public function testEveryIncidentHasProse(): void
    {
        foreach (SurgicalIncidents::names() as $incident) {
            $this->assertNotSame(
                [],
                ReportCorpus::incident($incident),
                "L'incident '{$incident}' n'a aucun paragraphe : le fait serait muet dans le récit."
            );
        }
    }

    /**
     * LE REFUS LE PLUS IMPORTANT : un chiffre dans la prose.
     *
     * Un fragment est réemployé des dizaines de fois ; un âge ou une durée écrits dedans
     * contrediraient le dossier dès le deuxième emploi.
     */
    public function testAFigureInTheProseIsRejected(): void
    {
        $path = $this->corpus(
            [
                'gestes' => [
                    'generique' => ['Patient de 54 ans, abord habituel. Geste réglé.'],
                ],
            ]
        );

        ReportCorpus::useFile($path);

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/CHIFFRE/');

        ReportCorpus::section('fermetures');
    }

    /**
     * UNE SUBSTITUTION INCONNUE apparaîtrait TELLE QUELLE dans le dossier.
     */
    public function testAnUnknownPlaceholderIsRejected(): void
    {
        $path = $this->corpus(
            [
                'gestes' => [
                    'generique' => ['Abord pour {acte}. Patient né à {ville_de_naissance}.'],
                ],
            ]
        );

        ReportCorpus::useFile($path);

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/ville_de_naissance/');

        ReportCorpus::section('fermetures');
    }

    /**
     * UN INCIDENT SANS PROSE est refusé À L'OUVERTURE et non au tirage : le découvrir au millième
     * séjour obligerait à purger pour réessayer.
     */
    public function testAMissingIncidentIsRejectedAtLoad(): void
    {
        $path = $this->corpus(['incidents' => ['conversion' => ['Conversion décidée.']]]);

        ReportCorpus::useFile($path);

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/incident/');

        ReportCorpus::section('fermetures');
    }

    /**
     * L'ABSENCE DE FRAGMENT GÉNÉRIQUE est un défaut, pas un choix : un acte non nommé ne
     * produirait AUCUN compte rendu, et le manque serait silencieux.
     */
    public function testAbsentGenericGestureIsRejected(): void
    {
        $path = $this->corpus(['gestes' => ['HMFC004' => ['Cholécystectomie réglée.']]]);

        ReportCorpus::useFile($path);

        $this->expectException(SeedException::class);
        $this->expectExceptionMessageMatches('/generique/');

        ReportCorpus::section('fermetures');
    }

    /**
     * Un acte nommé a ses gestes propres ; un acte inconnu retombe sur les génériques, et le
     * DISTINGUO est ce qui permet au semis de rapporter sa couverture.
     */
    public function testNamedActsAreDistinguishedFromGenericOnes(): void
    {
        $this->assertTrue(ReportCorpus::hasOwnGesture('HMFC004'));
        $this->assertFalse(ReportCorpus::hasOwnGesture('ZZZZ999'));
        $this->assertNotSame([], ReportCorpus::gesturesFor('ZZZZ999'), 'Un acte inconnu doit tout de même produire une prose');
    }

    /**
     * Écrit un corpus de test, complété des sections obligatoires absentes.
     *
     * @param array<string, mixed> $overrides
     */
    private function corpus(array $overrides): string
    {
        $incidents = [];

        foreach (SurgicalIncidents::names() as $incident) {
            $incidents[$incident] = ['Paragraphe de ' . $incident . '.'];
        }

        $corpus = array_merge(
            [
                'version'      => 1,
                'placeholders' => ['acte', 'asa', 'installation', 'duree'],
                'ouvertures'   => ['generique' => ['Installation et champage.']],
                'gestes'       => ['generique' => ['Abord pour {acte}, geste réglé.']],
                'fermetures'   => ['Fermeture plan par plan.'],
                'incidents'    => $incidents,
            ],
            $overrides
        );

        $path = sys_get_temp_dir() . '/corpus-' . uniqid('', false) . '.json';
        file_put_contents($path, json_encode($corpus, JSON_UNESCAPED_UNICODE));

        $this->written[] = $path;

        return $path;
    }
}
