<?php
/**
 * @package Hospilibre\Cli
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Cli\Console;

use Ox\Cli\Request;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Request command
 */
class RequestToken extends Request {
  protected $token;

  /**
   * @see parent::configure()
   */
  protected function configure() {
    parent::configure();

    $this
      ->setName('ox-request:token')
      ->setAliases(['request:token'])
      ->setDescription('Request a Hospilibre controller with a token')
      ->addArgument(
        'token',
        InputArgument::REQUIRED,
        'Token'
      );
  }

  function getParameters(InputInterface $input) {
    parent::getParameters($input);

    $this->token   = $input->getArgument('token');
  }

  /**
   * @inheritdoc
   */
  protected function getHospilibreView() {
    return $this->getUrl();
  }

  protected function getUrl() {
    return sprintf(
      "%s/index.php?token=%s",
      $this->url,
      $this->token
    );
  }
}
