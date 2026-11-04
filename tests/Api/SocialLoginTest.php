<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Enum\AccountStatus;
use App\Legal\LegalDocuments;
use App\Tests\ApiTestCase;

/**
 * Login social: conta existente autentica; conta nova vira cadastro pendente,
 * sem User, e só é criada no completion com aceite legal.
 */
final class SocialLoginTest extends ApiTestCase
{
    /** @return array<string, mixed> */
    private function pendingSocial(
        string $sub = 'g_sub_001',
        string $email = 'nova@exemplo.org',
        int $verified = 1,
        ?string $displayName = null,
        string $provider = 'google',
    ): array {
        $token = $provider.'_valid|'.$sub.'|'.$email.'|'.$verified.($displayName ? '|'.$displayName : '');
        $body = $this->request('POST', '/auth/social', [
            'provider' => $provider,
            'idToken' => $token,
            'nonce' => 'nonce_'.$sub,
            'displayName' => $displayName,
            'deviceLabel' => 'Android',
        ]);
        $this->assertStatus(200);

        return $body;
    }

    /**
     * @param array<string, mixed> $pending
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function completeSocial(array $pending, string $handle = 'pessoa.nova', string $name = 'Pessoa Nova', array $extra = []): array
    {
        $body = $this->request('POST', '/auth/social/complete', array_merge([
            'pendingSignupToken' => $pending['pendingSignupToken'],
            'handle' => $handle,
            'displayName' => $name,
            'countryCode' => 'BR',
            'legalAccepted' => true,
            'legalVersion' => LegalDocuments::VERSION,
            'deviceLabel' => 'Android',
        ], $extra));
        $this->assertStatus(200);

        return $body;
    }

    public function testGoogleFirstLoginCreatesPendingSignupWithoutUser(): void
    {
        $pending = $this->pendingSocial(displayName: 'Nova Pessoa');

        self::assertSame('pending_signup', $pending['status']);
        self::assertNotEmpty($pending['pendingSignupToken']);
        self::assertSame('google', $pending['provider']);
        self::assertSame('nova@exemplo.org', $pending['email']);
        self::assertSame('Nova Pessoa', $pending['displayName']);
        self::assertNull($pending['accessToken'] ?? null);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['nova@exemplo.org']));
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_social_signup'));

        $tokens = $this->completeSocial($pending, 'nova.pessoa', 'Nova Pessoa');
        self::assertSame('Bearer', $tokens['tokenType']);
        self::assertFalse($tokens['onboardingRequired']);
        self::assertNotEmpty($tokens['accessToken']);

        $me = $this->request('GET', '/me', token: (string) $tokens['accessToken']);
        $this->assertStatus(200);
        self::assertSame('nova@exemplo.org', $me['email']);
        self::assertTrue($me['emailVerified']);
        self::assertFalse($me['pendingOnboarding']);
        self::assertFalse($me['hasPassword']);
        self::assertTrue($me['legalAccepted']);
        self::assertSame('nova.pessoa', $me['handle']);
        $legal = $this->em()->getConnection()->fetchAssociative('SELECT legal_accepted_at, legal_terms_version, legal_rules_version, legal_privacy_version FROM app_user WHERE email = ?', ['nova@exemplo.org']);
        self::assertIsArray($legal);
        self::assertNotNull($legal['legal_accepted_at']);
        self::assertSame(LegalDocuments::TERMS_VERSION, $legal['legal_terms_version']);
        self::assertSame(LegalDocuments::RULES_VERSION, $legal['legal_rules_version']);
        self::assertSame(LegalDocuments::PRIVACY_VERSION, $legal['legal_privacy_version']);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_social_signup'));
    }

    public function testGoogleSecondLoginReturnsSameUserAfterCompletion(): void
    {
        $pending = $this->pendingSocial('g_sub_002', 'recorrente@exemplo.org');
        $first = $this->completeSocial($pending, 'recorrente', 'Recorrente');
        $firstMe = $this->request('GET', '/me', token: (string) $first['accessToken']);

        $second = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_002|recorrente@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        self::assertSame('authenticated', $second['status']);
        $secondMe = $this->request('GET', '/me', token: (string) $second['accessToken']);
        self::assertSame($firstMe['id'], $secondMe['id']);
    }

    public function testGoogleInvalidTokenReturns401(): void
    {
        $body = $this->request('POST', '/auth/social', ['provider' => 'google', 'idToken' => 'token_invalido']);

        $this->assertStatus(401);
        self::assertSame('/errors/invalid-social-token', $body['type']);
    }

    public function testGoogleSuspendedAccountIsBlocked(): void
    {
        $pending = $this->pendingSocial('g_sub_003', 'suspensa@exemplo.org');
        $tokens = $this->completeSocial($pending, 'suspensa', 'Suspensa');
        $me = $this->request('GET', '/me', token: (string) $tokens['accessToken']);

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
        $this->createUser('conflito', 'conflito@exemplo.org', verified: true);

        $body = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_004|conflito@exemplo.org|1',
        ]);

        $this->assertStatus(409);
        self::assertSame('/errors/account-email-conflict', $body['type']);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_social_signup'));
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['conflito@exemplo.org']));
    }

    public function testGoogleEmailNotVerifiedDoesNotConflict(): void
    {
        $this->createUser('naoconf', 'naoconf@exemplo.org', verified: true);

        $pending = $this->pendingSocial('g_sub_005', 'naoconf@exemplo.org', verified: 0);

        self::assertSame('pending_signup', $pending['status']);
        self::assertNull($pending['email']);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_social_signup WHERE provider_email IS NOT NULL'));
    }

    // --- Apple ---

    public function testAppleFirstLoginCreatesPendingAndPreservesRelayEmail(): void
    {
        $relay = '1234abcd@privaterelay.appleid.com';
        $pending = $this->pendingSocial('a_sub_001', $relay, provider: 'apple', displayName: 'Pessoa Apple');

        self::assertSame('pending_signup', $pending['status']);
        self::assertSame('apple', $pending['provider']);
        self::assertSame($relay, $pending['email']);
        self::assertSame('Pessoa Apple', $pending['displayName']);

        $tokens = $this->completeSocial($pending, 'pessoa.apple', 'Pessoa Apple');
        $me = $this->request('GET', '/me', token: (string) $tokens['accessToken']);
        self::assertSame($relay, $me['email']);
        self::assertTrue($me['emailVerified']);
    }

    public function testAppleSecondLoginWithoutEmailReturnsSameUser(): void
    {
        $pending = $this->pendingSocial('a_sub_003', 'apple2@exemplo.org', provider: 'apple');
        $first = $this->completeSocial($pending, 'apple2', 'Apple Dois');
        $userId = $this->request('GET', '/me', token: (string) $first['accessToken'])['id'];

        $second = $this->request('POST', '/auth/social', [
            'provider' => 'apple',
            'idToken' => 'apple_valid|a_sub_003||0',
            'nonce' => 'nonce2',
        ]);

        $this->assertStatus(200);
        self::assertSame('authenticated', $second['status']);
        $secondUserId = $this->request('GET', '/me', token: (string) $second['accessToken'])['id'];
        self::assertSame($userId, $secondUserId);
    }

    public function testAppleMissingNonceReturns401(): void
    {
        $body = $this->request('POST', '/auth/social', [
            'provider' => 'apple',
            'idToken' => 'apple_valid|a_sub_004|x@exemplo.org|1',
        ]);

        $this->assertStatus(401);
        self::assertSame('/errors/invalid-social-token', $body['type']);
    }

    // --- Account linking ---

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

        $socialLogin = $this->request('POST', '/auth/social', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_link_001|maria@exemplo.org|1',
        ]);
        $this->assertStatus(200);
        self::assertSame('authenticated', $socialLogin['status']);
        $linkedMe = $this->request('GET', '/me', token: (string) $socialLogin['accessToken']);
        self::assertSame($user->getId()->toRfc4122(), $linkedMe['id']);
    }

    public function testLinkGoogleAlreadyLinkedToAnotherUserReturns409(): void
    {
        $maria = $this->createUser('maria2', 'maria2@exemplo.org');
        $mariaToken = $this->login('maria2@exemplo.org')['accessToken'];
        $this->request('POST', '/me/auth-identities', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_taken|maria2@exemplo.org|1',
        ], token: $mariaToken);
        $this->assertStatus(200);

        $this->createUser('joao', 'joao@exemplo.org');
        $joaoToken = $this->login('joao@exemplo.org')['accessToken'];
        $body = $this->request('POST', '/me/auth-identities', [
            'provider' => 'google',
            'idToken' => 'google_valid|g_sub_taken|maria2@exemplo.org|1',
        ], token: $joaoToken);

        $this->assertStatus(409);
        self::assertSame('/errors/identity-already-linked', $body['type']);
    }

    // --- Completion ---

    public function testCompleteSocialSignupRequiresLegalAcceptance(): void
    {
        $pending = $this->pendingSocial('g_sub_no_legal', 'nolegal@exemplo.org');
        $body = $this->request('POST', '/auth/social/complete', [
            'pendingSignupToken' => $pending['pendingSignupToken'],
            'handle' => 'semaceite',
            'displayName' => 'Sem Aceite',
            'legalAccepted' => false,
            'legalVersion' => LegalDocuments::VERSION,
        ]);

        $this->assertStatus(422);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['nolegal@exemplo.org']));
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_social_signup'));
    }

    public function testCompleteSocialSignupDuplicateHandleIsRejected(): void
    {
        $this->createUser('handleexist');
        $pending = $this->pendingSocial('g_sub_dup_handle', 'dup@exemplo.org');

        $body = $this->request('POST', '/auth/social/complete', [
            'pendingSignupToken' => $pending['pendingSignupToken'],
            'handle' => 'handleexist',
            'displayName' => 'Alguém',
            'legalAccepted' => true,
            'legalVersion' => LegalDocuments::VERSION,
        ]);

        $this->assertStatus(422);
        self::assertSame('handle', $body['violations'][0]['propertyPath']);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['dup@exemplo.org']));
    }

    public function testCompleteSocialSignupExpiredTokenIsRejected(): void
    {
        $pending = $this->pendingSocial('g_sub_expired', 'expired@exemplo.org');
        $this->em()->getConnection()->executeStatement('UPDATE pending_social_signup SET expires_at = ? WHERE token_hash = ?', [
            (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:sP'),
            hash('sha256', $pending['pendingSignupToken']),
        ]);
        $this->em()->clear();

        $body = $this->request('POST', '/auth/social/complete', [
            'pendingSignupToken' => $pending['pendingSignupToken'],
            'handle' => 'expirado',
            'displayName' => 'Expirado',
            'legalAccepted' => true,
            'legalVersion' => LegalDocuments::VERSION,
        ]);

        $this->assertStatus(400);
        self::assertSame('/errors/pending-signup-expired', $body['type']);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_social_signup'));
    }

    public function testCompleteSocialSignupTokenIsSingleUse(): void
    {
        $pending = $this->pendingSocial('g_sub_single', 'single@exemplo.org');
        $this->completeSocial($pending, 'single', 'Single');

        $body = $this->request('POST', '/auth/social/complete', [
            'pendingSignupToken' => $pending['pendingSignupToken'],
            'handle' => 'single2',
            'displayName' => 'Single Dois',
            'legalAccepted' => true,
            'legalVersion' => LegalDocuments::VERSION,
        ]);

        $this->assertStatus(400);
        self::assertSame('/errors/pending-signup-expired', $body['type']);
    }

    public function testConcurrentSocialLoginDoesNotCreateUserBeforeCompletion(): void
    {
        $first = $this->pendingSocial('g_sub_concurrent', 'conc@exemplo.org');
        $second = $this->pendingSocial('g_sub_concurrent', 'conc@exemplo.org');

        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_social_signup'));
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['conc@exemplo.org']));

        $this->completeSocial($second, 'conc', 'Concurrente');
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM app_user WHERE email = ?', ['conc@exemplo.org']));
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_social_signup'));

        $replay = $this->request('POST', '/auth/social/complete', [
            'pendingSignupToken' => $first['pendingSignupToken'],
            'handle' => 'conc2',
            'displayName' => 'Concurrente',
            'legalAccepted' => true,
            'legalVersion' => LegalDocuments::VERSION,
        ]);
        $this->assertStatus(400);
    }
}
