<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\ApiTestCase;

final class EmailVerificationTest extends ApiTestCase
{
    public function testCodeVerifiesEmailOnce(): void
    {
        [$user, $token, $code] = $this->registerAndLogin();

        $this->request('POST', '/me/email-verification', ['code' => $code], $token);
        $this->assertStatus(204);
        self::assertTrue($this->fresh(User::class, $user->getId())->isEmailVerified());

        $me = $this->request('GET', '/me', token: $token);
        self::assertTrue($me['emailVerified']);
    }

    public function testWrongCodeIsRejectedAndBlocksAfterFiveAttempts(): void
    {
        [, $token, $code] = $this->registerAndLogin();
        $wrong = '000000' === $code ? '111111' : '000000';

        for ($i = 0; $i < 5; ++$i) {
            $body = $this->request('POST', '/me/email-verification', ['code' => $wrong], $token);
            $this->assertStatus(400);
            self::assertSame('/errors/invalid-code', $body['type']);
        }

        // O código certo não vale mais depois de 5 erros.
        $this->request('POST', '/me/email-verification', ['code' => $code], $token);
        $this->assertStatus(400);
    }

    public function testExpiredCodeIsRejected(): void
    {
        [, $token, $code] = $this->registerAndLogin();

        // O access token também venceria: entra de novo depois do salto no tempo.
        self::mockTime('+25 hours');
        $token = $this->login('verifica@exemplo.org', 'senha-de-teste-123')['accessToken'];
        $this->request('POST', '/me/email-verification', ['code' => $code], $token);

        $this->assertStatus(400);
    }

    public function testResendInvalidatesPreviousCode(): void
    {
        [, $token, $oldCode] = $this->registerAndLogin();

        $this->request('POST', '/me/email-verification/resend', token: $token);
        $this->assertStatus(202);
        $newCode = $this->lastCodeSentTo('verifica@exemplo.org');

        if ($oldCode !== $newCode) {
            $this->request('POST', '/me/email-verification', ['code' => $oldCode], $token);
            $this->assertStatus(400);
        }
        $this->request('POST', '/me/email-verification', ['code' => $newCode], $token);
        $this->assertStatus(204);
    }

    public function testResendHasSixtySecondCooldown(): void
    {
        [, $token] = $this->registerAndLogin();

        $this->request('POST', '/me/email-verification/resend', token: $token);
        $this->assertStatus(202);
        $problem = $this->request('POST', '/me/email-verification/resend', token: $token);
        $this->assertStatus(429);
        self::assertSame('/errors/too-many-requests', $problem['type']);
    }

    public function testVerificationRequiresAuthentication(): void
    {
        $this->request('POST', '/me/email-verification', ['code' => '123456']);

        $this->assertStatus(401);
    }

    /** @return array{User, string, string} */
    private function registerAndLogin(): array
    {
        $this->request('POST', '/auth/register', [
            'email' => 'verifica@exemplo.org',
            'password' => 'senha-de-teste-123',
            'handle' => 'verifica',
            'displayName' => 'Verifica',
        ]);
        $this->assertStatus(202);
        $code = $this->lastCodeSentTo('verifica@exemplo.org');
        $token = $this->login('verifica@exemplo.org', 'senha-de-teste-123')['accessToken'];
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'verifica@exemplo.org']);
        self::assertNotNull($user);

        return [$user, $token, $code];
    }
}
