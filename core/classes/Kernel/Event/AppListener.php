<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Kernel\Event;

use Exception;
use Ox\Cli\Console\IAppDependantCommand;
use Ox\Core\CApp;
use Ox\Core\Kernel\Routing\RequestHelperTrait;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class AppListener implements EventSubscriberInterface
{
    use RequestHelperTrait;

    /** @var CApp */
    private $app;

    public function __construct(ContainerInterface $container)
    {
        $this->app = CApp::getInstance();
        $this->app->setContainer($container);
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => [
                ['onRequest', 100],
                ['onRouting', 31], // SF Route listener is 32
            ],
            KernelEvents::RESPONSE  => ['onResponse', 100],
            KernelEvents::TERMINATE => ['onTerminate', 90],
            ConsoleEvents::COMMAND  => ['onCommand', 100],
        ];
    }

    public function onCommand(ConsoleCommandEvent $command_event)
    {
        $cmd = $command_event->getCommand();

        if ($cmd instanceof IAppDependantCommand) {
            $this->app->startForCli($cmd->getName());
        }
    }

    /**
     * @param RequestEvent $event
     *
     * @return void
     * @throws Exception
     */
    public function onRequest(RequestEvent $event)
    {
        if (!$this->supports($event)) {
            return;
        }

        $request = $event->getRequest();
        $return  = $this->app->startForRequest($request);

        if ($return instanceof Response) {
            $event->setResponse($return);
        }
    }


    /**
     * @param RequestEvent $event
     *
     * @return bool
     */
    private function supports(RequestEvent $event)
    {
        if (!$event->isMainRequest()) {
            return false;
        }

        // ignore routes _wdt _profiler _error
        if (!$this->isRequestApiOrGui($event->getRequest())) {
            return false;
        }

        return true;
    }

    /**
     * @param RequestEvent $event
     *
     * @throws Exception
     */
    public function onRouting(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $this->app->setPublic($request);

        //        // TODO [public] appfine refactoring for public routes
        //        if ($this->isRequestPublic($request)) {
        //            // $this->app::initPublicEnvironment();
        //        }
    }

    /**
     * @param ResponseEvent $event
     *
     * @return void
     * @throws Exception
     */
    public function onResponse(ResponseEvent $event)
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->app->isStarted()) {
            $event->stopPropagation();

            return;
        }

        $request = $event->getRequest();
        $this->app->stop($request);

        //        if ($this->isRequestPublic($request)) {
        //            // TODO [public] appfine refactoring for public routes
        //            // $this->app::stopPublicEnvironment();
        //        }

    }

    /**
     * @param TerminateEvent $event
     *
     * @throws Exception
     */
    public function onTerminate(TerminateEvent $event)
    {
        if (!$this->app->isStarted()) {
            $event->stopPropagation();

            return;
        }

        $request = $event->getRequest();
        $this->app->terminate($request);
    }
}
