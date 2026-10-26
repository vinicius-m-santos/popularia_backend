<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erros em /api/ que acontecem antes de chegar a uma operação do API Platform (rota
 * inexistente, método não permitido) também saem como problem+json, sem página HTML
 * nem detalhes internos. Os erros das operações são tratados pelo próprio API Platform.
 */
final class ApiFallbackErrorListener
{
    #[AsEventListener(KernelEvents::EXCEPTION, priority: -64)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/') || $request->attributes->has('_api_operation') || $event->hasResponse()) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $allow = $exception instanceof HttpExceptionInterface ? (string) ($exception->getHeaders()['Allow'] ?? '') : '';
        $problem = match (true) {
            $exception instanceof ApiProblem => $exception,
            404 === $status => ApiProblem::notFound('Endereço não encontrado.'),
            405 === $status => new ApiProblem(405, '/errors/method-not-allowed', 'Método não permitido.', null, ['Allow' => $allow]),
            $status < 500 => new ApiProblem($status, '/errors/'.$status, 'Requisição inválida.'),
            // 5xx: segue para o tratamento padrão (que não mostra detalhes em produção) e fica no log.
            default => null,
        };

        if (null !== $problem) {
            $event->setResponse($problem->toResponse());
        }
    }
}
