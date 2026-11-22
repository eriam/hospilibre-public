<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Tests\Unit\Seed;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Garde-fou : aucune primitive d'aléa du langage dans le moteur de seed.
 *
 * `mt_rand()`, `shuffle()`, `array_rand()`, `uniqid()`… ne garantissent pas la même séquence
 * d'une version de PHP ou d'une plateforme à l'autre. Un seul appel oublié suffit à rendre un
 * range non reproductible, et la panne est silencieuse : les données restent plausibles, elles
 * cessent simplement d'être les mêmes. Ce test est le seul endroit où cette règle est
 * vérifiable automatiquement.
 *
 * L'analyse passe par le tokenizer et non par un grep : `SeedRandom::shuffle()` est une méthode
 * légitime, il faut donc distinguer un APPEL de fonction globale d'un appel de méthode ou d'une
 * déclaration. Aucune base de données n'est nécessaire.
 */
class NoNonDeterministicRandomnessTest extends TestCase
{
    private const FORBIDDEN = [
        'rand',
        'mt_rand',
        'srand',
        'mt_srand',
        'random_int',
        'random_bytes',
        'array_rand',
        'shuffle',
        'str_shuffle',
        'uniqid',
        'lcg_value',
    ];

    public function testTheSeedEngineCallsNoLanguageRandomness(): void
    {
        $files = $this->seedFiles();
        $this->assertNotEmpty($files, 'Le moteur de seed est introuvable — chemin à corriger dans ce test');

        $offences = [];
        foreach ($files as $file) {
            foreach ($this->forbiddenCallsIn($file) as $offence) {
                $offences[] = $offence;
            }
        }

        $this->assertSame(
            [],
            $offences,
            "Primitive d'aléa non déterministe dans le moteur de seed :\n  " . implode("\n  ", $offences)
        );
    }

    /**
     * Le test ne vaut que s'il sait détecter une infraction : on le vérifie sur un extrait
     * fabriqué, sinon un tokenizer mal utilisé le rendrait vert pour toujours.
     */
    public function testTheGuardActuallyDetectsAnOffence(): void
    {
        $source = <<<'PHP'
<?php
class Faux
{
    public function shuffle(array $x): array
    {
        return $this->shuffle($x) + self::shuffle($x);
    }

    public function faute(array $x): array
    {
        $y = mt_rand(1, 10);
        shuffle($x);

        return [$y, $x];
    }
}
PHP;

        $path = tempnam(sys_get_temp_dir(), 'seedguard') . '.php';
        file_put_contents($path, $source);

        try {
            $found = $this->forbiddenCallsIn($path);
        } finally {
            unlink($path);
        }

        $names = [];
        foreach ($found as $offence) {
            $names[] = preg_replace('/^.*: /', '', $offence);
        }
        sort($names);

        $this->assertSame(
            ['mt_rand()', 'shuffle()'],
            $names,
            'Le garde doit voir les appels globaux et ignorer méthode et déclaration'
        );
    }

    /**
     * @return string[]
     */
    private function seedFiles(): array
    {
        // Parcours RÉCURSIF : un sous-répertoire de builders échapperait à un glob simple, et
        // le garde deviendrait faux sans que rien ne le signale.
        $root  = dirname(__DIR__, 3) . '/classes/Seed';
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return string[] descriptions « fichier:ligne: fonction() »
     */
    private function forbiddenCallsIn(string $path): array
    {
        $tokens = token_get_all((string)file_get_contents($path));

        // On ne garde que les tokens significatifs, en conservant les lignes.
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        $method_operators = [T_OBJECT_OPERATOR, T_DOUBLE_COLON];
        if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $method_operators[] = T_NULLSAFE_OBJECT_OPERATOR;
        }

        $offences = [];
        $count    = count($significant);
        for ($i = 0; $i < $count; $i++) {
            $token = $significant[$i];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (!in_array(strtolower($token[1]), self::FORBIDDEN, true)) {
                continue;
            }

            // Appel de méthode ($x->shuffle(), self::shuffle()) ou déclaration : légitime.
            $previous = $significant[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], $method_operators, true)) {
                continue;
            }
            if (is_array($previous) && $previous[0] === T_FUNCTION) {
                continue;
            }

            // Ce n'est une infraction que si c'est effectivement un appel.
            $next = $significant[$i + 1] ?? null;
            if ($next !== '(') {
                continue;
            }

            $offences[] = basename($path) . ':' . $token[2] . ': ' . $token[1] . '()';
        }

        return $offences;
    }
}
