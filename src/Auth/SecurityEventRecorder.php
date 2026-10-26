<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\AuthSession;
use App\Entity\SecurityEvent;
use App\Entity\User;
use App\Enum\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Grava eventos no registro de segurança (com IP e retenção limitada) e emite um resumo
 * sem IP no canal de log "security_audit", útil para alertas operacionais.
 *
 * O IP vem de Request::getClientIp(): só considera X-Forwarded-For quando a conexão vem
 * de um proxy listado em TRUSTED_PROXIES. Um cliente não consegue falsificar o IP gravado.
 */
final class SecurityEventRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
        #[Target('security_audit')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, scalar|null> $context Detalhes curtos; nunca senhas, tokens, códigos ou e-mails. */
    public function record(SecurityEventType $type, ?User $user = null, ?AuthSession $session = null, array $context = []): void
    {
        $event = new SecurityEvent(
            $type,
            $user,
            $session?->getId(),
            $this->requests->getMainRequest()?->getClientIp(),
            $context,
            $this->clock->now(),
        );
        $this->em->persist($event);
        $this->em->flush();

        $this->logger->info('security_event', [
            'event_type' => $type->value,
            'event_id' => $event->getId()->toRfc4122(),
            'user_id' => $user?->getId()->toRfc4122(),
        ] + $context);
    }
}
