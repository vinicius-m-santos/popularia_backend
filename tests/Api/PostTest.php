<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Post;
use App\Entity\PostMedia;
use App\Enum\ComunaVisibility;
use App\Enum\ContentStatus;
use App\Tests\ApiTestCase;

final class PostTest extends ApiTestCase
{
    public function testMemberPublishesAndAuthorshipComesFromToken(): void
    {
        $comuna = $this->createComuna('tarifa');
        $author = $this->createUser('camila');
        $this->addMember($comuna, $author);
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $post = $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => "  Assembleia sábado às 10h.\r\nTragam ideias!  "], $token);

        $this->assertStatus(201);
        self::assertSame("Assembleia sábado às 10h.\nTragam ideias!", $post['body']);
        self::assertSame('camila', $post['author']['handle']);
        self::assertSame(['id' => $comuna->getId()->toRfc4122(), 'slug' => 'tarifa', 'name' => 'Tarifa', 'status' => 'active'], $post['comuna']);
        self::assertSame(0, $post['commentCount']);
        self::assertSame(['isAuthor' => true, 'canComment' => true, 'canDelete' => true, 'canRemove' => false], $post['viewer']);
        self::assertSame(['id', 'body', 'createdAt', 'author', 'comuna', 'commentCount', 'viewer', 'topic', 'document', 'media'], array_keys($post));
        self::assertSame(['id', 'handle', 'displayName'], array_keys($post['author']));
        self::assertTrue($this->fresh(Post::class, $post['id'])->getAuthor()->getId()->equals($author->getId()));
    }

    public function testClientCannotSetAuthorOrCounters(): void
    {
        $comuna = $this->createComuna('tarifa');
        $author = $this->createUser('camila');
        $other = $this->createUser('outra');
        $this->addMember($comuna, $author);
        $token = $this->login('camila@exemplo.org')['accessToken'];

        foreach ([['authorId' => $other->getId()->toRfc4122()], ['commentCount' => 99], ['status' => 'published'], ['id' => '00000000-0000-0000-0000-000000000001']] as $extra) {
            $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => 'Oi'] + $extra, $token);
            $this->assertStatus(400);
        }
        self::assertSame(0, $this->em()->getRepository(Post::class)->count([]));
    }

    public function testNonMemberCannotPublishInComuna(): void
    {
        $comuna = $this->createComuna('tarifa');
        $this->createUser('camila');

        $body = $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => 'Oi'], $this->login('camila@exemplo.org')['accessToken']);

        $this->assertStatus(403);
        self::assertSame('/errors/not-a-member', $body['type']);
    }

    public function testUnverifiedEmailCannotPublish(): void
    {
        $this->createUser('camila', verified: false);

        $body = $this->request('POST', '/posts', ['comunaId' => null, 'body' => 'Oi'], $this->login('camila@exemplo.org')['accessToken']);

        $this->assertStatus(403);
        self::assertSame('/errors/email-not-verified', $body['type']);
    }

    public function testPublishingRequiresAuthentication(): void
    {
        $this->request('POST', '/posts', ['comunaId' => null, 'body' => 'Oi']);

        $this->assertStatus(401);
    }

    public function testInvalidBodyIsRejected(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $this->request('POST', '/posts', ['body' => '   '], $token);
        $this->assertStatus(422);
        $this->request('POST', '/posts', ['body' => str_repeat('a', 1001)], $token);
        $this->assertStatus(422);
        $this->request('POST', '/posts', ['body' => "texto\u{0007}com controle"], $token);
        $this->assertStatus(422);
    }

    public function testReadyMediaCanBeAttachedOnlyOnceAndIsAlternativeToPdf(): void
    {
        $author = $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $media = new PostMedia($author, \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'post-media/test.jpg', 'image', 'image/jpeg', str_repeat('a', 64), 100, 20, 10, null, new \DateTimeImmutable());
        $media->status = 'ready';
        $this->em()->persist($media);
        $this->em()->flush();

        $post = $this->request('POST', '/posts', ['body' => '', 'mediaId' => $media->id->toRfc4122()], $token);
        $this->assertStatus(201);
        self::assertSame('image', $post['media']['type']);
        self::assertSame(20, $post['media']['width']);
        self::assertArrayNotHasKey('storageKey', $post['media']);

        $problem = $this->request('POST', '/posts', ['body' => 'reuso', 'mediaId' => $media->id->toRfc4122()], $token);
        $this->assertStatus(409);
        self::assertSame('/errors/post-media-attached', $problem['type']);

        $this->request('POST', '/posts', ['body' => 'dois anexos', 'mediaId' => $media->id->toRfc4122(), 'documentId' => \Symfony\Component\Uid\Uuid::v7()->toRfc4122()], $token);
        $this->assertStatus(422);
    }

    public function testMediaFromAnotherAccountIsNotAttachable(): void
    {
        $owner = $this->createUser('dona');
        $this->createUser('camila');
        $media = new PostMedia($owner, \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'post-media/other.mp4', 'video', 'video/mp4', str_repeat('b', 64), 100, 20, 10, 1000, new \DateTimeImmutable());
        $media->status = 'ready';
        $this->em()->persist($media);
        $this->em()->flush();

        $this->request('POST', '/posts', ['body' => '', 'mediaId' => $media->id->toRfc4122()], $this->login('camila@exemplo.org')['accessToken']);
        $this->assertStatus(404);
    }

    public function testOnlyAuthorDeletesAndTextIsErased(): void
    {
        $author = $this->createUser('camila');
        $this->createUser('outra');
        $post = $this->createPost($author, null, 'Texto que vai sumir');
        $uri = '/posts/'.$post->getId()->toRfc4122();

        $body = $this->request('DELETE', $uri, token: $this->login('outra@exemplo.org')['accessToken']);
        $this->assertStatus(403);
        self::assertSame('/errors/not-the-author', $body['type']);

        $this->request('DELETE', $uri, token: $this->login('camila@exemplo.org')['accessToken']);
        $this->assertStatus(204);
        $stored = $this->fresh(Post::class, $post->getId());
        self::assertSame(ContentStatus::Deleted, $stored->getStatus());
        self::assertSame('', $stored->getBody());
        $this->request('GET', $uri);
        $this->assertStatus(404);
    }

    public function testFeedTabsAndPagination(): void
    {
        $tarifa = $this->createComuna('tarifa');
        $hortas = $this->createComuna('hortas');
        $fechada = $this->createComuna('fechada', ComunaVisibility::Private);
        $viewer = $this->createUser('camila');
        $author = $this->createUser('autora');
        $this->addMember($tarifa, $viewer);
        $this->createPost($author, $tarifa, 'tarifa 1');
        $this->createPost($author, $hortas, 'hortas 1');
        $this->createPost($author, $fechada, 'fechada 1');
        $this->createPost($author, null, 'perfil 1');
        $this->createPost($author, $tarifa, 'tarifa 2');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $mine = $this->request('GET', '/feed?tab=minhas', token: $token);
        self::assertSame(['tarifa 2', 'tarifa 1'], array_column($mine['items'], 'body'));

        $first = $this->request('GET', '/feed?limit=2', token: $token);
        $second = $this->request('GET', '/feed?limit=2&cursor='.$first['nextCursor'], token: $token);
        $all = array_column([...$first['items'], ...$second['items']], 'body');
        self::assertSame(['tarifa 2', 'perfil 1', 'hortas 1', 'tarifa 1'], $all);
        self::assertNull($second['nextCursor']);

        $this->request('GET', '/feed?tab=outra', token: $token);
        $this->assertStatus(400);
        $this->request('GET', '/feed');
        $this->assertStatus(401);
    }

    public function testRateLimitOnPublishing(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        for ($i = 0; $i < 30; ++$i) {
            $this->request('POST', '/posts', ['body' => 'post '.$i], $token);
            $this->assertStatus(201);
        }
        $this->request('POST', '/posts', ['body' => 'post demais'], $token);

        $this->assertStatus(429);
    }
}
