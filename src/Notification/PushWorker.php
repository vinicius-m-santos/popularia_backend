<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Bounded database queue: only committed notifications are ever delivered. */
final readonly class PushWorker
{
    public function __construct(private Connection $db, private EntityManagerInterface $em, private NotificationRepository $notifications, private ExpoPushProvider $provider, private ClockInterface $clock, private LoggerInterface $logger, private ?EventReminderWorker $reminders = null)
    {
    }

    /** @return array{queued: int, sent: int, receipts: int} */
    public function run(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $result = ['queued' => 0, 'sent' => 0, 'receipts' => 0];
        // A second process exits; a dead worker automatically releases its connection lock.
        if (!$this->db->fetchOne('SELECT pg_try_advisory_lock(72910431)')) {
            return $result;
        }
        try {
            $this->em->clear();
            $this->reminders?->dispatchDue($limit);
            $result['queued'] = $this->stage($limit);
            $result['receipts'] = $this->checkReceipts($limit);
            $result['sent'] = $this->send($limit);
            // Terminal outbox rows are operational metadata, not permanent history.
            $this->db->executeStatement("DELETE FROM push_delivery WHERE id IN (SELECT id FROM push_delivery WHERE status IN ('done', 'failed', 'skipped') AND available_at < :before LIMIT 1000)", ['before' => $this->clock->now()->modify('-7 days')->format(DATE_ATOM)]);

            return $result;
        } finally {
            $this->db->executeQuery('SELECT pg_advisory_unlock(72910431)');
        }
    }

    private function stage(int $limit): int
    {
        return $this->db->transactional(function () use ($limit): int {
            $rows = $this->db->fetchAllAssociative('SELECT id, recipient_id, created_at FROM notification WHERE push_queued_at IS NULL ORDER BY id LIMIT '.$limit.' FOR UPDATE SKIP LOCKED');
            foreach ($rows as $row) {
                // Backlogs older than a day remain in-app, without waking old installations.
                if (new \DateTimeImmutable($row['created_at']) > $this->clock->now()->modify('-1 day')) {
                    $devices = $this->db->fetchAllAssociative('SELECT d.id, d.token FROM push_device d JOIN auth_session s ON s.id = d.session_id WHERE d.user_id = :user AND d.disabled_at IS NULL AND d.registered_at <= :created AND s.revoked_at IS NULL', ['user' => $row['recipient_id'], 'created' => $row['created_at']]);
                    foreach ($devices as $device) {
                        $this->db->executeStatement("INSERT INTO push_delivery (id, notification_id, device_id, token_hash, status, attempts, available_at) VALUES (:id, :notification, :device, :hash, 'pending', 0, :now) ON CONFLICT (notification_id, device_id) DO NOTHING", ['id' => Uuid::v7()->toRfc4122(), 'notification' => $row['id'], 'device' => $device['id'], 'hash' => hash('sha256', $device['token']), 'now' => $this->clock->now()->format(DATE_ATOM)]);
                    }
                }
                $this->db->executeStatement('UPDATE notification SET push_queued_at = :now WHERE id = :id', ['now' => $this->clock->now()->format(DATE_ATOM), 'id' => $row['id']]);
            }

            return count($rows);
        });
    }

    private function send(int $limit): int
    {
        $rows = $this->db->fetchAllAssociative("SELECT q.*, d.token, d.user_id, d.session_id, d.disabled_at, n.recipient_id, n.created_at, n.type FROM push_delivery q JOIN push_device d ON d.id = q.device_id JOIN notification n ON n.id = q.notification_id WHERE q.status = 'pending' AND q.available_at <= :now ORDER BY q.available_at, q.id LIMIT ".$limit, ['now' => $this->clock->now()->format(DATE_ATOM)]);
        $messages = $deliveries = $counts = $supports = [];
        foreach ($rows as $row) {
            $notification = $this->eligible($row);
            if (null === $notification) {
                $this->finish($row['id'], 'skipped');
                continue;
            }
            $category = self::category($notification->getType());
            $categories = $this->db->fetchOne('SELECT push_categories FROM notification_preference WHERE user_id = :user', ['user' => $row['recipient_id']]);
            if (false !== $categories && !in_array($category, json_decode($categories, true, flags: JSON_THROW_ON_ERROR), true)) {
                $this->finish($row['id'], 'skipped');
                continue;
            }
            // High-frequency supports have one push per recipient/5 min; every event stays in-app.
            if (NotificationType::CommentSupport === $notification->getType() && ((isset($supports[$row['recipient_id']]) && $supports[$row['recipient_id']] !== $row['notification_id']) || $this->db->fetchOne("SELECT 1 FROM push_delivery q JOIN notification n ON n.id = q.notification_id WHERE n.recipient_id = :user AND n.type = 'comment_support' AND q.notification_id <> :notification AND q.status IN ('ticket', 'done') AND n.created_at > :since LIMIT 1", ['user' => $row['recipient_id'], 'notification' => $row['notification_id'], 'since' => $this->clock->now()->modify('-5 minutes')->format(DATE_ATOM)]))) {
                $this->finish($row['id'], 'skipped');
                continue;
            }
            if (NotificationType::CommentSupport === $notification->getType()) {
                $supports[$row['recipient_id']] = $row['notification_id'];
            }
            $counts[$row['recipient_id']] ??= $this->notifications->countUnread($notification->getRecipient());
            $messages[] = [
                'to' => $row['token'], 'title' => 'Popularia', 'body' => self::body($notification->getType()),
                'data' => ['notificationId' => $row['notification_id'], 'recipientId' => $row['recipient_id']],
                'sound' => 'default', 'channelId' => 'social', 'badge' => $counts[$row['recipient_id']], 'ttl' => 3600,
            ];
            $deliveries[] = $row;
        }
        if ([] === $messages) {
            return 0;
        }
        try {
            $tickets = $this->provider->send($messages);
            foreach ($deliveries as $i => $row) {
                $ticket = $tickets[$i];
                if ('ok' === ($ticket['status'] ?? null) && is_string($ticket['id'] ?? null) && strlen($ticket['id']) <= 100) {
                    $this->db->executeStatement("UPDATE push_delivery SET status = 'ticket', ticket = :ticket, attempts = attempts + 1, available_at = :later WHERE id = :id", ['ticket' => $ticket['id'], 'later' => $this->clock->now()->modify('+15 minutes')->format(DATE_ATOM), 'id' => $row['id']]);
                } else {
                    $this->failure($row, $this->errorName($ticket), false);
                }
            }
        } catch (\RuntimeException $error) {
            foreach ($deliveries as $row) {
                $this->failure($row, $error->getMessage(), false);
            }
        }

        return count($deliveries);
    }

    /** @param array<string, mixed> $row */
    private function eligible(array $row): ?Notification
    {
        if (null !== $row['disabled_at'] || $row['user_id'] !== $row['recipient_id'] || !hash_equals($row['token_hash'], hash('sha256', $row['token'])) || new \DateTimeImmutable($row['created_at']) <= $this->clock->now()->modify('-1 day')) {
            return null;
        }
        if (!$this->db->fetchOne('SELECT 1 FROM auth_session s WHERE s.id = :session AND s.revoked_at IS NULL AND EXISTS (SELECT 1 FROM refresh_token r WHERE r.session_id = s.id AND r.rotated_at IS NULL AND r.expires_at > :now)', ['session' => $row['session_id'], 'now' => $this->clock->now()->format(DATE_ATOM)])) {
            return null;
        }
        $user = $this->em->find(User::class, Uuid::fromString($row['recipient_id']));
        if (null === $user || !$user->isActive()) {
            return null;
        }
        $notification = $this->notifications->findVisible($user, $row['notification_id']);

        return null === $notification?->getReadAt() ? $notification : null;
    }

    private function checkReceipts(int $limit): int
    {
        $rows = $this->db->fetchAllAssociative("SELECT * FROM push_delivery WHERE status = 'ticket' AND available_at <= :now ORDER BY available_at LIMIT ".$limit, ['now' => $this->clock->now()->format(DATE_ATOM)]);
        if ([] === $rows) {
            return 0;
        }
        try {
            $receipts = $this->provider->receipts(array_column($rows, 'ticket'));
            foreach ($rows as $row) {
                $receipt = $receipts[$row['ticket']] ?? null;
                if (null === $receipt) {
                    $this->receiptRetry($row);
                } elseif ('ok' === ($receipt['status'] ?? null)) {
                    $this->finish($row['id'], 'done');
                } else {
                    $this->failure($row, $this->errorName($receipt), true);
                }
            }
        } catch (\RuntimeException) {
            foreach ($rows as $row) {
                $this->receiptRetry($row);
            }
            $this->logger->error('Push receipt provider unavailable.');
        }

        return count($rows);
    }

    /** @param array<string, mixed> $row */
    private function receiptRetry(array $row): void
    {
        $this->db->executeStatement('UPDATE push_delivery SET status = :status, attempts = attempts + 1, available_at = :later, error_code = :error WHERE id = :id', ['status' => $row['attempts'] >= 16 ? 'failed' : 'ticket', 'later' => $this->clock->now()->modify('+1 hour')->format(DATE_ATOM), 'error' => 'ReceiptUnavailable', 'id' => $row['id']]);
    }

    /** @param array<string, mixed> $row */
    private function failure(array $row, string $error, bool $receipt): void
    {
        $allowed = ['DeviceNotRegistered', 'MessageTooBig', 'MessageRateExceeded', 'MismatchSenderId', 'InvalidCredentials', 'ProviderConfiguration', 'ProviderUnavailable', 'InvalidProviderResponse'];
        $error = in_array($error, $allowed, true) ? $error : 'UnknownProviderError';
        if ('DeviceNotRegistered' === $error) {
            // A late receipt for a previous registration must not disable a rotated/transferred token.
            $this->db->executeStatement('UPDATE push_device SET disabled_at = :now WHERE id = :id AND token = :token AND user_id = :user', ['now' => $this->clock->now()->format(DATE_ATOM), 'id' => $row['device_id'], 'token' => $row['token'] ?? $this->tokenForHash($row), 'user' => $row['recipient_id'] ?? $this->recipientForDelivery($row)]);
        }
        $retry = !$receipt && in_array($error, ['ProviderUnavailable', 'InvalidProviderResponse', 'MessageRateExceeded'], true) && $row['attempts'] < 4;
        $this->db->executeStatement('UPDATE push_delivery SET status = :status, error_code = :error, attempts = attempts + 1, available_at = :later WHERE id = :id', ['status' => $retry ? 'pending' : 'failed', 'error' => $error, 'later' => $this->clock->now()->modify('+'.(30 * 2 ** min(5, $row['attempts'])).' seconds')->format(DATE_ATOM), 'id' => $row['id']]);
        $this->logger->error('Push delivery failed.', ['delivery_id' => $row['id'], 'provider_error' => $error, 'retry' => $retry]);
    }

    /** @param array<string, mixed> $row */
    private function tokenForHash(array $row): string
    {
        $token = $this->db->fetchOne('SELECT token FROM push_device WHERE id = :id', ['id' => $row['device_id']]);

        return is_string($token) && hash_equals($row['token_hash'], hash('sha256', $token)) ? $token : '';
    }

    /** @param array<string, mixed> $row */
    private function recipientForDelivery(array $row): string
    {
        return (string) $this->db->fetchOne('SELECT recipient_id FROM notification WHERE id = :id', ['id' => $row['notification_id']]);
    }

    private function finish(string $id, string $status): void
    {
        $this->db->executeStatement('UPDATE push_delivery SET status = :status, available_at = :now WHERE id = :id', ['status' => $status, 'now' => $this->clock->now()->format(DATE_ATOM), 'id' => $id]);
    }

    /** @param array<string, mixed> $result */
    private function errorName(array $result): string
    {
        return is_string($result['details']['error'] ?? null) ? $result['details']['error'] : 'InvalidProviderResponse';
    }

    private static function body(NotificationType $type): string
    {
        return match ($type) {
            NotificationType::Follow => 'Você tem um novo seguidor.',
            NotificationType::Comment => 'Sua publicação recebeu um comentário.',
            NotificationType::Reply, NotificationType::SquareReply => 'Você recebeu uma resposta.',
            NotificationType::MentionPost => 'Você foi mencionado em uma publicação.',
            NotificationType::MentionComment => 'Você foi mencionado em um comentário.',
            NotificationType::CommentSupport => 'Seu comentário recebeu um apoio.',
            NotificationType::JoinRequested => 'Há uma solicitação de participação para você analisar.',
            NotificationType::EventReminder => 'Você tem um lembrete de atividade.',
            NotificationType::EventCancelled => 'Uma atividade que você acompanha foi cancelada.',
            NotificationType::EventRescheduled, NotificationType::EventPlaceChanged, NotificationType::EventLinkChanged, NotificationType::EventUpdated => 'Uma atividade que você acompanha foi atualizada.',
            default => 'Você tem uma atualização de participação em comuna.',
        };
    }

    private static function category(NotificationType $type): string
    {
        return match ($type) {
            NotificationType::Follow => 'follows',
            NotificationType::Comment, NotificationType::Reply, NotificationType::SquareReply, NotificationType::CommentSupport, NotificationType::MentionPost, NotificationType::MentionComment => 'interactions',
            default => 'comunas',
        };
    }
}
