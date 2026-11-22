<?php

/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Tests\Unit;

use Ox\Cli\CConfigDbEnv;
use Ox\Tests\UnitTestHospilibre;

class CConfigDbEnvTest extends UnitTestHospilibre
{
    private const CONFIG = <<<'PHP'
<?php
$dPconfig['db']['std']['dbtype'] = 'mysql';
$dPconfig['db']['std']['dbhost'] = 'db';
$dPconfig['db']['std']['dbname'] = '';
$dPconfig['db']['std']['dbuser'] = '';
$dPconfig['db']['std']['dbpass'] = '';
$dPconfig['db']['hl7v2']['dbhost'] = 'localhost';
$dPconfig['db']['hl7v2']['dbname'] = 'hl7v2';
$dPconfig['db']['hl7v2']['dbuser'] = '';
$dPconfig['db']['hl7v2']['dbpass'] = 'hl7v2';
$dPconfig['db']['ccam']['dbhost'] = 'db';
$dPconfig['db']['ccam']['dbname'] = '';
$dPconfig['db']['ccam']['dbuser'] = '';
$dPconfig['db']['ccam']['dbpass'] = '';
$dPconfig['admin']['CUser']['strong_password'] = '1';
PHP;

    private function load(string $php): array
    {
        $dPconfig = [];
        eval('?>' . $php);

        return $dPconfig;
    }

    public function testSourcesDuServeurDockerRecoiventLesIdentifiants(): void
    {
        $out = $this->load(CConfigDbEnv::apply(self::CONFIG, 'db', 'hospilibre', 'hl', "p'ass"));

        foreach (['std', 'ccam'] as $ds) {
            $this->assertSame('db', $out['db'][$ds]['dbhost'], $ds);
            $this->assertSame('hospilibre', $out['db'][$ds]['dbname'], $ds);
            $this->assertSame('hl', $out['db'][$ds]['dbuser'], $ds);
            $this->assertSame("p'ass", $out['db'][$ds]['dbpass'], $ds); // apostrophe échappée
        }
        // Une source d'un autre hôte n'est pas touchée.
        $this->assertSame('hl7v2', $out['db']['hl7v2']['dbname']);
        $this->assertSame('hl7v2', $out['db']['hl7v2']['dbpass']);
        // Le reste de la configuration est intact.
        $this->assertSame('1', $out['admin']['CUser']['strong_password']);
    }

    public function testSourceAvecSaPropreBaseGardeSonNom(): void
    {
        // ox-install:externals --declare ajoute INSEE/ccamV2/cim10 sur le MÊME hôte, chacun dans sa
        // propre base. Au redémarrage suivant, l'injection ne doit pas les renvoyer vers la base
        // applicative (constaté : « Table 'hospilibre.pays' doesn't exist », semis impossible).
        $config = self::CONFIG . "\n\$dPconfig['db']['INSEE']['dbhost'] = 'db';\n"
            . "\$dPconfig['db']['INSEE']['dbname'] = 'insee';\n"
            . "\$dPconfig['db']['INSEE']['dbuser'] = 'ancien';\n"
            . "\$dPconfig['db']['INSEE']['dbpass'] = 'ancien';\n";
        $out = $this->load(CConfigDbEnv::apply($config, 'db', 'hospilibre', 'hl', 'x'));

        $this->assertSame('insee', $out['db']['INSEE']['dbname']);
        $this->assertSame('hl', $out['db']['INSEE']['dbuser']);
        $this->assertSame('x', $out['db']['INSEE']['dbpass']);
        // std suit toujours DB_NAME, même s'il portait un autre nom.
        $renamed = str_replace("['std']['dbname'] = ''", "['std']['dbname'] = 'autre'", self::CONFIG);
        $this->assertSame('hospilibre', $this->load(CConfigDbEnv::apply($renamed, 'db', 'hospilibre', 'hl', 'x'))['db']['std']['dbname']);
    }

    public function testIdempotent(): void
    {
        $once  = CConfigDbEnv::apply(self::CONFIG, 'db', 'hospilibre', 'hl', 'x');
        $twice = CConfigDbEnv::apply($once, 'db', 'hospilibre', 'hl', 'x');
        $this->assertSame($once, $twice);
    }

    public function testStdAbsenteEstAjoutee(): void
    {
        $out = $this->load(CConfigDbEnv::apply("<?php\n\$dPconfig['x']['y'] = '1';\n", 'db', 'n', 'u', 'p'));
        $this->assertSame(
            ['dbtype' => 'mysql', 'dbhost' => 'db', 'dbname' => 'n', 'dbuser' => 'u', 'dbpass' => 'p'],
            $out['db']['std']
        );
    }
}
