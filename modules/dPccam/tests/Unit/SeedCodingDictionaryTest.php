<?php

/**
 * @package Hospilibre\Ccam
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Hospilibre\Ccam\Tests\Unit;

use Hospilibre\Ccam\CCodeCCAM;
use Hospilibre\Cim10\CCodeCIM10;
use Ox\Cli\Seed\Cim10Dictionary;
use Ox\Cli\Seed\FrenchCoding;
use Ox\Core\CSQLDataSource;
use Ox\Tests\UnitTestHospilibre;

/**
 * LES CODES DU SEEDER EXISTENT-ILS VRAIMENT DANS LES DICTIONNAIRES OFFICIELS ?
 *
 * C'est le seul test qui puisse répondre, et c'est pour cela qu'il est ici et non dans `cli/tests` : il
 * a besoin des datasources `ccamV2` et `cim10`, donc d'une instance dont les dictionnaires ont été
 * importés (`ox-install:externals --import`).
 *
 * CE QU'IL PROTÈGE, et que rien d'autre ne protégerait :
 *
 * - un code INVENTÉ. La table de codage est écrite à la main ; `HHFA016` et `HHFA061` se ressemblent, et
 *   le socle ne contrôle la CCAM que sur sa forme — les deux passeraient. Côté CIM-10 la sanction est
 *   pire : `CSejour::check()` vide le champ en silence, et le séjour part sans diagnostic ;
 * - un code RETIRÉ. Les deux nomenclatures sont révisées chaque année ; `p_acte.DATEFIN` dit quand un
 *   acte cesse d'être facturable, et un code périmé se repère ici plutôt qu'en production ;
 * - un code SANS LIBELLÉ EXPLOITABLE. Le dictionnaire en contient : 27 codes CIM-10 sans libellé
 *   français, et 24 dont le libellé porte encore le marqueur `#EOL` laissé par l'import des
 *   modifications. Un diagnostic qui s'affiche « Fibromyalgie#EOLFibromyosite » est un défaut visible ;
 *   ces codes sont écartés de la table de codage, et ce test empêche qu'un s'y glisse.
 *
 * Le test se SAUTE quand les dictionnaires ne sont pas montés, au lieu d'échouer : une instance sans
 * dictionnaire est une configuration légitime — le seeder y écrit alors des actes cotés et des séjours
 * sans diagnostic, ce qu'il annonce dans son rapport.
 */
class SeedCodingDictionaryTest extends UnitTestHospilibre
{
    /** Marqueur de saut de ligne laissé par CImportCim10 dans certains libellés. */
    private const LINE_FEED_MARKER = '#EOL';

    /**
     * Les codes CCAM déclarés existent, et sont encore en vigueur.
     */
    public function testEveryDeclaredCcamCodeExistsAndIsStillValid(): void
    {
        if (CSQLDataSource::get('ccamV2', true) === null) {
            $this->markTestSkipped("La datasource 'ccamV2' n'est pas déclarée sur cette instance");
        }

        $codes = FrenchCoding::ccamCodes();
        $this->assertNotEmpty($codes);

        foreach ($codes as $code) {
            $acte = CCodeCCAM::get($code);

            $this->assertSame(
                $code,
                (string)$acte->code,
                "Le code CCAM '{$code}' est introuvable dans le dictionnaire : load() remplace le code"
                . " par '-' quand il n'existe pas"
            );

            $this->assertNotSame(
                '',
                (string)$acte->libelle_long,
                "Le code CCAM '{$code}' n'a pas de libellé"
            );

            // '00000000' signifie « sans date de fin ». Toute autre valeur est une date YYYYMMDD.
            $end = (string)$acte->date_fin;

            if ($end !== '' && $end !== '00000000') {
                $this->assertGreaterThan(
                    date('Ymd'),
                    $end,
                    "Le code CCAM '{$code}' ({$acte->libelle_long}) n'est plus en vigueur depuis {$end}"
                );
            }
        }
    }

    /**
     * Les codes CIM-10 déclarés existent, et leur libellé est exploitable.
     */
    public function testEveryDeclaredCim10CodeExistsWithAUsableLabel(): void
    {
        if (!Cim10Dictionary::isAvailable()) {
            $this->markTestSkipped('Le dictionnaire CIM-10 ne répond pas sur cette instance');
        }

        $codes = FrenchCoding::cim10Codes();
        $this->assertNotEmpty($codes);

        foreach ($codes as $code) {
            $diagnosis = CCodeCIM10::get($code);

            $this->assertTrue(
                (bool)$diagnosis->exist,
                "Le code CIM-10 '{$code}' est introuvable. Attention à la forme : le dictionnaire ne"
                . " répond que sans le point — « J189 » et non « J18.9 »."
            );

            $label = (string)$diagnosis->libelle;

            $this->assertNotSame('', $label, "Le code CIM-10 '{$code}' n'a pas de libellé français");

            $this->assertStringNotContainsString(
                self::LINE_FEED_MARKER,
                $label,
                "Le libellé du code CIM-10 '{$code}' porte le marqueur " . self::LINE_FEED_MARKER
                . ' : il s\'afficherait tel quel. Choisir un autre code.'
            );
        }
    }

    /**
     * Le diagnostic qui motive un acte doit être cohérent avec la CIM-10 : un code de chapitre entier
     * ou un code de regroupement ne se pose pas sur un séjour. Le niveau du code le dit.
     */
    public function testProcedureDiagnosesAreTerminalCodes(): void
    {
        if (!Cim10Dictionary::isAvailable()) {
            $this->markTestSkipped('Le dictionnaire CIM-10 ne répond pas sur cette instance');
        }

        foreach (FrenchCoding::cim10Codes() as $code) {
            $diagnosis = CCodeCIM10::get($code);

            // Niveaux 1 et 2 sont les chapitres et les groupes ; un diagnostic de séjour est plus bas.
            $this->assertGreaterThanOrEqual(
                3,
                (int)$diagnosis->level,
                "Le code CIM-10 '{$code}' ({$diagnosis->libelle}) est un regroupement de niveau"
                . " {$diagnosis->level}, pas un diagnostic"
            );
        }
    }
}
