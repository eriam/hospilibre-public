<?php

/**
 * @package Hospilibre\Cli\Tests
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use finfo;
use Hospilibre\Files\CFile;
use Ox\Cli\Seed\SeedAttachment;
use Ox\Tests\UnitTestHospilibre;

/**
 * UNE PIÈCE JOINTE DOIT ÊTRE CE QU'ELLE DIT ÊTRE, ET TENIR DANS LE DISQUE.
 *
 * ⚠ CE FICHIER A CORRIGÉ DEUX DE MES ERREURS, ET C'EST LA RAISON DE SON EXISTENCE.
 *
 * LA PREMIÈRE PORTAIT SUR LE RÔLE DE L'APPLICATION. J'avais écrit dans `DocumentBuilder` qu'« un
 * PDF mal formé serait refusé par l'application elle-même ». Le témoin négatif de la première
 * version de ce test a échoué et l'a démenti : `CFile::isAllowedFileContent()` contrôle
 * SÉPARÉMENT l'extension et le type MIME relu du contenu, SANS vérifier leur concordance. Comme
 * `text/plain` et `application/octet-stream` sont tous deux admis, la chaîne « ceci n'est pas un
 * PDF » nommée « .pdf » passe sans difficulté. L'application n'est donc PAS un filet : la cohérence
 * entre le type déclaré et le contenu écrit est à notre charge seule, et c'est ce que ce fichier
 * vérifie.
 *
 * LA SECONDE PORTAIT SUR LE FORMAT. La première version produisait de vrais PDF. Le test de poids
 * a mesuré 660 Ko par document avec `dejavusans`, 87 Ko avec la plus légère police du dépôt qui
 * couvre le français — la police étant embarquée EN ENTIER dans chaque PDF, faute de police de base
 * livrée et de sous-ensemble de glyphes dans ce fork de TCPDF. À 87 Ko, le profil `medium` pesait
 * 2,1 Go et le profil `large` plus de 6 Go. C'est cette mesure, et rien d'autre, qui a fait passer
 * les pièces jointes au HTML.
 *
 * ⇒ LE PLAFOND DE POIDS RESTE DONC, et c'est l'assertion la plus utile du fichier : c'est elle qui
 * a trouvé le problème, et c'est elle qui le retrouverait si quelqu'un revenait au PDF sans mesurer.
 */
class SeedAttachmentTest extends UnitTestHospilibre
{
    /**
     * Plafond par pièce jointe, en octets.
     *
     * ⚠ CALIBRÉ SUR LA MESURE, PAS CHOISI AU HASARD. Un document HTML du semis pèse environ mille
     * cinq cents octets. Le plafond à huit kilo-octets laisse de la marge pour une prose plus longue
     * et reste DIX FOIS en dessous des 87 Ko du PDF le plus léger possible : s'il est franchi, c'est
     * qu'on a changé de format ou embarqué quelque chose, et il faut refaire le calcul de volume
     * pour les profils `medium` et `large` avant d'accepter.
     */
    private const MAX_BYTES = 8000;

    /** Le texte d'épreuve : accents, ligature « œ », apostrophe, pour que rien ne passe par chance. */
    private const BODY = "Chère consœur, cher confrère, je vous adresse ce patient que je suis depuis"
    . " plusieurs années. Œdèmes discrets des membres inférieurs. Je reste à votre disposition.";

    public function testTheSniffedMimeTypeMatchesWhatWeDeclare(): void
    {
        $html  = SeedAttachment::render('Courrier du médecin traitant', ['Motif' => 'Cholécystectomie'], self::BODY);
        $finfo = new finfo(FILEINFO_MIME_TYPE, '');

        $this->assertSame(
            SeedAttachment::MIME,
            $finfo->buffer($html),
            'Le type MIME relu du contenu doit être celui que `DocumentBuilder` déclare à CFile.'
            . " L'application ne vérifie pas cette concordance : c'est à nous de la tenir."
        );

        // ★ LE TÉMOIN : le reniflage doit pouvoir rendre autre chose, sinon l'assertion ci-dessus
        // ne prouverait pas qu'il discrimine.
        $this->assertNotSame(
            SeedAttachment::MIME,
            $finfo->buffer("du texte sans la moindre balise"),
            'Un contrôle qui ne peut pas échouer ne dit rien.'
        );
    }

    /**
     * ★ LE PLAFOND : c'est cette assertion qui a fait abandonner le PDF.
     */
    public function testADocumentStaysUnderTheWeightCeiling(): void
    {
        $html = SeedAttachment::render(
            'Courrier du médecin traitant',
            ['Admission prévue' => '2026-10-07', 'Motif' => 'Cholécystectomie'],
            self::BODY
        );

        $this->assertLessThan(
            self::MAX_BYTES,
            strlen($html),
            'Une pièce jointe dépasse le plafond. Avant de relever celui-ci, refaire le calcul de'
            . ' volume : le profil `medium` produit environ vingt-quatre mille pièces, le profil'
            . ' `large` environ soixante-douze mille.'
        );
    }

    public function testTheApplicationAcceptsTheContentAndTheExtension(): void
    {
        $html = SeedAttachment::render('Consentement éclairé signé', [], self::BODY);

        $this->assertTrue(
            CFile::isAllowedFileContent($html, SeedAttachment::EXTENSION),
            "L'extension et le type doivent tous deux figurer dans les listes blanches de CFile,"
            . " sans quoi store() refuse le fichier en rendant une chaîne — et non en levant."
        );
    }

    /**
     * Les accents et la ligature doivent se retrouver TELS QUELS dans le document.
     *
     * VÉRIFIABLE ICI, CE QUI NE L'ÉTAIT PAS EN PDF : le texte d'un PDF est compressé et encodé selon
     * la police, donc une recherche de chaîne y échouait pour de mauvaises raisons et le test se
     * contentait de constater que le rendu avait abouti. En HTML, le texte est le texte.
     */
    public function testAccentsAndLigaturesSurviveVerbatim(): void
    {
        $html = SeedAttachment::render("Compte rendu d'anatomopathologie", ['Intervention' => 'Exérèse'], self::BODY);

        $this->assertStringContainsString('consœur', $html, 'La ligature doit survivre.');
        $this->assertStringContainsString('Œdèmes', $html);
        $this->assertStringContainsString('Exérèse', $html, "L'en-tête factuel doit être accentué aussi.");
        $this->assertStringContainsString('charset="utf-8"', $html, "L'encodage doit être déclaré.");
    }

    /**
     * ⚠ ET L'ÉCHAPPEMENT, parce que les faits viennent de la base et non d'une constante.
     *
     * Un libellé d'intervention contenant un chevron — ce qu'une saisie libre permet — casserait le
     * document ou y injecterait du balisage. Le document est servi par l'application dans un
     * navigateur : c'est un vecteur réel, pas une hypothèse.
     */
    public function testFactsComingFromTheDatabaseAreEscaped(): void
    {
        $html = SeedAttachment::render('Titre', ['Motif' => '<script>alert(1)</script>'], 'Corps.');

        $this->assertStringNotContainsString('<script>', $html, 'Le balisage doit être échappé.');
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
