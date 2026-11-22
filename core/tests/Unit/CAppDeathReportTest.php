<?php

/**
 * @package Ox\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Core\Tests\Unit;

use Ox\Core\CApp;
use Ox\Tests\UnitTestHospilibre;
use ReflectionMethod;

/**
 * UN 500 NE DOIT PAS ÊTRE MUET.
 *
 * Un « Application died unexpectedly » sort avec un corps VIDE, et son seul canal de diagnostic
 * — trigger_error -> CError -> logException — est routé vers un Elasticsearch qui n'est pas
 * déployé. Mesuré sur le terrain : error.log d'Apache vide, tmp/errors/ vide, table error_log à
 * zéro ligne, et dans `docker logs` rien que la ligne d'accès 500.
 *
 * Trois diagnostics longs en une semaine ont partagé cette signature — un 500 à corps vide et
 * aucun journal — pour trois causes sans aucun rapport : un classref non généré, une collision de
 * table `note`, et un tmp/ resté à root (deux heures perdues par un utilisateur du kit de cours, qui a demandé
 * ce durcissement).
 *
 * Ce qui rend la ligne décisive : elle est construite en SHUTDOWN, donc error_get_last() porte
 * encore le fatal qui a tué le script, avec son fichier et sa ligne.
 *
 * On teste la CONSTRUCTION de la ligne, pas l'écriture : provoquer une vraie mort applicative dans
 * un test ne prouverait rien de plus et emporterait le processus phpunit.
 */
class CAppDeathReportTest extends UnitTestHospilibre
{
    private function line(): string
    {
        $method = new ReflectionMethod(CApp::class, 'buildDeathReportLine');
        $method->setAccessible(true);

        return (string)$method->invoke(null);
    }

    public function testTheLineAlwaysNamesTheFailureSoItCanBeGrepped(): void
    {
        $this->assertStringContainsString(
            'Application died unexpectedly',
            $this->line(),
            'Un exploitant doit pouvoir grepper cette ligne sans connaître la cause.'
        );
    }

    /**
     * LE CŒUR DU DURCISSEMENT : le dernier fatal, avec son fichier et sa ligne.
     */
    public function testTheLastPhpErrorIsReportedWithItsFileAndLine(): void
    {
        // Provoque une erreur non fatale pour alimenter error_get_last() sans tuer le test.
        @trigger_error('panne simulee pour le test', E_USER_WARNING);

        $line = $this->line();

        $this->assertStringContainsString('last_error=', $line);
        $this->assertStringContainsString(
            'panne simulee pour le test',
            $line,
            "C'est cette information qui manquait : sans elle, trois pannes distinctes avaient la"
            . ' même signature.'
        );
        $this->assertMatchesRegularExpression(
            '/ at .+:\d+/',
            $line,
            'Le fichier et la ligne sont ce qui transforme un 500 muet en diagnostic.'
        );
    }

    /**
     * Le message d'erreur PHP peut être multiligne (traces SQL notamment). La ligne de rapport
     * doit rester UNE ligne, sans quoi elle devient illisible mêlée aux journaux d'accès.
     */
    public function testTheReportStaysOnASingleLine(): void
    {
        @trigger_error("premiere ligne\nseconde ligne", E_USER_WARNING);

        $this->assertStringNotContainsString("\n", $this->line());
    }

    /**
     * La chaîne de requête est volontairement ÉCARTÉE : le journal d'accès porte déjà l'URL
     * complète, et la recopier ici dupliquerait d'éventuels jetons dans un second endroit.
     */
    public function testTheQueryStringIsNotCopiedIntoTheLog(): void
    {
        $previous_uri    = $_SERVER['REQUEST_URI'] ?? null;
        $previous_method = $_SERVER['REQUEST_METHOD'] ?? null;

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/index.php?m=admin&login_token=SECRET42';

        try {
            $line = $this->line();

            $this->assertStringContainsString('/index.php', $line, 'Le chemin reste utile au diagnostic.');
            $this->assertStringNotContainsString('SECRET42', $line, 'La chaîne de requête ne doit pas être recopiée.');
        } finally {
            if ($previous_uri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previous_uri;
            }

            if ($previous_method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previous_method;
            }
        }
    }
}
