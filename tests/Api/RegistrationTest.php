<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\SecurityEvent;
use App\Entity\User;
use App\Enum\SecurityEventType;
use App\Repository\UserRepository;
use App\Tests\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationTest extends ApiTestCase
{
    private const array VALID = [
        'email' => '  Nova.Pessoa@Exemplo.org ',
        'password' => 'frase longa e segura',
        'handle' => 'nova.pessoa',
        'displayName' => 'Nova Pessoa',
        'bio' => 'Professora no cursinho popular.',
        'city' => 'Belo Horizonte · MG',
        'interests' => ['edu', 'livros'],
    ];

    public function testValidRegistrationCreatesAccountAndSendsCode(): void
    {
        $body = $this->request('POST', '/auth/register', ['email' => 'nova.pessoa@exemplo.org'] + self::VALID);

        $this->assertStatus(202);
        self::assertArrayHasKey('message', $body);
        self::assertSame(['message'], array_keys($body));

        $user = $this->users()->findOneByEmail('nova.pessoa@exemplo.org');
        self::assertNotNull($user);
        self::assertSame('nova.pessoa', $user->getHandle());
        self::assertFalse($user->isEmailVerified());
        self::assertSame(['edu', 'livros'], $user->getInterests());
        self::assertSame('user', $user->getPlatformRole()->value);
        self::assertMatchesRegularExpression('/^\d{6}$/', $this->lastCodeSentTo('nova.pessoa@exemplo.org'));
    }

    public function testPasswordIsStoredOnlyAsHash(): void
    {
        $this->request('POST', '/auth/register', ['email' => 'nova.pessoa@exemplo.org'] + self::VALID);

        $user = $this->users()->findOneByEmail('nova.pessoa@exemplo.org');
        self::assertNotNull($user);
        self::assertNotSame(self::VALID['password'], $user->getPassword());
        self::assertStringNotContainsString(self::VALID['password'], $user->getPassword());
        self::assertNotNull(password_get_info($user->getPassword())['algo'], 'Hash num formato reconhecido pelo PHP.');
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::VALID['password']));
    }

    public function testInvalidDataIsRejectedWithViolations(): void
    {
        $body = $this->request('POST', '/auth/register', [
            'email' => 'sem-arroba',
            'password' => 'curta',
            'handle' => 'Com Espaço',
            'displayName' => '',
            'interests' => ['inexistente'],
        ]);

        $this->assertStatus(422);
        $paths = array_column((array) $body['violations'], 'propertyPath');
        foreach (['email', 'password', 'handle', 'displayName', 'interests[0]'] as $field) {
            self::assertContains($field, $paths);
        }
        self::assertNull($this->users()->findOneByEmail('sem-arroba'));
    }

    public function testClientCannotChooseRoleOrIdentifiers(): void
    {
        $this->request('POST', '/auth/register', self::VALID + ['platformRole' => 'admin', 'id' => '00000000-0000-0000-0000-000000000001']);

        $this->assertStatus(400);
        self::assertNull($this->users()->findOneByEmail('nova.pessoa@exemplo.org'));
    }

    public function testReservedHandleIsRejected(): void
    {
        $body = $this->request('POST', '/auth/register', ['handle' => 'moderacao'] + self::VALID);

        $this->assertStatus(422);
        self::assertSame('handle', $body['violations'][0]['propertyPath']);
    }

    public function testHandleMustBeUnique(): void
    {
        $this->createUser('nova.pessoa');

        $body = $this->request('POST', '/auth/register', ['email' => 'outra@exemplo.org'] + self::VALID);

        $this->assertStatus(422);
        self::assertSame('handle', $body['violations'][0]['propertyPath']);
        self::assertNull($this->users()->findOneByEmail('outra@exemplo.org'));
    }

    public function testDuplicateEmailGetsSameResponseAndNoSecondAccount(): void
    {
        $existing = $this->createUser('camila', 'camila@exemplo.org');
        $fresh = $this->request('POST', '/auth/register', ['email' => 'livre@exemplo.org', 'handle' => 'livre'] + self::VALID);

        $duplicate = $this->request('POST', '/auth/register', ['email' => 'CAMILA@exemplo.org', 'handle' => 'outro.nome'] + self::VALID);

        $this->assertStatus(202);
        self::assertSame($fresh, $duplicate);
        self::assertNull($this->users()->findOneByHandle('outro.nome'));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailSubjectContains($email, 'Tentativa de cadastro');
        self::assertSame(1, $this->em()->getRepository(SecurityEvent::class)->count(['type' => SecurityEventType::RegistrationWithExistingEmail, 'user' => $existing->getId()]));
    }

    public function testRegistrationIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->request('POST', '/auth/register', ['email' => "p{$i}@exemplo.org", 'handle' => "pessoa{$i}"] + self::VALID);
            $this->assertStatus(202);
        }

        $this->request('POST', '/auth/register', ['email' => 'p10@exemplo.org', 'handle' => 'pessoa10'] + self::VALID);

        $this->assertStatus(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    private function users(): UserRepository
    {
        $this->em()->clear();

        return $this->em()->getRepository(User::class);
    }
}
