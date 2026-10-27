<?php

declare(strict_types=1);

namespace App\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;

/**
 * Em staging, descarta e-mails cujos destinatários não estejam na allowlist.
 * Ativa quando MAILER_STAGING_ALLOWLIST está definida (lista separada por vírgulas).
 * Garante que staging não dispare e-mails reais para usuários durante testes.
 */
final readonly class StagingMailGuard
{
    /** @var list<string> */
    private array $allowed;

    public function __construct(
        private LoggerInterface $logger,
        string $allowlist,
    ) {
        $this->allowed = '' !== $allowlist
            ? array_map('strtolower', array_map('trim', explode(',', $allowlist)))
            : [];
    }

    #[AsEventListener]
    public function onMessage(MessageEvent $event): void
    {
        if ([] === $this->allowed) {
            return;
        }

        $envelope = $event->getEnvelope();
        $allowed = [];
        $dropped = [];

        foreach ($envelope->getRecipients() as $recipient) {
            if (\in_array(strtolower($recipient->getAddress()), $this->allowed, true)) {
                $allowed[] = $recipient;
            } else {
                $dropped[] = $recipient->getAddress();
            }
        }

        if ([] !== $dropped) {
            $this->logger->info('StagingMailGuard: e-mail descartado (destinatário fora da allowlist).', ['dropped' => $dropped]);
        }

        if ([] === $allowed) {
            $event->reject();
        }
    }
}
