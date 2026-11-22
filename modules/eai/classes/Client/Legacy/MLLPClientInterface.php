<?php

/**
 * @package Hospilibre\Core
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Eai\Client\Legacy;

use Ox\Core\Contracts\Client\ClientInterface;

interface MLLPClientInterface extends ClientInterface
{
    public function send(): void;
    public function receive(): string;
}
