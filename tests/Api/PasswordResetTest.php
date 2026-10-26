<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\ApiTestCase;

final class PasswordResetTest extends ApiTestCase
{
    private const string NEW_PASSWORD = 'uma nova senha forte';

    public function testUnknownEmailGetsSameAnswerAndNoEmail(): void
    {
        $this->createUser('camila');
        $known = $this->request('POST', '/auth/password-reset/request', ['email' => 'camila@exemplo.org']);
        $this->assertStatus(202);

        $unknown = $this->request('POST', '/auth/password-reset/request', ['email' => 'ninguem@exemplo.org']);

        $this->assertStatus(202);
        self::assertSame($known, $unknown);
        self::assertEmailCount(0);
    }

    public function testResetChangesPasswordAndEndsAllSessions(): void
    {
        $user = $this->createUser('camila', verified: false);
        $stolen = $this->login('camila@exemplo.org');

        $this->request('POST', '/auth/password-reset/request', ['email' => 'camila@exemplo.org']);
        $code = $this->lastCodeSentTo('camila@exemplo.org');
        $this->request('POST', '/auth/password-reset/confirm', ['email' => 'camila@exemplo.org', 'code' => $code, 'newPassword' => self::NEW_PASSWORD]);
        $this->assertStatus(204);

        // Sessões abertas antes (inclusive as de um invasor) não valem mais.
        $this->request('GET', '/me', token: $stolen['accessToken']);
        $this->assertStatus(401);
        $this->request('POST', '/auth/refresh', ['refreshToken' => $stolen['refreshToken']]);
        $this->assertStatus(401);

        $this->request('POST', '/auth/login', ['email' => 'camila@exemplo.org', 'password' => self::PASSWORD]);
        $this->assertStatus(401);
        $this->login('camila@exemplo.org', self::NEW_PASSWORD);
        self::assertTrue($this->fresh(User::class, $user->getId())->isEmailVerified());
    }

    public function testCodeIsSingleUse(): void
    {
        $this->createUser('camila');
        $this->request('POST', '/auth/password-reset/request', ['email' => 'camila@exemplo.org']);
        $code = $this->lastCodeSentTo('camila@exemplo.org');
        $this->request('POST', '/auth/password-reset/confirm', ['email' => 'camila@exemplo.org', 'code' => $code, 'newPassword' => self::NEW_PASSWORD]);
        $this->assertStatus(204);

        $body = $this->request('POST', '/auth/password-reset/confirm', ['email' => 'camila@exemplo.org', 'code' => $code, 'newPassword' => 'outra senha qualquer']);

        $this->assertStatus(400);
        self::assertSame('/errors/invalid-code', $body['type']);
        $this->login('camila@exemplo.org', self::NEW_PASSWORD);
    }

    public function testExpiredCodeIsRejected(): void
    {
        $this->createUser('camila');
        $this->request('POST', '/auth/password-reset/request', ['email' => 'camila@exemplo.org']);
        $code = $this->lastCodeSentTo('camila@exemplo.org');

        self::mockTime('+31 minutes');
        $this->request('POST', '/auth/password-reset/confirm', ['email' => 'camila@exemplo.org', 'code' => $code, 'newPassword' => self::NEW_PASSWORD]);

        $this->assertStatus(400);
    }

    public function testCodeOfOneAccountDoesNotWorkForAnother(): void
    {
        $this->createUser('camila');
        $this->createUser('outra');
        $this->request('POST', '/auth/password-reset/request', ['email' => 'camila@exemplo.org']);
        $code = $this->lastCodeSentTo('camila@exemplo.org');

        $this->request('POST', '/auth/password-reset/confirm', ['email' => 'outra@exemplo.org', 'code' => $code, 'newPassword' => self::NEW_PASSWORD]);

        $this->assertStatus(400);
    }

    public function testRequestIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->request('POST', '/auth/password-reset/request', ['email' => "p{$i}@exemplo.org"]);
            $this->assertStatus(202);
        }

        $this->request('POST', '/auth/password-reset/request', ['email' => 'p10@exemplo.org']);

        $this->assertStatus(429);
    }

    public function testEmailsPerAccountAreLimitedSilently(): void
    {
        $this->createUser('camila');
        for ($i = 0; $i < 3; ++$i) {
            $this->request('POST', '/auth/password-reset/request', ['email' => 'camila@exemplo.org']);
            self::assertEmailCount(1);
        }

        $this->request('POST', '/auth/password-reset/request', ['email' => 'camila@exemplo.org']);

        $this->assertStatus(202);
        self::assertEmailCount(0);
    }
}
