<?php

declare(strict_types=1);

namespace App\Sentry;

use App\Logging\RequestIdSubscriber;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Filtra eventos antes de enviar ao Sentry:
 * - descarta erros HTTP esperados (4xx de fluxo normal);
 * - adiciona request_id como tag para correlacionar com logs Railway;
 * - remove dados sensíveis adicionais que possam ter escapado.
 */
final readonly class SentryBeforeSend
{
    /**
     * Status HTTP que indicam fluxo esperado, não bugs. 5xx e 4xx incomuns passam.
     * 400 está aqui porque o próprio API Platform usa para payloads inválidos.
     */
    private const array EXPECTED_STATUSES = [400, 401, 403, 404, 405, 409, 410, 422, 429];

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof HttpExceptionInterface) {
            if (\in_array($exception->getStatusCode(), self::EXPECTED_STATUSES, true)) {
                return null;
            }
        }

        $requestId = $this->requests->getMainRequest()?->attributes->get(RequestIdSubscriber::ATTRIBUTE);
        if (\is_string($requestId)) {
            $event->setTag('request_id', $requestId);
        }

        $request = $event->getRequest();
        $request = array_intersect_key($request, array_flip(['url', 'method']));
        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = preg_replace('~[?#].*$~', '', $request['url']);
            $request['url'] = preg_replace('~/webhooks/brevo/[^/]+~', '/webhooks/brevo/[redacted]', $request['url'] ?? '');
        }
        $event->setRequest($request);
        $event->setUser(\Sentry\UserDataBag::createFromArray(array_filter(['id' => $event->getUser()?->getId(), 'ip_address' => '0.0.0.0'])));
        $event->setExtra([]);
        $event->setBreadcrumb([]);
        foreach ($event->getExceptions() as $error) {
            if (preg_match('/Doctrine|PDO|Postgre|HttpClient/', $error->getType())) {
                $error->setValue('Operational failure; sensitive details omitted.');
            }
        }

        return $event;
    }
}
