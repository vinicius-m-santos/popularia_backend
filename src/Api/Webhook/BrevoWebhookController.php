<?php

declare(strict_types=1);

namespace App\Api\Webhook;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recebe eventos de entrega da Brevo (delivered, bounced, spam, blocked, etc.).
 * O token no path é o segredo compartilhado — sem ele qualquer POST seria aceito.
 * Propósito: operacional (monitorar deliverability e hard bounces), não marketing.
 */
#[AsController]
final readonly class BrevoWebhookController
{
    private const array LOGGABLE_EVENTS = [
        'delivered',
        'soft_bounce',
        'hard_bounce',
        'spam',
        'blocked',
        'invalid_email',
        'deferred',
        'unsubscribed',
    ];

    public function __construct(
        private LoggerInterface $logger,
        #[Autowire('%env(default:app.empty_string:BREVO_WEBHOOK_SECRET)%')]
        private string $secret,
    ) {
    }

    #[Route('/webhooks/brevo/{token}', name: 'brevo_webhook', methods: ['POST'])]
    public function __invoke(Request $request, string $token): Response
    {
        if ('' === $this->secret || !hash_equals($this->secret, $token)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $event = (string) ($payload['event'] ?? 'unknown');
        if (\in_array($event, self::LOGGABLE_EVENTS, true)) {
            $this->logger->info('Brevo: evento de entrega recebido.', [
                'event' => $event,
                'message_id' => $payload['message-id'] ?? null,
            ]);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
