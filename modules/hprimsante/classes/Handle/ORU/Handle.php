<?php

/**
 * @package Hospilibre\Hprimsante
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Hprimsante\Handle\ORU;

use Ox\Core\CMbArray;
use Ox\Core\CStoredObject;
use Ox\Interop\Eai\CInteropSender;
use Ox\Interop\Hprimsante\CHPrimSanteRecordORU;
use Ox\Interop\Hprimsante\Exceptions\CHPrimSanteExceptionError;
use Ox\Interop\Hprimsante\Exceptions\CHPrimSanteExceptionWarning;
use Hospilibre\Patients\CPatient;

abstract class Handle
{
    /** @var string */
    public const KEY_OBR_NODE = 'OBR_node';

    /** @var string */
    public const KEY_OBR_INDEX = 'OBR_index';

    /** @var string */
    public const KEY_OBX_NODE = 'OBX_node';

    /** @var string */
    public const KEY_OBX_INDEX = 'OBX_index';

    /** @var CHPrimSanteRecordORU */
    protected $message;

    /** @var array */
    protected $params;

    /**
     * Construct of Handle
     *
     * @param CHPrimSanteRecordORU $message
     */
    public function __construct(CHPrimSanteRecordORU $message)
    {
        $this->message = $message;
    }

    /**
     * Handle message information
     *
     * @param array $params
     *
     * @return void
     */
    abstract public function handle(array $params): void;

    /**
     * Return patient from message
     *
     * @return CPatient|null
     */
    public function getPatient(): ?CPatient
    {
        return $this->message->patient;
    }

    /**
     * Return sender interop from message
     *
     * @return CInteropSender
     */
    public function getSender(): CInteropSender
    {
        return $this->message->_ref_sender;
    }

    /**
     * @return CStoredObject|null
     */
    public function getTarget(): ?CStoredObject
    {
        return $this->message->target;
    }

    /**
     * @return string
     */
    public function getIndex(): string
    {
        $index_obr = CMbArray::get($this->params, self::KEY_OBR_INDEX, 0) + 1;
        $index_obx = CMbArray::get($this->params, self::KEY_OBX_INDEX, 0) + 1;

        return array_key_exists(self::KEY_OBX_INDEX, $this->params) ? "OBR[$index_obr].OBX[$index_obx]" : $index_obr;
    }

    /**
     * Make exception error
     *
     * @param string      $type_error
     * @param string      $code_error
     * @param string|null $field
     * @param string|null $comment
     * @param array       $address
     *
     * @return CHPrimSanteExceptionError
     */
    public function makeError(
        string $type_error,
        string $code_error,
        ?string $field = null,
        ?string $comment = null,
        array $address = []
    ): CHPrimSanteExceptionError {
        if (!$field) {
            $field = "$type_error.1";
        }

        if (!$address) {
            $address = [$type_error, $this->getIndex(), $this->message->identifier_patient];
        }

        return new CHPrimSanteExceptionError($type_error, $code_error, $address, $field, $comment);
    }

    /**
     * Make exception warning
     *
     * @param string      $type_error
     * @param string      $code_error
     * @param string|null $field
     * @param string|null $comment
     * @param array       $address
     *
     * @return CHPrimSanteExceptionWarning
     */
    public function makeWarning(
        string $type_error,
        string $code_error,
        ?string $field = null,
        ?string $comment = null,
        array $address = []
    ): CHPrimSanteExceptionWarning {
        if (!$field) {
            $field = "$type_error.1";
        }

        if (!$address) {
            $address = [$type_error, $this->getIndex(), $this->message->identifier_patient];
        }

        return new CHPrimSanteExceptionWarning($type_error, $code_error, $address, $field, $comment);
    }

    /**
     * @return bool
     */
    protected function isModeSAS(): bool
    {
        return (bool) $this->getSender()->_configs['mode_sas'];
    }
}
