<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\PlatformRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base dos testes de API: requisições JSON reais contra o kernel, banco PostgreSQL de teste
 * (cada teste numa transação desfeita ao final) e ajudantes para criar contas e entrar.
 */
abstract class ApiTestCase extends WebTestCase
{
    use ClockSensitiveTrait;

    protected const string PASSWORD = 'senha-de-teste-123';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    /**
     * @param array<mixed>|null $body
     *
     * @return array<mixed>
     */
    protected function request(string $method, string $uri, ?array $body = null, ?string $token = null, string $contentType = 'application/json'): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $contentType];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $this->client->request($method, '/api/v1'.$uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : (array) json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }

    protected function assertStatus(int $expected): void
    {
        $response = $this->client->getResponse();
        self::assertSame($expected, $response->getStatusCode(), (string) $response->getContent());
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(
        string $handle = 'camila',
        ?string $email = null,
        bool $verified = true,
        PlatformRole $role = PlatformRole::User,
    ): User {
        $now = new \DateTimeImmutable();
        $user = new User($email ?? $handle.'@exemplo.org', $handle, ucfirst($handle), $now);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, self::PASSWORD), $now);
        if ($verified) {
            $user->markEmailVerified($now);
        }
        $user->changePlatformRole($role, $now);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Sem $owner, é uma comuna da equipe; com $owner, a pessoa é proprietária e membro "founder". */
    protected function createComuna(string $slug, ComunaVisibility $visibility = ComunaVisibility::Public, ?User $owner = null): Comuna
    {
        $comuna = new Comuna($slug, ucfirst($slug), 'Descrição de '.$slug, 'São Paulo · SP', $visibility, 'red', ['comun'], 2024, new \DateTimeImmutable());
        $comuna->addRule('Respeito sempre', 'Sem ataques pessoais.');
        $this->em()->persist($comuna);
        $this->em()->flush();
        if (null !== $owner) {
            $this->addMember($comuna, $owner, ComunaRole::Founder);
        }

        return $comuna;
    }

    /** Com ComunaRole::Founder, a pessoa vira também a proprietária (comuna.owner), como no app. */
    protected function addMember(Comuna $comuna, User $user, ComunaRole $role = ComunaRole::Member): void
    {
        if (ComunaRole::Founder === $role) {
            $comuna->changeOwner($user);
        }
        $this->em()->persist(new ComunaMembership($comuna, $user, $role, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->em()->getRepository(Comuna::class)->adjustMemberCount($comuna, +1);
    }

    protected function createPost(User $author, ?Comuna $comuna, string $body = 'Publicação de teste'): Post
    {
        $post = new Post($author, $comuna, $body, new \DateTimeImmutable());
        $this->em()->persist($post);
        $this->em()->flush();

        return $post;
    }

    /** @return array{accessToken: string, refreshToken: string, sessionId: string} */
    protected function login(string $email, string $password = self::PASSWORD): array
    {
        $tokens = $this->request('POST', '/auth/login', ['email' => $email, 'password' => $password, 'deviceLabel' => 'Teste']);
        $this->assertStatus(200);
        \assert(\is_string($tokens['accessToken']) && \is_string($tokens['refreshToken']) && \is_string($tokens['sessionId']));

        return ['accessToken' => $tokens['accessToken'], 'refreshToken' => $tokens['refreshToken'], 'sessionId' => $tokens['sessionId']];
    }

    /** Código de 6 dígitos do último e-mail enviado para $to. */
    protected function lastCodeSentTo(string $to): string
    {
        $messages = array_values(array_filter(
            self::getMailerMessages(),
            static fn ($m) => $m instanceof Email && $m->getTo()[0]->getAddress() === $to,
        ));
        self::assertNotEmpty($messages, 'Nenhum e-mail enviado para '.$to);
        $last = end($messages);
        self::assertSame(1, preg_match('/\b(\d{6})\b/', (string) $last->getTextBody(), $m), 'E-mail sem código.');

        return $m[1];
    }

    /**
     * Versão atual de uma entidade no banco. O kernel reinicia entre requisições, então
     * objetos carregados antes de uma requisição não refletem o que ela mudou.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function fresh(string $class, mixed $id): object
    {
        $this->em()->clear();
        $entity = $this->em()->find($class, $id);
        self::assertNotNull($entity);

        return $entity;
    }
}
