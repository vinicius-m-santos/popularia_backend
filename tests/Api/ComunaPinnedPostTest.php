<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Comuna;
use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\UserBlock;
use App\Enum\AccountStatus;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\ContentStatus;
use App\Enum\PlatformRole;
use App\Tests\ApiTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** Fixar publicação na comuna: permissão, limite, ordem e leitura. */
final class ComunaPinnedPostTest extends ApiTestCase
{
    public function testOwnerAndModeratorCanPinAndAMemberCannot(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $mod = $this->createUser('mod');
        $this->addMember($comuna, $mod, ComunaRole::Moderator);
        $member = $this->createUser('membro');
        $this->addMember($comuna, $member);
        $admin = $this->createUser('ops', role: PlatformRole::Admin);
        $post = $this->createPost($member, $comuna, 'Regras da comuna');
        $ownerPost = $this->createPost($owner, $comuna, 'Recado da proprietária');

        $memberToken = $this->login('membro@exemplo.org')['accessToken'];
        $denied = $this->request('PUT', $this->pinPath($comuna, $post), token: $memberToken);
        $this->assertStatus(403);
        self::assertSame('/errors/not-comuna-moderator', $denied['type']);

        $adminToken = $this->login('ops@exemplo.org')['accessToken'];
        $this->request('PUT', $this->pinPath($comuna, $post), token: $adminToken);
        $this->assertStatus(403);

        $modToken = $this->login('mod@exemplo.org')['accessToken'];
        $pinned = $this->request('PUT', $this->pinPath($comuna, $post), token: $modToken);
        $this->assertStatus(200);
        self::assertSame($post->getId()->toRfc4122(), $pinned['items'][0]['id']);
        self::assertTrue($pinned['items'][0]['viewer']['canPin']);
        self::assertTrue($pinned['items'][0]['viewer']['pinned']);
        self::assertTrue($pinned['items'][0]['viewer']['canRemove']);

        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $both = $this->request('PUT', $this->pinPath($comuna, $ownerPost), token: $ownerToken);
        $this->assertStatus(200);
        self::assertSame([$ownerPost->getId()->toRfc4122(), $post->getId()->toRfc4122()], array_column($both['items'], 'id'));
        self::assertTrue($both['items'][0]['viewer']['canPin']);
        self::assertFalse($both['items'][0]['viewer']['canRemove']);

        $seenByMember = $this->request('GET', '/posts/'.$post->getId()->toRfc4122(), token: $memberToken);
        self::assertFalse($seenByMember['viewer']['canPin']);
        self::assertTrue($seenByMember['viewer']['pinned']);
    }

