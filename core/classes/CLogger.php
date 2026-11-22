<?php
/**
 * @package Hospilibre\Core
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Réimplémentation GPL (clean-room, à partir de l'interface attendue par le code
 * appelant : CError, CApp, ApplicationLog, SystemLoggingController) d'un logger
 * applicatif, en enveloppant Monolog (déjà présent dans vendor). Remplace la
 * classe OXOL retirée lors du nettoyage de licences, qui était référencée par du
 * code conservé (gestion d'erreurs notamment).
 */

namespace Ox\Core;

use Monolog\Formatter\HtmlFormatter;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Throwable;

/**
 * Logger applicatif : fine couche au-dessus de Monolog.
 *
 * Interface publique (dérivée de l'usage) :
 *   new CLogger($channel)                        canal (CHANNEL_*)
 *   ->setStreamFile($file)                       fichier de destination
 *   ->setJsonFormatter() / ->setHtmlFormatter()  format de sortie
 *   ->log($message, $context, $level)            écrit un enregistrement
 *   ::getLevels()                                [niveau_int => nom]
 *   ::getLevelsColors()                          [NOM => couleur]
 *   ::decodeContext($context)                    contexte (array) depuis un log lu
 */
class CLogger
{
    // Canaux
    public const CHANNEL_ERROR      = 'error';
    public const CHANNEL_HOSPILIBRE = 'hospilibre';
    public const CHANNEL_IMPORT     = 'import';

    // Niveaux (valeurs alignées sur Monolog pour compatibilité)
    public const LEVEL_DEBUG     = Logger::DEBUG;
    public const LEVEL_INFO      = Logger::INFO;
    public const LEVEL_NOTICE    = Logger::NOTICE;
    public const LEVEL_WARNING   = Logger::WARNING;
    public const LEVEL_ERROR     = Logger::ERROR;
    public const LEVEL_CRITICAL  = Logger::CRITICAL;
    public const LEVEL_ALERT     = Logger::ALERT;
    public const LEVEL_EMERGENCY = Logger::EMERGENCY;

    /** @var Logger */
    private $logger;

    /** @var StreamHandler|null */
    private $handler;

    /** @var \Monolog\Formatter\FormatterInterface|null */
    private $formatter;

    public function __construct(string $channel = self::CHANNEL_HOSPILIBRE)
    {
        $this->logger = new Logger($channel);
    }

    /**
     * Définit le fichier de destination des logs.
     *
     * @return $this
     */
    public function setStreamFile(string $file): self
    {
        $this->handler = new StreamHandler($file, Logger::DEBUG);
        if ($this->formatter !== null) {
            $this->handler->setFormatter($this->formatter);
        }
        $this->logger->pushHandler($this->handler);

        return $this;
    }

    /**
     * Sortie au format JSON (une ligne JSON par enregistrement).
     *
     * @return $this
     */
    public function setJsonFormatter(): self
    {
        $this->formatter = new JsonFormatter();
        if ($this->handler !== null) {
            $this->handler->setFormatter($this->formatter);
        }

        return $this;
    }

    /**
     * Sortie au format HTML.
     *
     * @return $this
     */
    public function setHtmlFormatter(): self
    {
        $this->formatter = new HtmlFormatter();
        if ($this->handler !== null) {
            $this->handler->setFormatter($this->formatter);
        }

        return $this;
    }

    /**
     * Sortie au format « ligne » (une ligne texte par enregistrement), avec un
     * gabarit compatible avec les placeholders Monolog (%datetime%, %level_name%,
     * %message%, %context%, %extra%).
     *
     * @param string|null $format     Gabarit de ligne
     * @param string|null $dateFormat Format de date
     *
     * @return $this
     */
    public function setLineFormatter(?string $format = null, ?string $dateFormat = null): self
    {
        // allowInlineLineBreaks=true, ignoreEmptyContextAndExtra=true
        $this->formatter = new LineFormatter($format, $dateFormat, true, true);
        if ($this->handler !== null) {
            $this->handler->setFormatter($this->formatter);
        }

        return $this;
    }

