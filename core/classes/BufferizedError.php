<?php

/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core;

use JsonSerializable;

/**
 * A bufferiezed error ready to be display
 */
class BufferizedError implements JsonSerializable
{
    public const FIELD_CODE           = 'code';
    public const FIELD_MICROTIME      = 'microtime';
    public const FIELD_USER_ID        = 'user_id';
    public const FIELD_SERVER_IP      = 'server_ip';
    public const FIELD_TIME           = 'time';
    public const FIELD_REQUEST_UID    = 'request_uid';
    public const FIELD_TYPE           = 'type';
    public const FIELD_TEXT           = 'text';
    public const FIELD_FILE           = 'file';
    public const FIELD_LINE           = 'line';
    public const FIELD_SIGNATURE_HASH = 'signature_hash';
    public const FIELD_DATA           = 'data';


    /** @var string */
    private $code;

    /** @var string */
    private $microtime;

    /** @var int */
    private $user_id;

    /** @var string */
    private $server_ip;

    /** @var string */
    private $time;

    /** @var string */
    private $request_uid;

    /** @var string */
    private $type;

    /** @var string */
    private $text;

    /** @var string */
    private $file;

    /** @var int */
    private $line;

    /** @var string */
    private $signature_hash;

    /** @var array */
    private $data = [];

    /** @var string */
    private static $user_view;

    /**
     * Disable constructor. Use self::createFromArray
     */
    private function __construct()
    {
    }

    /**
     * Create the object using an indexed array.
     */
    public static function createFromArray(array $data): self
    {
        $instance = new self();

        $instance->code           = $data[self::FIELD_CODE] ?? null;
        $instance->microtime      = $data[self::FIELD_MICROTIME] ?? null;
        $instance->user_id        = $data[self::FIELD_USER_ID] ?? null;
        $instance->server_ip      = $data[self::FIELD_SERVER_IP] ?? null;
        $instance->time           = $data[self::FIELD_TIME] ?? null;
        $instance->request_uid    = $data[self::FIELD_REQUEST_UID] ?? null;
        $instance->type           = $data[self::FIELD_TYPE] ?? null;
        $instance->text           = $data[self::FIELD_TEXT] ?? null;
        $instance->file           = $data[self::FIELD_FILE] ?? null;
        $instance->line           = $data[self::FIELD_LINE] ?? null;
        $instance->signature_hash = $data[self::FIELD_SIGNATURE_HASH] ?? null;
        $instance->data           = $data[self::FIELD_DATA] ?? [];


        return $instance;
    }

    /**
     * Simplified array ready to be json_encode
     *
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            self::FIELD_USER_ID => $this->user_id,
            self::FIELD_TIME    => $this->time,
            self::FIELD_TYPE    => $this->type,
            self::FIELD_TEXT    => mb_convert_encoding($this->text, 'UTF-8', 'ISO-8859-1'),
            self::FIELD_FILE    => $this->file,
            self::FIELD_LINE    => $this->line,
        ];
    }

    /**
     * Return an array representation of the error.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            self::FIELD_MICROTIME      => $this->microtime,
            self::FIELD_USER_ID        => $this->user_id,
            self::FIELD_SERVER_IP      => $this->server_ip,
            self::FIELD_TIME           => $this->time,
            self::FIELD_REQUEST_UID    => $this->request_uid,
            self::FIELD_TYPE           => $this->type,
            self::FIELD_TEXT           => mb_convert_encoding($this->text, 'UTF-8', 'ISO-8859-1'),
            self::FIELD_FILE           => $this->file,
            self::FIELD_LINE           => $this->line,
            self::FIELD_SIGNATURE_HASH => $this->signature_hash,
            self::FIELD_DATA           => $this->data,
        ];
    }

    /**
     * String serialization for display purpose.
     */
    public function __toString(): string
    {
        $html_class = str_replace('big-', 'small-', CError::$_classes[$this->code] ?? null);
        $log        = "\n\n<div class='$html_class'>";

        if ($this->user_id) {
            $log .= "\n<strong>User: </strong>{$this->getUserView()} ({$this->user_id})";
        }

        $text = CMbString::purifyHTML($this->text);

        $log .= "<strong>Time: </strong>{$this->time} <strong>Type: </strong>{$this->type} <strong>Text: </strong>{$text} ";
        $log .= "<strong>File: </strong>{$this->file}:{$this->line}";
        $log .= "</div>";

        //return $log;
        return "";
    }

    /**
     * Get the user view if the user exists.
     * Only one user can be retrieved by call because it's the connected user.
     */
    private function getUserView(): ?string
    {
        if (!self::$user_view && class_exists(CAppUI::class, false) && CAppUI::$user) {
            $user = CAppUI::$user;
            if ($user->_id) {
                self::$user_view = $user->_view;
            }
        }

        return self::$user_view;
    }
}
