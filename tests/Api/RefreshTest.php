<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AuthSession;
use App\Entity\SecurityEvent;
use App\Enum\SecurityEventType;
use App\Tests\ApiTestCase;

final class RefreshTest extends ApiTestCase
{
    public function testRefreshRotatesBothTokens(): void
    {
        $this->createUser('camila');
        $first = $this->login('camila@exemplo.org');

        $second = $this->request('POST', '/auth/refresh', ['refreshToken' => $first['refreshToken']]);

        $this->assertStatus(200);
        self::assertSame($first['sessionId'], $second['sessionId']);
        self::assertNotSame($first['refreshToken'], $second['refreshToken']);
        self::assertNotSame($first['accessToken'], $second['accessToken']);

        // O access token anterior deixa de valer na hora; o novo funciona.
        $this->request('GET', '/me', token: $first['accessToken']);
        $this->assertStatus(401);
        $this->request('GET', '/me', token: (string) $second['accessToken']);
        $this->assertStatus(200);
    }

    public function testReusingRotatedRefreshTokenRevokesTheSession(): void
    {
        $user = $this->createUser('camila');
        $first = $this->login('camila@exemplo.org');
        $second = $this->request('POST', '/auth/refresh', ['refreshToken' => $first['refreshToken']]);

        // Alguém reapresenta o token já girado (por exemplo, uma cópia roubada).
        $body = $this->request('POST', '/auth/refresh', ['refreshToken' => $first['refreshToken']]);
        $this->assertStatus(401);
        self::assertSame('/errors/invalid-refresh-token', $body['type']);

        // A sessão inteira cai: nem o token mais novo nem o access token atual valem mais.
        $this->request('POST', '/auth/refresh', ['refreshToken' => $second['refreshToken']]);
        $this->assertStatus(401);
        $this->request('GET', '/me', token: (string) $second['accessToken']);
        $this->assertStatus(401);

        $session = $this->fresh(AuthSession::class, $first['sessionId']);
        self::assertSame('refresh_token_reuse', $session->getRevokedReason());
        $event = $this->em()->getRepository(SecurityEvent::class)->findOneBy(['type' => SecurityEventType::RefreshTokenReuseDetected]);
        self::assertNotNull($event);
        self::assertSame($user->getId()->toRfc4122(), $event->getUser()?->getId()->toRfc4122());
        self::assertSame($first['sessionId'], $event->getSessionId()?->toRfc4122());
        self::assertNotNull($event->getIpAddress());
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $this->createUser('camila');
        $tokens = $this->login('camila@exemplo.org');

        self::mockTime('+31 days');
        $this->request('POST', '/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);

        $this->assertStatus(401);
    }

    public function testRevokedSessionCannotRefresh(): void
    {
        $this->createUser('camila');
        $tokens = $this->login('camila@exemplo.org');
        $this->request('POST', '/auth/logout', token: $tokens['accessToken']);
        $this->assertStatus(204);

        $this->request('POST', '/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);

        $this->assertStatus(401);
    }

    public function testUnknownRefreshTokenIsRejected(): void
    {
        $this->request('POST', '/auth/refresh', ['refreshToken' => 'inventado']);

        $this->assertStatus(401);
    }
}
