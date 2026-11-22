<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Elastic;

use MyCLabs\Enum\Enum;

/**
 * Encoding types Enum
 * Todo: Replace by Enum in PHP 8.1
 *
 * @method static static NONE()
 * @method static static UTF_8()
 * @method static static ISO_8859_1()
 */
class Encoding extends Enum
{
    private const NONE       = 'none';
    private const UTF_8      = 'UTF-8';
    private const ISO_8859_1 = 'utf-8';

    public function isNoneOrUTF8(): bool
    {
        return ($this->getValue() === self::UTF_8 || $this->getValue() === self::NONE);
    }
}
