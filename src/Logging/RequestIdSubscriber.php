<?php

declare(strict_types=1);

namespace App\Logging;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Dá a cada requisição um identificador (X-Request-Id), devolvido na resposta e presente em
 * todos os logs dela. Aceita o do proxy se tiver formato seguro; senão gera um novo.
 */
final class RequestIdSubscriber
{
    public const string ATTRIBUTE = '_request_id';

    #[AsEventListener(KernelEvents::REQUEST, priority: 4096)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $incoming = $event->getRequest()->headers->get('X-Request-Id', '');
        $id = 1 === preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : Uuid::v4()->toRfc4122();
        $event->getRequest()->attributes->set(self::ATTRIBUTE, $id);
    }

    #[AsEventListener(KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $id = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if ($event->isMainRequest() && \is_string($id)) {
            $event->getResponse()->headers->set('X-Request-Id', $id);
        }
    }
}