    /**
     * Ajoute le processeur d'introspection Monolog : enrichit `extra` avec le
     * fichier / la ligne / la classe / la fonction appelant le log.
     *
     * @return $this
     */
    public function setIntrospectionProcessor(): self
    {
        // On saute les frames internes (Monolog + ce wrapper CLogger) pour que
        // `extra` pointe sur le véritable appelant applicatif, pas sur log().
        $this->logger->pushProcessor(
            new IntrospectionProcessor(Logger::DEBUG, ['Monolog\\', 'Ox\\Core\\CLogger'])
        );

        return $this;
    }

    /**
     * Ajoute un processeur applicatif : enrichit `extra` avec le contexte
     * Hospilibre courant (identifiant de requête, utilisateur, IP). Ne doit
     * jamais lever : le contexte est renseigné au mieux.
     *
     * @return $this
     */
    public function setHospilibreProcessor(): self
    {
        $this->logger->pushProcessor(
            static function (array $record): array {
                try {
                    if (class_exists(CApp::class) && method_exists(CApp::class, 'getRequestUID')) {
                        $record['extra']['request_uid'] = CApp::getRequestUID();
                    }
                    if (class_exists(CAppUI::class) && isset(CAppUI::$user) && CAppUI::$user) {
                        $record['extra']['user_id'] = CAppUI::$user->_id ?? null;
                    }
                    $record['extra']['server_ip'] = $_SERVER['SERVER_ADDR'] ?? null;
                    $record['extra']['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
                } catch (Throwable $e) {
                    // Enrichissement best-effort : on n'altère pas la journalisation.
                }

                return $record;
            }
        );

        return $this;
    }

    /**
     * Accès au logger Monolog sous-jacent (ex. pour fermer les handlers).
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * Écrit un enregistrement. Ordre des arguments conforme à l'usage historique :
     * (message, contexte, niveau).
     *
     * @param mixed $message
     * @param mixed $context
     * @param int   $level
     *
     * @return bool
     */
    public function log($message, $context = [], int $level = self::LEVEL_INFO): bool
    {
        if (!is_array($context)) {
            $context = ($context === null) ? [] : ['data' => $context];
        }

        try {
            // Monolog 2.x : Logger::log() renvoie void (PSR-3), on renvoie donc
            // explicitement true en cas de succès.
            $this->logger->log($level, (string)$message, $context);

            return true;
        } catch (Throwable $e) {
            // Le logger ne doit jamais faire échouer l'appelant (ex. handler
            // d'erreur). En dernier recours, on retombe sur error_log.
            error_log('[CLogger] ' . (string)$message);

            return false;
        }
    }

    /**
     * Table niveau (int) => nom (minuscule).
     *
     * @return string[]
     */
    public static function getLevels(): array
    {
        return [
            self::LEVEL_DEBUG     => 'debug',
            self::LEVEL_INFO      => 'info',
            self::LEVEL_NOTICE    => 'notice',
            self::LEVEL_WARNING   => 'warning',
            self::LEVEL_ERROR     => 'error',
            self::LEVEL_CRITICAL  => 'critical',
            self::LEVEL_ALERT     => 'alert',
            self::LEVEL_EMERGENCY => 'emergency',
        ];
    }

    /**
     * Table NOM (majuscule) => couleur d'affichage.
     *
     * @return string[]
     */
    public static function getLevelsColors(): array
    {
        return [
            'DEBUG'     => '#808080',
            'INFO'      => '#468847',
            'NOTICE'    => '#3a87ad',
            'WARNING'   => '#c09853',
            'ERROR'     => '#b94a48',
            'CRITICAL'  => '#b94a48',
            'ALERT'     => '#b94a48',
            'EMERGENCY' => '#b94a48',
        ];
    }

    /**
     * Normalise le contexte d'un enregistrement relu depuis un fichier de log :
     * accepte aussi bien un tableau déjà décodé qu'une chaîne JSON.
     *
     * @param mixed $context
     *
     * @return array
     */
    public static function decodeContext($context): array
    {
        if (is_array($context)) {
            return $context;
        }
        if (is_string($context) && $context !== '') {
            $decoded = json_decode($context, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
