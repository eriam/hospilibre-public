<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed;

/**
 * Le contenu d'une pièce jointe du semis : un document HTML autonome.
 *
 * ⚠ C'ÉTAIT UN PDF, ET LA MESURE M'A FAIT REVENIR DESSUS. La première version produisait de vrais
 * PDF par TCPDF, au motif — juste en soi — qu'une pièce jointe réelle est un PDF. Mesuré ensuite :
 * SOIXANTE-DIX-SEPT KILO-OCTETS DE POLICE pour un kilo et demi de texte, et aucun moyen d'y
 * échapper.
 *
 * POURQUOI AUCUN MOYEN. Le fork de TCPDF du dépôt ne livre AUCUNE des quatorze polices de base du
 * format PDF — `helvetica.php`, `times.php`, `courier.php` sont absents — et n'implémente pas le
 * sous-ensemble de glyphes. Toute police utilisable est donc une police TrueType embarquée EN
 * ENTIER dans CHAQUE document. La plus légère du lot qui couvre le français, `vera`, donne un
 * document de 87 Ko ; `dejavusans`, celle que l'application emploie ailleurs, en donne un de 660 Ko.
 *
 * CE QUE ÇA DONNE À L'ÉCHELLE DES PROFILS :
 *
 *   — `small`  (60 lits, 3 mois) :  ~1 800 pièces →  157 Mo
 *   — `medium` (200 lits, 12 mois) : ~24 000 pièces →  2,1 Go
 *   — `large`  (600 lits, 12 mois) : ~72 000 pièces →  6 Go et plus
 *
 * ⚠ MAIS CE N'EST PAS LA RAISON DÉCISIVE, ET J'AI D'ABORD CRU LE CONTRAIRE SUR UN CHIFFRE FAUX.
 * J'ai justifié l'abandon du PDF en écrivant que la machine de production n'avait « sept
 * gigaoctets libres », donc que 2,1 Go y seraient à l'étroit. Mesure d'infra : elle a 30 Go dont
 * 21 LIBRES. Mes sept gigaoctets étaient sa MÉMOIRE VIVE — une valeur notée pour une tout autre
 * question, celle de l'`earlyoom` qui tue les semis — que j'ai transportée dans un raisonnement
 * sur le disque. Les 2,1 Go passaient donc confortablement. Le volume reste un argument de
 * sobriété ; il n'était pas un argument d'impossibilité.
 *
 * ★ LA RAISON DÉCISIVE EST CELLE-CI, ET ELLE NE DÉPEND D'AUCUN CHIFFRE. Un PDF minimal employant
 * la police Helvetica de base, non embarquée, pèserait un kilo-octet : c'est techniquement la
 * meilleure réponse. Mais AUCUN LECTEUR DE PDF INDÉPENDANT n'est installé sur la machine de
 * développement — ni `pdftotext`, ni `qpdf`, ni `pypdf`. La seule vérification possible serait mes
 * propres assertions, et `finfo` reconnaît un PDF à ses CINQ PREMIERS OCTETS : il dirait
 * « application/pdf » d'un fichier par ailleurs corrompu. Livrer une pièce jointe dont aucun
 * instrument indépendant ne peut dire qu'elle s'ouvre serait pire que ne pas en livrer — la ligne
 * apparaîtrait dans le dossier et l'utilisateur obtiendrait une erreur du lecteur.
 *
 * Le HTML, lui, se vérifie par simple lecture : le texte est le texte, et un test peut le
 * retrouver mot pour mot. C'est cela, et non le volume, qui tranche.
 *
 * DONC DU HTML, qui est vérifiable de bout en bout : `text/html` est dans la liste blanche des types
 * de `CFile`, `.html` dans celle des extensions, l'application sait l'afficher, et un document de
 * mille cinq cents octets porte exactement le même texte. Ce que l'on perd est la ressemblance avec
 * un scan ; ce que l'on garde est un dossier qui tient sur le disque et dont on peut lire le
 * contenu sans outil.
 *
 * CE N'EST PAS UN SCAN, ET IL FAUT LE DIRE PLUTÔT QUE LE MASQUER : un vrai courrier de médecin
 * traitant est une image, souvent de travers, parfois manuscrite. On ne fabrique pas un scan
 * plausible sans image source. Le lecteur d'un jeu de données doit savoir ce qu'il regarde.
 */
final class SeedAttachment
{
    /** L'extension du fichier déposé, et elle est contrôlée par `CFile`. */
    public const EXTENSION = 'html';

    /** Le type MIME déclaré, qui doit être celui que `finfo` relira du contenu. */
    public const MIME = 'text/html';

    /**
     * Rend le document.
     *
     * AUTONOME ET SANS RESSOURCE EXTERNE : pas de feuille de style liée, pas d'image. Une pièce
     * jointe est servie hors du contexte de l'application, et toute référence sortante y serait
     * soit cassée, soit une fuite vers l'extérieur depuis un dossier patient.
     *
     * @param array<string, string> $facts En-tête factuel, lu dans le dossier
     * @param string                $body  Prose, venue du corpus
     */
    public static function render(string $title, array $facts, string $body): string
    {
        $html = "<!DOCTYPE html>\n<html lang=\"fr\"><head><meta charset=\"utf-8\" />"
            . '<title>' . self::escape($title) . '</title>'
            . '<style>body{font-family:serif;margin:3em;max-width:40em;line-height:1.5}'
            . 'th{text-align:left;padding-right:1em;vertical-align:top}'
            . 'table{margin-bottom:1.5em}</style></head><body>';

        $html .= '<h1>' . self::escape($title) . '</h1>';

        if ($facts !== []) {
            $html .= '<table>';

            foreach ($facts as $label => $value) {
                $html .= '<tr><th>' . self::escape($label) . '</th><td>' . self::escape($value)
                    . '</td></tr>';
            }

            $html .= '</table>';
        }

        $html .= '<p>' . self::escape($body) . '</p>';

        return $html . "</body></html>\n";
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