    public function testPinAndUnpinAreIdempotent(): void
    {
        [$comuna, $token, $post] = $this->moderatorAndPost('Assembleia sexta');
        $first = $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(200);
        $again = $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(200);
        self::assertSame($first['items'][0]['id'], $again['items'][0]['id']);
        self::assertCount(1, $again['items']);
        self::assertSame(1, $this->pinCount($comuna));

        $this->request('DELETE', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(204);
        $this->request('DELETE', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', $this->listPath($comuna), token: $token)['items']);
        self::assertSame(0, $this->pinCount($comuna));
    }

    public function testPinnedPollKeepsEveryOptionAndOtherPins(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $text = $this->createPost($owner, $comuna, 'Outro aviso');
        $this->request('PUT', $this->pinPath($comuna, $text), token: $token);
        $poll = $this->request('POST', '/posts', [
            'body' => 'Qual dia?', 'comunaId' => $comuna->getId()->toRfc4122(),
            'poll' => ['question' => 'Qual dia?', 'options' => [['label' => 'Segunda'], ['label' => 'Terça'], ['label' => 'Quarta'], ['label' => 'Quinta']]],
        ], $token);
        $this->assertStatus(201);
        $this->request('PUT', $this->listPath($comuna).'/'.$poll['id'], token: $token);
        $this->assertStatus(200);
        $list = $this->request('GET', $this->listPath($comuna), token: $token);
        self::assertCount(2, $list['items']);
        self::assertCount(4, $list['items'][0]['poll']['options']);
        self::assertSame($text->getId()->toRfc4122(), $list['items'][1]['id']);
    }

    public function testFourthPinIsRejectedAndTheExistingOnesStay(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $ids = [];
        foreach (['Regras', 'Reunião', 'Campanha', 'Quarta'] as $body) {
            $ids[] = $this->createPost($owner, $comuna, $body)->getId()->toRfc4122();
        }
        foreach (array_slice($ids, 0, 3) as $id) {
            $this->request('PUT', $this->listPath($comuna).'/'.$id, token: $token);
            $this->assertStatus(200);
        }

        $denied = $this->request('PUT', $this->listPath($comuna).'/'.$ids[3], token: $token);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-pin-limit', $denied['type']);
        self::assertSame('Esta comuna já tem 3 publicações fixadas.', $denied['title']);
        $list = $this->request('GET', $this->listPath($comuna), token: $token);
        self::assertSame([$ids[2], $ids[1], $ids[0]], array_column($list['items'], 'id'));
        self::assertSame(3, $this->pinCount($comuna));
    }

    public function testDatabaseTriggerRejectsAFourthRowInsideTheSameTransaction(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $posts = [];
        foreach (['Um', 'Dois', 'Três', 'Quatro'] as $body) {
            $posts[] = $this->createPost($owner, $comuna, $body);
        }
        foreach (array_slice($posts, 0, 3) as $post) {
            $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
            $this->assertStatus(200);
        }

        $connection = $this->em()->getConnection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement(
                'INSERT INTO comuna_pinned_post (id, comuna_id, post_id, position, pinned_by_id, pinned_at) VALUES (?, ?, ?, 1, ?, NOW())',
                [$this->uuid(), $comuna->getId()->toRfc4122(), $posts[3]->getId()->toRfc4122(), $owner->getId()->toRfc4122()],
            );
            self::fail('O banco aceitou uma quarta publicação fixada.');
        } catch (DriverException $e) {
            $connection->rollBack();
            self::assertStringContainsString('comuna_pin_limit_reached', $e->getMessage());
        }
        self::assertSame(3, $this->pinCount($comuna));
    }

    public function testConcurrentInsertsCannotExceedThree(): void
    {
        /** @var array{host?: string, port?: int|string, dbname?: string, user?: string, password?: string|null} $params */
        $params = $this->em()->getConnection()->getParams();
        $pdo = self::pdo($params);
        $userId = $this->uuid();
        $comunaId = $this->uuid();
        $slug = 'pin'.bin2hex(random_bytes(4));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
        $postIds = [$this->uuid(), $this->uuid(), $this->uuid(), $this->uuid()];
        try {
            $pdo->prepare('INSERT INTO app_user (id, email, password, handle, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$userId, $slug.'@exemplo.org', 'x', $slug, 'Pin', $now, $now]);
            $pdo->prepare('INSERT INTO comuna (id, slug, name, description, place, visibility, tone, founded_year, created_at, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$comunaId, $slug, 'Pin', 'Descrição', 'São Paulo', 'public', 'red', 2026, $now, $userId]);
            $insertPost = $pdo->prepare('INSERT INTO post (id, status, created_at, body, author_id, comuna_id) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($postIds as $index => $postId) {
                $insertPost->execute([$postId, 'published', $now, 'Post '.$index, $userId, $comunaId]);
            }
            $insertPin = $pdo->prepare('INSERT INTO comuna_pinned_post (id, comuna_id, post_id, position, pinned_by_id, pinned_at) VALUES (?, ?, ?, ?, ?, ?)');
            $insertPin->execute([$this->uuid(), $comunaId, $postIds[0], 1, $userId, $now]);
            $insertPin->execute([$this->uuid(), $comunaId, $postIds[1], 2, $userId, $now]);

            $script = <<<'PHP'
                $pdo = new PDO(
                    sprintf('pgsql:host=%s;port=%d;dbname=%s', getenv('PIN_DB_HOST'), (int) getenv('PIN_DB_PORT'), getenv('PIN_DB_NAME')),
                    (string) getenv('PIN_DB_USER'),
                    (string) getenv('PIN_DB_PASSWORD'),
                );
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec("SET statement_timeout = '8s'");
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO comuna_pinned_post (id, comuna_id, post_id, position, pinned_by_id, pinned_at) VALUES (?, ?, ?, 1, ?, NOW())')
                        ->execute([getenv('PIN_ID'), getenv('COMUNA_ID'), getenv('POST_ID'), getenv('USER_ID')]);
                    $pdo->commit();
                    fwrite(STDOUT, "ok\n");
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    fwrite(STDOUT, $e->getMessage());
                    exit(1);
                }
                PHP;
            $env = [
                'PIN_DB_HOST' => (string) ($params['host'] ?? 'localhost'),
                'PIN_DB_PORT' => (string) ($params['port'] ?? 5432),
                'PIN_DB_NAME' => (string) ($params['dbname'] ?? ''),
                'PIN_DB_USER' => (string) ($params['user'] ?? ''),
                'PIN_DB_PASSWORD' => (string) ($params['password'] ?? ''),
                'COMUNA_ID' => $comunaId,
                'USER_ID' => $userId,
            ];
            $first = new Process([PHP_BINARY, '-r', $script], null, $env + ['PIN_ID' => $this->uuid(), 'POST_ID' => $postIds[2]]);
            $second = new Process([PHP_BINARY, '-r', $script], null, $env + ['PIN_ID' => $this->uuid(), 'POST_ID' => $postIds[3]]);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();
            $outcomes = [$first->isSuccessful(), $second->isSuccessful()];
            sort($outcomes);
            self::assertSame([false, true], $outcomes, $first->getOutput().$second->getOutput());
            self::assertStringContainsString('comuna_pin_limit_reached', $first->getOutput().$second->getOutput());
            $count = $pdo->prepare('SELECT COUNT(*) FROM comuna_pinned_post WHERE comuna_id = ?');
            $count->execute([$comunaId]);
            /** @var array{0: string|int}|false $row */
            $row = $count->fetch(\PDO::FETCH_NUM);
            self::assertIsArray($row);
            self::assertSame(3, (int) $row[0]);
        } finally {
            $pdo->exec('DELETE FROM comuna WHERE id = '.$pdo->quote($comunaId));
            $pdo->exec('DELETE FROM app_user WHERE id = '.$pdo->quote($userId));
        }
    }

    public function testPersonalAndForeignPostsCannotBePinned(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $other = $this->createComuna('outra', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $personal = $this->createPost($owner, null, 'No meu perfil');
        $foreign = $this->createPost($owner, $other, 'Na outra comuna');

        foreach ([$personal, $foreign] as $post) {
            $response = $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
            $this->assertStatus(409);
            self::assertSame('/errors/post-not-in-comuna', $response['type']);
        }
        self::assertSame(0, $this->pinCount($comuna));
    }

    public function testRemovedAndDeletedPinsAreReleasedButSuspensionOnlyHidesThem(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $mod = $this->createUser('mod');
        $this->addMember($comuna, $mod, ComunaRole::Moderator);
        $author = $this->createUser('autora');
        $this->addMember($comuna, $author);
        $removed = $this->createPost($author, $comuna, 'Fora do tema');
        $token = $this->login('mod@exemplo.org')['accessToken'];
        $authorToken = $this->login('autora@exemplo.org')['accessToken'];
        $this->request('PUT', $this->pinPath($comuna, $removed), token: $token);
        $this->assertStatus(200);
        $this->request('POST', '/posts/'.$removed->getId()->toRfc4122().'/removal', ['reason' => 'Fora do tema da comuna'], $token);
        $this->assertStatus(204);
        self::assertSame(ContentStatus::Removed, $this->fresh(Post::class, $removed->getId())->getStatus());
        self::assertSame([], $this->request('GET', $this->listPath($comuna), token: $token)['items']);
        self::assertSame(0, $this->pinCount($comuna));
        $hidden = $this->request('PUT', $this->pinPath($comuna, $removed), token: $token);
        $this->assertStatus(404);
        self::assertStringNotContainsString('Fora do tema', (string) json_encode($hidden));

        $deleted = $this->createPost($this->managedUser($author), $this->managedComuna($comuna), 'Vou apagar');
        $this->request('PUT', $this->pinPath($comuna, $deleted), token: $token);
        $this->request('DELETE', '/posts/'.$deleted->getId()->toRfc4122(), token: $authorToken);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', $this->listPath($comuna), token: $token)['items']);
        self::assertSame(0, $this->pinCount($comuna));

        $suspended = $this->createPost($this->managedUser($author), $this->managedComuna($comuna), 'Conta some');
        $this->request('PUT', $this->pinPath($comuna, $suspended), token: $token);
        $this->em()->clear();
        $account = $this->em()->find(User::class, $author->getId());
        self::assertInstanceOf(User::class, $account);
        $account->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $list = $this->request('GET', $this->listPath($comuna), token: $token);
        self::assertSame([], $list['items']);
        self::assertStringNotContainsString('Conta some', (string) json_encode($list));
        self::assertSame(1, $this->pinCount($comuna));
        $account = $this->fresh(User::class, $author->getId());
        $account->changeStatus(AccountStatus::Active, new \DateTimeImmutable());
        $this->em()->flush();
        $restored = $this->request('GET', $this->listPath($comuna), token: $token);
        self::assertSame($suspended->getId()->toRfc4122(), $restored['items'][0]['id']);
    }

    public function testSuspendedModeratorCannotPin(): void
    {
        [$comuna, $token, $post] = $this->moderatorAndPost('Aviso');
        $mod = $this->em()->getRepository(User::class)->findOneBy(['handle' => 'mod']);
        self::assertInstanceOf(User::class, $mod);
        $mod->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $response = $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(403);
        self::assertSame('/errors/account-inactive', $response['type']);
    }

    public function testPrivateComunaAndBlocksDoNotBypassAccess(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $author = $this->createUser('autora');
        $this->addMember($comuna, $author);
        $reader = $this->createUser('leitor');
        $this->addMember($comuna, $reader);
        $outsider = $this->createUser('fora');
        $post = $this->createPost($author, $comuna, 'Ata secreta');
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(200);

        $outside = $this->login('fora@exemplo.org')['accessToken'];
        $hidden = $this->request('GET', $this->listPath($comuna), token: $outside);
        $this->assertStatus(403);
        self::assertSame('/errors/comuna-private', $hidden['type']);
        self::assertStringNotContainsString('Ata secreta', (string) json_encode($hidden));
        $this->request('PUT', $this->pinPath($comuna, $post), token: $outside);
        $this->assertStatus(404);

        $readerToken = $this->login('leitor@exemplo.org')['accessToken'];
        self::assertSame('Ata secreta', $this->request('GET', $this->listPath($comuna), token: $readerToken)['items'][0]['body']);
        $this->em()->persist(new UserBlock($this->managedUser($reader), $this->managedUser($author), new \DateTimeImmutable()));
        $this->em()->flush();
        $blocked = $this->request('GET', $this->listPath($comuna), token: $readerToken);
        self::assertSame([], $blocked['items']);
        self::assertStringNotContainsString('Ata secreta', (string) json_encode($blocked));
        self::assertSame('Ata secreta', $this->request('GET', $this->listPath($comuna), token: $token)['items'][0]['body']);
        self::assertSame(1, $this->pinCount($comuna));
    }

    public function testNewPinComesFirstAndModeratorsCanReorder(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $rules = $this->createPost($owner, $comuna, 'Regras da comuna');
        $meeting = $this->createPost($owner, $comuna, 'Próxima reunião');
        $campaign = $this->createPost($owner, $comuna, 'Campanha atual');
        $this->request('PUT', $this->pinPath($comuna, $rules), token: $token);
        $this->request('PUT', $this->pinPath($comuna, $meeting), token: $token);
        $list = $this->request('PUT', $this->pinPath($comuna, $campaign), token: $token);
        self::assertSame(
            [$campaign->getId()->toRfc4122(), $meeting->getId()->toRfc4122(), $rules->getId()->toRfc4122()],
            array_column($list['items'], 'id'),
        );
        self::assertSame([1, 2, 3], $this->positions($comuna));

        $repeated = $this->request('PUT', $this->pinPath($comuna, $campaign), token: $token);
        self::assertSame(array_column($list['items'], 'id'), array_column($repeated['items'], 'id'));

        $moved = $this->request('PUT', $this->pinPath($comuna, $campaign).'/position', ['position' => 3], $token);
        $this->assertStatus(200);
        self::assertSame(
            [$meeting->getId()->toRfc4122(), $rules->getId()->toRfc4122(), $campaign->getId()->toRfc4122()],
            array_column($moved['items'], 'id'),
        );
        $same = $this->request('PUT', $this->pinPath($comuna, $campaign).'/position', ['position' => 3], $token);
        self::assertSame(array_column($moved['items'], 'id'), array_column($same['items'], 'id'));

        $this->request('DELETE', $this->pinPath($comuna, $meeting), token: $token);
        self::assertSame(
            [$rules->getId()->toRfc4122(), $campaign->getId()->toRfc4122()],
            array_column($this->request('GET', $this->listPath($comuna), token: $token)['items'], 'id'),
        );
        self::assertSame([1, 2], $this->positions($comuna));

        $missing = $this->request('PUT', $this->pinPath($comuna, $meeting).'/position', ['position' => 1], $token);
        $this->assertStatus(409);
        self::assertSame('/errors/post-not-pinned', $missing['type']);
    }

    public function testArchivedComunaRejectsPinChanges(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $pinned = $this->createPost($owner, $comuna, 'Histórico');
        $fresh = $this->createPost($owner, $comuna, 'Novo');
        $this->request('PUT', $this->pinPath($comuna, $pinned), token: $token);
        $this->request('POST', '/comunas/tarifa/archive', token: $token);
        $this->assertStatus(200);

        $response = $this->request('PUT', $this->pinPath($comuna, $fresh), token: $token);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-archived', $response['type']);
        $this->request('DELETE', $this->pinPath($comuna, $pinned), token: $token);
        $this->assertStatus(409);
        self::assertSame('Histórico', $this->request('GET', $this->listPath($comuna), token: $token)['items'][0]['body']);
    }

    public function testDeletingTheComunaRemovesPins(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $post = $this->createPost($owner, $comuna, 'Único aviso');
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(200);
        $this->request('DELETE', '/comunas/tarifa', token: $token);
        $this->assertStatus(204);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM comuna_pinned_post'));
    }

    public function testPinDoesNotCreateANotification(): void
    {
        [$comuna, $token, $post] = $this->moderatorAndPost('Sem aviso');
        $before = $this->em()->getRepository(Notification::class)->count([]);
        $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(200);
        $this->request('DELETE', $this->pinPath($comuna, $post), token: $token);
        $this->assertStatus(204);
        $this->em()->clear();
        self::assertSame($before, $this->em()->getRepository(Notification::class)->count([]));
    }

    public function testPinnedListReusesThePostShape(): void
    {
        [$comuna, $token, $post] = $this->moderatorAndPost('Próxima reunião da comuna');
        $page = $this->request('PUT', $this->pinPath($comuna, $post), token: $token);
        self::assertNull($page['nextCursor']);
        self::assertSame(
            ['id', 'body', 'createdAt', 'author', 'comuna', 'commentCount', 'viewer', 'topic', 'document', 'media', 'poll', 'mentions', 'event'],
            array_keys($page['items'][0]),
        );
        self::assertNull($page['items'][0]['event']);
        self::assertSame('Próxima reunião da comuna', $page['items'][0]['body']);
        self::assertSame('tarifa', $page['items'][0]['comuna']['slug']);
    }

    /** @return array{0: Comuna, 1: string, 2: Post} */
    private function moderatorAndPost(string $body): array
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $this->addMember($comuna, $this->createUser('mod'), ComunaRole::Moderator);
        $post = $this->createPost($owner, $comuna, $body);

        return [$comuna, $this->login('mod@exemplo.org')['accessToken'], $post];
    }

    private function pinPath(Comuna $comuna, Post $post): string
    {
        return $this->listPath($comuna).'/'.$post->getId()->toRfc4122();
    }

    private function listPath(Comuna $comuna): string
    {
        return '/comunas/'.$comuna->getSlug().'/pinned-posts';
    }

    private function pinCount(Comuna $comuna): int
    {
        $this->em()->clear();

        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM comuna_pinned_post WHERE comuna_id = ?',
            [$comuna->getId()->toRfc4122()],
        );
    }

    /** @return list<int> */
    private function positions(Comuna $comuna): array
    {
        $this->em()->clear();
        /** @var list<string|int> $positions */
        $positions = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT position FROM comuna_pinned_post WHERE comuna_id = ? ORDER BY position',
            [$comuna->getId()->toRfc4122()],
        );

        return array_map(intval(...), $positions);
    }

    private function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    private function managedUser(User $user): User
    {
        $managed = $this->em()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $managed);

        return $managed;
    }

    private function managedComuna(Comuna $comuna): Comuna
    {
        $managed = $this->em()->find(Comuna::class, $comuna->getId());
        self::assertInstanceOf(Comuna::class, $managed);

        return $managed;
    }

    /** @param array{host?: string, port?: int|string, dbname?: string, user?: string, password?: string|null} $params */
    private static function pdo(array $params): \PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $params['host'] ?? 'localhost',
            (int) ($params['port'] ?? 5432),
            $params['dbname'] ?? '',
        );
        $pdo = new \PDO($dsn, (string) ($params['user'] ?? ''), (string) ($params['password'] ?? ''));
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
