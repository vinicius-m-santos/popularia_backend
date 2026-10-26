<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AuthSession;
use App\Entity\RefreshToken;
use App\Entity\SecurityEvent;
use App\Enum\AccountStatus;
use App\Enum\SecurityEventType;
use App\Tests\ApiTestCase;

final class LoginTest extends ApiTestCase
{
    public function testLoginCreatesSessionWithHashedTokens(): void
    {
        $user = $this->createUser('camila');

        $tokens = $this->request('POST', '/auth/login', ['email' => 'Camila@Exemplo.org', 'password' => self::PASSWORD, 'deviceLabel' => 'iPhone']);

        $this->assertStatus(200);
        self::assertSame('Bearer', $tokens['tokenType']);
        self::assertSame(900, $tokens['accessTokenExpiresIn']);
        self::assertGreaterThanOrEqual(40, \strlen((string) $tokens['accessToken']));

        $session = $this->fresh(AuthSession::class, $tokens['sessionId']);
        self::assertSame('iPhone', $session->getDeviceLabel());
        // No banco só existem os hashes: os tokens em texto não aparecem em nenhuma coluna.
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT s.*, r.token_hash FROM auth_session s JOIN refresh_token r ON r.session_id = s.id');
        $dump = json_encode($rows, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString((string) $tokens['accessToken'], $dump);
        self::assertStringNotContainsString((string) $tokens['refreshToken'], $dump);
        self::assertSame(hash('sha256', (string) $tokens['refreshToken']), $rows[0]['token_hash']);

        $me = $this->request('GET', '/me', token: (string) $tokens['accessToken']);
        self::assertSame($user->getId()->toRfc4122(), $me['id']);
        self::assertSame(1, $this->em()->getRepository(SecurityEvent::class)->count(['type' => SecurityEventType::LoginSucceeded]));
    }

    public function testWrongPasswordAndUnknownEmailGetTheSameAnswer(): void
    {
        $this->createUser('camila');

        $wrongPassword = $this->request('POST', '/auth/login', ['email' => 'camila@exemplo.org', 'password' => 'senha errada!']);
        $this->assertStatus(401);
        $unknownEmail = $this->request('POST', '/auth/login', ['email' => 'ninguem@exemplo.org', 'password' => 'senha errada!']);
        $this->assertStatus(401);

        // Sem o trace (só existe em modo debug), as duas respostas são idênticas.
        unset($wrongPassword['trace'], $unknownEmail['trace']);
        self::assertSame($wrongPassword, $unknownEmail);
        self::assertSame('/errors/invalid-credentials', $wrongPassword['type']);
        self::assertSame(0, $this->em()->getRepository(RefreshToken::class)->count([]));
    }

    public function testSuspendedAccountCannotLogIn(): void
    {
        $user = $this->createUser('camila');
        $user->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();

        $body = $this->request('POST', '/auth/login', ['email' => 'camila@exemplo.org', 'password' => self::PASSWORD]);

        $this->assertStatus(403);
        self::assertSame('/errors/account-inactive', $body['type']);
    }

    public function testSuspensionTakesEffectOnExistingAccessToken(): void
    {
        $user = $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $fresh = $this->fresh(\App\Entity\User::class, $user->getId());
        $fresh->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();

        $body = $this->request('GET', '/me', token: $token);
        $this->assertStatus(403);
        self::assertSame('/errors/account-inactive', $body['type']);
    }

    public function testLoginIsRateLimitedPerAccountAndIp(): void
    {
        $this->createUser('camila');

        for ($i = 0; $i < 5; ++$i) {
            $this->request('POST', '/auth/login', ['email' => 'camila@exemplo.org', 'password' => 'tentativa '.$i]);
            $this->assertStatus(401);
        }

        // Nem a senha certa passa enquanto o limite estiver estourado.
        $body = $this->request('POST', '/auth/login', ['email' => 'camila@exemplo.org', 'password' => self::PASSWORD]);
        $this->assertStatus(429);
        self::assertSame('/errors/too-many-requests', $body['type']);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testExpiredAccessTokenIsRejected(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        self::mockTime('+16 minutes');
        $this->request('GET', '/me', token: $token);

        $this->assertStatus(401);
    }

    public function testGarbageTokenIsRejected(): void
    {
        $this->request('GET', '/me', token: 'nao-e-um-token');

        $this->assertStatus(401);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }
}
