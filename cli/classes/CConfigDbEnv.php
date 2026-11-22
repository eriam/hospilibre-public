<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli;

/**
 * Identifiants de base injectés dans includes/config.php depuis l'environnement (DB_HOST,
 * DB_NAME, DB_USER, DB_PASS) au démarrage du conteneur.
 *
 * Le fichier versionné ne porte AUCUN identifiant : il en portait ceux de staging, embarqués dans
 * toute image construite depuis le dépôt — y compris celles distribuées aux étudiants. Chaque
 * source déclarée sur l'hôte DB_HOST reçoit utilisateur et mot de passe ; le NOM de base n'est
 * imposé qu'à std et aux sources qui n'en ont pas (celles vidées dans le dépôt, hébergées dans la
 * base applicative). Une source qui a SA base (INSEE, ccamV2, cim10, déclarées par
 * ox-install:externals) la garde. Les sources d'autres hôtes ne sont pas touchées.
 * Transformation de texte pure : testable, idempotente.
 */
class CConfigDbEnv
{
    private const FIELDS = ['dbname', 'dbuser', 'dbpass'];

    public static function apply(string $php, string $host, string $name, string $user, string $pass): string
    {
        $values = ['dbname' => $name, 'dbuser' => $user, 'dbpass' => $pass];

        preg_match_all(
            "/^\\\$dPconfig\\['db'\\]\\['([^']+)'\\]\\['dbhost'\\] = '((?:[^'\\\\]|\\\\.)*)';$/m",
            $php,
            $hosts,
            PREG_SET_ORDER
        );
        $sources = [];
        foreach ($hosts as [, $ds, $dbhost]) {
            if (stripslashes($dbhost) === $host) {
                $sources[] = $ds;
            }
        }

        if (!in_array('std', array_column($hosts, 1), true)) {
            $php = rtrim($php) . "\n\$dPconfig['db']['std']['dbtype'] = 'mysql';\n"
                . "\$dPconfig['db']['std']['dbhost'] = " . var_export($host, true) . ";\n";
            $sources[] = 'std';
        }

        foreach ($sources as $ds) {
            foreach (self::FIELDS as $field) {
                if ($field === 'dbname' && $ds !== 'std' && self::currentValue($php, $ds, 'dbname') !== '') {
                    continue; // la source a sa propre base : on ne la renvoie pas vers la base applicative
                }
                $line    = "\$dPconfig['db']['$ds']['$field'] = " . var_export($values[$field], true) . ';';
                $pattern = "/^\\\$dPconfig\\['db'\\]\\['" . preg_quote($ds, '/') . "'\\]\\['$field'\\] = .*;$/m";
                if (preg_match($pattern, $php)) {
                    $php = preg_replace_callback($pattern, static fn () => $line, $php);
                } else {
                    $php = rtrim($php) . "\n" . $line . "\n";
                }
            }
        }

        return $php;
    }

    /** Valeur actuelle d'un champ d'une source ('' si absent). */
    private static function currentValue(string $php, string $ds, string $field): string
    {
        $pattern = "/^\\\$dPconfig\\['db'\\]\\['" . preg_quote($ds, '/') . "'\\]\\['$field'\\] = '((?:[^'\\\\]|\\\\.)*)';$/m";

        return preg_match($pattern, $php, $m) ? stripslashes($m[1]) : '';
    }
}
