<?php

declare(strict_types=1);

namespace App\Library;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: -100)]
final class LibraryCacheListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api/v1/library') && !str_starts_with($path, '/api/v1/me/saved-documents') && !str_starts_with($path, '/api/v1/me/follow-requests')) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->remove('ETag');
        $response->headers->remove('Last-Modified');
        $response->setVary('Authorization', false);
    }
}
