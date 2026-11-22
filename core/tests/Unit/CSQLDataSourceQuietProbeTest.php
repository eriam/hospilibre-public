<?php

/**
 * @package Ox\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Core\Tests\Unit;

use Ox\Core\CSQLDataSource;
use Ox\Tests\UnitTestHospilibre;

/**
 * UNE SOURCE FACULTATIVE INJOIGNABLE NE DOIT PAS TUER LE PROCESSUS.
 *
 * Signalé par un environnement de cours (déploiement multi-conteneur), en déploiement multi-conteneur.
 * includes/config_dist.php déclare hl7v2, rpps_import et presta_ssr sur « localhost » /
 * « 127.0.0.1 » — héritage du config.php d'exemple de Mediboard —, hôtes qui n'existent pas dans le
 * conteneur applicatif. ox-db:update convergeait puis mourait en fin de course :
 *
 *   [critical] User Error: SQLSTATE[HY000] [2002] No such file or directory
 *              (DSN = mysql:dbname=hl7v2;host=localhost)
 *
 * LA CAUSE N'ÉTAIT PAS L'HÔTE mais le traitement de l'échec. Les moteurs signalaient une connexion
 * ratée par « trigger_error(..., E_USER_ERROR) », et une E_USER_ERROR n'est supprimable NI par @,
 * NI par try/catch, NI par error_reporting(0) : elle arrête le script. Or
 * CSQLDataSource::get($dsn, $quiet = true) existe pour les appelants qui SONDENT une source
 * facultative, et init() porte déjà la garde « if (!$this->link && !$quiet) » — garde que
 * connect() ne laissait jamais atteindre.
 *
 * Le défaut ne dépendait donc pas de l'hôte : une base simplement ABSENTE sur le bon serveur
 * produisait le même arrêt. C'est ce que vérifie ce test, avec un hôte volontairement inexistant.
 */
class CSQLDataSourceQuietProbeTest extends UnitTestHospilibre
{
    private const DSN = 'hospilibre_test_source_injoignable';

    /**
     * Déclare une source dont la connexion échouera à coup sûr, et purge le cache de sources pour
     * que get() tente vraiment de se connecter.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // CAppUI::conf() lit le dPconfig global : c'est donc la qu'on declare la source.
        global $dPconfig;
        $dPconfig['db'][self::DSN] = [
            'dbtype' => 'mysql',
            // Hôte qui ne résout pas : l'échec est garanti sans dépendre de l'environnement.
            'dbhost' => 'hote-qui-nexiste-pas.invalid',
            'dbname' => 'base_absente',
            'dbuser' => 'personne',
            'dbpass' => 'rien',
        ];

        $this->forgetDataSource();
    }

    protected function tearDown(): void
    {
        global $dPconfig;
        unset($dPconfig['db'][self::DSN]);
        $this->forgetDataSource();

        parent::tearDown();
    }

    /**
     * get() met en cache les sources par DSN, y compris les échecs : sans purge, le second test
     * lirait le résultat du premier.
     */
    private function forgetDataSource(): void
    {
        unset(CSQLDataSource::$dataSources[self::DSN]);
    }

    /**
     * LE TEST QUI COMPTE : sonder une source injoignable rend null et laisse le processus vivant.
     *
     * Si la régression revient, ce test n'échoue pas — il TUE le processus phpunit sur une
     * E_USER_ERROR. C'est brutal, et c'est fidèle au défaut d'origine.
     */
    public function testProbingAnUnreachableSourceReturnsNullInsteadOfKillingTheProcess(): void
    {
        $datasource = CSQLDataSource::get(self::DSN, true);

        $this->assertNull(
            $datasource,
            'Une source facultative injoignable doit rendre null, pas interrompre le script.'
        );
    }

    /**
     * Se taire n'est pas oublier : le message reste lisible pour un diagnostic.
     */
    public function testTheSilencedFailureIsStillRecorded(): void
    {
        $class = CSQLDataSource::$engines['mysql'];

        /** @var CSQLDataSource $datasource */
        $datasource = new $class();
        $datasource->init(self::DSN, [], true);

        $this->assertNull($datasource->link, 'La connexion a bien échoué.');
        $this->assertNotNull(
            $datasource->quiet_connection_error,
            'Le message de l\'échec tu doit rester disponible : un diagnostic doit pouvoir le lire.'
        );
    }
}
