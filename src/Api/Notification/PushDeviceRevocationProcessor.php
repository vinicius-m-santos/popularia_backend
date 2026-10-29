<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\RateLimitGuard;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<PushDeviceRevocation, null> */
final readonly class PushDeviceRevocationProcessor implements ProcessorInterface
{
    public function __construct(private Connection $db, private ClockInterface $clock, private RequestStack $requests, private RateLimitGuard $limits)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->limits->consume('push_revocation', $this->requests->getCurrentRequest()?->getClientIp() ?? 'unknown');
        $id = (string) ($uriVariables['id'] ?? '');
        if (Uuid::isValid($id)) {
            $this->db->executeStatement('UPDATE push_device SET disabled_at = :now WHERE id = :id AND revocation_hash = :hash', ['now' => $this->clock->now()->format(DATE_ATOM), 'id' => $id, 'hash' => hash('sha256', $data->revocationSecret)]);
        }

        // Constant outcome; never reveals whether a device exists or whether the secret matched.
        return null;
    }
}
