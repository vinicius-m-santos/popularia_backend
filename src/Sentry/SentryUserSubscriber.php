<?php

declare(strict_types=1);

namespace App\Sentry;

use App\Entity\User;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Associa o usuário autenticado ao escopo do Sentry para que eventos capturados
 * indiquem qual conta foi afetada. Apenas o UUID interno é enviado — sem e-mail,
 * nome, telefone ou qualquer dado pessoal.
 */
final readonly class SentryUserSubscriber
{
    public function __construct(
        private Security $security,
    ) {
    }

    #[AsEventListener(KernelEvents::REQUEST, priority: -64)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        SentrySdk::getCurrentHub()->configureScope(static function (Scope $scope) use ($user): void {
            $scope->setUser(['id' => (string) $user->getId(), 'ip_address' => '0.0.0.0']);
        });
    }
}
