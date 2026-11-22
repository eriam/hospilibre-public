<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Kernel\Event;

use Exception;
use Ox\Core\Api\Exceptions\ApiException;
use Ox\Core\Api\Request\RequestFormats;
use Ox\Core\Api\Resources\Item;
use Ox\Core\Api\Serializers\ErrorSerializer;
use Ox\Core\CController;
use Ox\Core\CError;
use Ox\Core\Kernel\Exception\HttpException;
use Ox\Core\Kernel\Routing\RequestHelperTrait;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use Twig\Environment;

/**
 * Exception listener for V2 calls
 */
class ExceptionListener implements EventSubscriberInterface
{
    use RequestHelperTrait;

    private $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 100],
        ];
    }

    /**
     * On exception check if the event is supported (not in _profiler).
     * Log the query and if the app is not in dev make a response using the exception.
     *
     * @throws Exception
     */
    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $request   = $event->getRequest();

        if (!$this->supports($event)) {
            return;
        }

        // log exception
        if ($this->canLog($throwable)) {
            /** @var Exception $throwable */
            CError::logException($throwable);
        }

        // If APP_DEBUG mode let debug handler display exception tools
        if ($this->isAppDebug($request)) {
            return;
        }

        // Make Response
        $status_code = $throwable instanceof HttpExceptionInterface ?
            $throwable->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;

        $headers = $throwable instanceof HttpExceptionInterface ?
            $throwable->getHeaders() : [];

        if ($this->isRequestApi($request)) {
            $response = $this->makeApiResponse($event, $status_code, $headers);
        } else {
            $response = $this->makeGuiResponse($event, $status_code, $headers);
        }

        $event->setResponse($response);
        $event->stopPropagation();
    }

    /**
     * @param ExceptionEvent $event
     * @param string         $status_code
     * @param array          $headers
     *
     * @return Response
     * @throws ApiException
     */
    protected function makeApiResponse(
        ExceptionEvent $event,
        string $status_code,
        array $headers = []
    ): Response {
        $e      = $event->getThrowable();
        $format = (new RequestFormats($event->getRequest()))->getExpected();
        $datas  = [
            'type'    => $this->encodeType($e),
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
        ];

        $resource = new Item($datas);
        $resource->setSerializer(ErrorSerializer::class);
        $resource->setFormat($format);

        return (new CController())->renderApiResponse($resource, $status_code, $headers);
    }

    /**
     * Obfuscated exception's class
     *
     * @param $e
     *
     * @return string
     */
    private function encodeType($e): string
    {
        return base64_encode(get_class($e));
    }

    /**
     * @param ExceptionEvent $event
     * @param string         $status_code
     * @param array          $headers
     *
     * @return Response
     */
    protected function makeGuiResponse(
        ExceptionEvent $event,
        string $status_code,
        array $headers = []
    ): Response {
        /** @var Exception $e */
        $e    = $event->getThrowable();
        $body = $this->twig->render("error.html.twig", [
            'type'    => $this->encodeType($e),
            'code'    => $e->getCode(),
            'message' => $e->getMessage(),
        ]);

        return new Response($body, $status_code, $headers);
    }

    /**
     * Do not use custom exception handler for profiler requests.
     * In profiler AppListener is not supported and CAppUI::conf is not initialized.
     */
    private function supports(KernelEvent $event): bool
    {
        return !$this->isRequestProfiler($event->getRequest());
    }

    /**
     * A throwable can be logged if either :
     * - it is not a HttpException
     * - it is a HttpException with isLoggable === true
     */
    private function canLog(Throwable $throwable): bool
    {
        return !($throwable instanceof HttpException) || $throwable->isLoggable();
    }
}
