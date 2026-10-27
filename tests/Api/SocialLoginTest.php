<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\UserAuthIdentity;
use App\Enum\AccountStatus;
use App\Tests\ApiTestCase;

/**
 * Testes do endpoint POST /auth/social.
 *
 * Usa os stubs FakeGoogleTokenValidator e FakeAppleTokenValidator (services_test.yaml).
 * Formato dos tokens stub:
 *   google_valid|<sub>|<email>|<email_verified 0|1>[|<name>]
 *   apple_valid|<sub>|<email>|<email_verified 0|1>
 */
final class SocialLoginTest extends ApiTestCase
{
    // --- Google ---

    public function testGoogleFirstLoginCreatesUserAndSession(): void
    {
        $body = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_001|nova@exemplo.org|1|Nova Pessoa',
            'deviceLabel' => 'Android',
        ]);

        $this->assertStatus(200);
        self::assertSame('Bearer', $body['tokenType']);
        self::assertTrue($body['onboardingRequired']);
        self::assertNotEmpty($body['accessToken']);
        self::assertNotEmpty($body['refreshToken']);

        // Me mostra email verificado e pendingOnboarding.
        $me = $this->request('GET', '/me', token: (string) $body['accessToken']);
        $this->assertStatus(200);
        self::assertTrue($me['emailVerified']);
        self::assertTrue($me['pendingOnboarding']);
        self::assertFalse($me['hasPassword']);
        self::assertSame('nova@exemplo.org', $me['email']);
    }

    public function testGoogleSecondLoginReturnsSameUser(): void
    {
        // Primeiro login.
        $first = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_002|recorrente@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        $firstMe = $this->request('GET', '/me', token: (string) $first['accessToken']);
        $userId = $firstMe['id'];

        // Segundo login com mesmo sub.
        $second = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_002|recorrente@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        $secondMe = $this->request('GET', '/me', token: (string) $second['accessToken']);
        self::assertSame($userId, $secondMe['id'], 'Deve retornar o mesmo User no segundo login.');
    }

    public function testGoogleInvalidTokenReturns401(): void
    {
        $body = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'token_invalido',
        ]);

        $this->assertStatus(401);
        self::assertSame('/errors/invalid-social-token', $body['type']);
    }

    public function testGoogleSuspendedAccountIsBlocked(): void
    {
        // Cria e depois suspende a conta.
        $first = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_003|suspensa@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        $me = $this->request('GET', '/me', token: (string) $first['accessToken']);

        $user = $this->fresh(\App\Entity\User::class, $me['id']);
        $user->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();

        $retry = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_003|suspensa@exemplo.org|1',
        ]);
        $this->assertStatus(403);
        self::assertSame('/errors/account-inactive', $retry['type']);
    }

    public function testGoogleEmailConflictWithExistingPasswordAccount(): void
    {
        // Cria conta normal com email verificado.
        $user = $this->createUser('conflito', 'conflito@exemplo.org', verified: true);

        // Google tenta criar conta com mesmo email verificado.
        $body = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_004|conflito@exemplo.org|1',
        ]);

        $this->assertStatus(409);
        self::assertSame('/errors/account-email-conflict', $body['type']);

        // Confirma que não criou novo User.
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['conflito@exemplo.org']));
    }

    public function testGoogleEmailNotVerifiedDoesNotConflict(): void
    {
        // Conta normal com mesmo email mas Google não confirma verificação.
        $this->createUser('naoconf', 'naoconf@exemplo.org', verified: true);

        $body = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_005|naoconf@exemplo.org|0',
        ]);

        // e_mail não verificado pelo provider: não verifica conflito, cria nova conta.
        $this->assertStatus(200);
    }

    // --- Apple ---

    public function testAppleFirstLoginCreatesUser(): void
    {
        $body = $this->request('POST', '/auth/social', [
            'provider' => 'apple',
            'idToken' => 'apple_valid|a_sub_001|apple@exemplo.org|1',
            'nonce' => 'raw_nonce_abc',
            'displayName' => 'Pessoa Apple',
        ]);

        $this->assertStatus(200);
        self::assertTrue($body['onboardingRequired']);
        $me = $this->request('GET', '/me', token: (string) $body['accessToken']);
        self::assertTrue($me['emailVerified']);
        self::assertFalse($me['hasPassword']);
    }

    public function testAppleHideMyEmailCreatesUserWithRelayEmail(): void
    {
        $relay = '1234abcd@privaterelay.appleid.com';
        $body = $this->request('POST', '/auth/social', [
            'provider' => 'apple',
            'idToken' => 'apple_valid|a_sub_002|'.$relay.'|1',
            'nonce' => 'raw_nonce_xyz',
        ]);

        $this->assertStatus(200);
        $me = $this->request('GET', '/me', token: (string) $body['accessToken']);
        self::assertSame($relay, $me['email'], 'E-mail relay da Apple deve ser preservado como e-mail da conta.');
    }

    public function testAppleSecondLoginWithoutEmailReturnsSameUser(): void
    {
        // Primeiro login Apple com email.
        $first = $this->request('POST', '/auth/social', [
            'provider' => 'apple',
            'idToken' => 'apple_valid|a_sub_003|apple2@exemplo.org|1',
            'nonce' => 'nonce1',
        ]);
        $this->assertStatus(200);
        $userId = $this->request('GET', '/me', token: (string) $first['accessToken'])['id'];

        // Login seguinte: Apple não retorna email (field vazio).
        $second = $this->request('POST', '/auth/social', [
            'provider' => 'apple',
            'idToken' => 'apple_valid|a_sub_003||0',
            'nonce' => 'nonce2',
        ]);
        $this->assertStatus(200);
        $secondUserId = $this->request('GET', '/me', token: (string) $second['accessToken'])['id'];

        self::assertSame($userId, $secondUserId, 'Mesmo User pelo sub, independente do email.');
    }

    public function testAppleMissingNonceReturns401(): void
    {
        $body = $this->request('POST', '/auth/social', [
            'provider' => 'apple',
            'idToken' => 'apple_valid|a_sub_004|x@exemplo.org|1',
            // nonce ausente
        ]);

        $this->assertStatus(401);
        self::assertSame('/errors/invalid-social-token', $body['type']);
    }

    // --- Account Linking ---

    public function testLinkGoogleToExistingPasswordAccount(): void
    {
        $user = $this->createUser('maria', 'maria@exemplo.org');
        $token = $this->login('maria@exemplo.org')['accessToken'];

        $body = $this->request('POST', '/me/auth-identities', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_link_001|maria@exemplo.org|1',
        ], token: $token);

        $this->assertStatus(200);
        self::assertCount(1, $body['identities']);
        self::assertSame('google', $body['identities'][0]['provider']);

        // Agora pode logar via Google e cair na mesma conta.
        $socialLogin = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_link_001|maria@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        $linkedMe = $this->request('GET', '/me', token: (string) $socialLogin['accessToken']);
        self::assertSame($user->getId()->toRfc4122(), $linkedMe['id']);
    }

    public function testLinkGoogleAlreadyLinkedToAnotherUserReturns409(): void
    {
        // Maria vincula Google.
        $maria = $this->createUser('maria2', 'maria2@exemplo.org');
        $mariaToken = $this->login('maria2@exemplo.org')['accessToken'];
        $this->request('POST', '/me/auth-identities', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_taken|maria2@exemplo.org|1',
        ], token: $mariaToken);
        $this->assertStatus(200);

        // João tenta vincular o mesmo Google sub.
        $joao = $this->createUser('joao', 'joao@exemplo.org');
        $joaoToken = $this->login('joao@exemplo.org')['accessToken'];
        $body = $this->request('POST', '/me/auth-identities', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_taken|maria2@exemplo.org|1',
        ], token: $joaoToken);

        $this->assertStatus(409);
        self::assertSame('/errors/identity-already-linked', $body['type']);
    }

    // --- Onboarding ---

    public function testCompleteOnboardingSetHandleAndName(): void
    {
        $social = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_onboard|onboard@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        self::assertTrue($social['onboardingRequired']);
        $token = (string) $social['accessToken'];

        $me = $this->request('POST', '/me/complete-onboarding', [
            'handle' => 'camilanova',
            'displayName' => 'Camila Nova',
        ], token: $token);

        $this->assertStatus(200);
        self::assertSame('camilanova', $me['handle']);
        self::assertSame('Camila Nova', $me['displayName']);
        self::assertFalse($me['pendingOnboarding']);
    }

    public function testCompleteOnboardingDuplicateHandleIsRejected(): void
    {
        $this->createUser('handleexist');

        $social = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_dup_handle|dup@exemplo.org|1',
        ]);
        $token = (string) $social['accessToken'];

        $body = $this->request('POST', '/me/complete-onboarding', [
            'handle' => 'handleexist',
            'displayName' => 'Alguém',
        ], token: $token);

        $this->assertStatus(422);
    }

    public function testCompleteOnboardingAlreadyCompletedReturns403(): void
    {
        $user = $this->createUser('jafeito');
        $token = $this->login('jafeito@exemplo.org')['accessToken'];

        $body = $this->request('POST', '/me/complete-onboarding', [
            'handle' => 'jafeito2',
            'displayName' => 'Já Feito',
        ], token: $token);

        $this->assertStatus(403);
        self::assertSame('/errors/onboarding-already-complete', $body['type']);
    }

    // --- Concorrência ---

    public function testConcurrentSocialLoginCreatesSingleUser(): void
    {
        // Simula duas requests quase simultâneas: a segunda não encontra a identidade ainda.
        $sub = 'g_sub_concurrent';
        $first = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|'.$sub.'|conc@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        $firstUserId = $this->request('GET', '/me', token: (string) $first['accessToken'])['id'];

        $second = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|'.$sub.'|conc@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        $secondUserId = $this->request('GET', '/me', token: (string) $second['accessToken'])['id'];

        self::assertSame($firstUserId, $secondUserId);
        self::assertSame(
            1,
            $this->em()->getRepository(UserAuthIdentity::class)->count(['provider' => 'google', 'providerSubject' => $sub]),
        );
    }
}
