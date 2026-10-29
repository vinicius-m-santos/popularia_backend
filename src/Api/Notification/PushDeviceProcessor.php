<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Security\CurrentSession;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<PushDeviceInput, PushDeviceView|null> */
final readonly class PushDeviceProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $user, private CurrentSession $session, private Connection $db, private ClockInterface $clock, private RateLimitGuard $limits)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?PushDeviceView
    {
        $id = (string) ($uriVariables['id'] ?? '');
        if (!Uuid::isValid($id)) {
            throw ApiProblem::badRequest('/errors/invalid-device', 'Identificador de instalação inválido.');
        }
        $user = $this->user->get()->getId()->toRfc4122();
        $now = $this->clock->now()->format(DATE_ATOM);
        if ($operation instanceof Delete) {
            $this->db->executeStatement('UPDATE push_device SET disabled_at = :now WHERE id = :id AND user_id = :user', ['now' => $now, 'id' => $id, 'user' => $user]);

            return null;
        }
        $this->limits->consume('push_registration', $user);
        $session = $this->session->get() ?? throw ApiProblem::unauthorized();
        $this->db->transactional(function () use ($id, $user, $session, $data, $now): void {
            // Token transfer and registration are serialized, including account changes on one phone.
            $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:token))', ['token' => $data->token]);
            $this->db->executeQuery('SELECT id FROM app_user WHERE id = :user FOR NO KEY UPDATE', ['user' => $user]);
            if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM push_device d JOIN auth_session s ON s.id = d.session_id WHERE d.user_id = :user AND d.disabled_at IS NULL AND d.id <> :id AND s.revoked_at IS NULL AND EXISTS (SELECT 1 FROM refresh_token r WHERE r.session_id = s.id AND r.rotated_at IS NULL AND r.expires_at > CURRENT_TIMESTAMP)', ['user' => $user, 'id' => $id]) >= 20) {
                throw ApiProblem::conflict('/errors/device-limit', 'O limite de aparelhos com push foi atingido.');
            }
            $existing = $this->db->fetchAssociative('SELECT user_id, token FROM push_device WHERE id = :id FOR UPDATE', ['id' => $id]);
            if (false !== $existing && $existing['user_id'] !== $user && !hash_equals($existing['token'], $data->token)) {
                throw ApiProblem::notFound();
            }
            $this->db->executeStatement('DELETE FROM push_device WHERE token = :token AND id <> :id', ['token' => $data->token, 'id' => $id]);
            $this->db->executeStatement('INSERT INTO push_device (id, user_id, session_id, token, platform, updated_at, registered_at, revocation_hash) VALUES (:id, :user, :session, :token, :platform, :now, :now, :revocation) ON CONFLICT (id) DO UPDATE SET user_id = EXCLUDED.user_id, session_id = EXCLUDED.session_id, token = EXCLUDED.token, platform = EXCLUDED.platform, registered_at = CASE WHEN push_device.session_id = EXCLUDED.session_id AND push_device.token = EXCLUDED.token THEN push_device.registered_at ELSE EXCLUDED.registered_at END, updated_at = EXCLUDED.updated_at, disabled_at = NULL, revocation_hash = EXCLUDED.revocation_hash', ['id' => $id, 'user' => $user, 'session' => $session->getId()->toRfc4122(), 'token' => $data->token, 'platform' => $data->platform, 'now' => $now, 'revocation' => hash('sha256', $data->revocationSecret)]);
        });

        return new PushDeviceView($id);
    }
}
