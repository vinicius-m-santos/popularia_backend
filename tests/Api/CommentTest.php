<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Post;
use App\Tests\ApiTestCase;

final class CommentTest extends ApiTestCase
{
    public function testCommentAndReplyBuildAThread(): void
    {
        $comuna = $this->createComuna('tarifa');
        $author = $this->createUser('camila');
        $this->addMember($comuna, $author);
        $post = $this->createPost($author, $comuna);
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $uri = '/posts/'.$post->getId()->toRfc4122().'/comments';

        $first = $this->request('POST', $uri, ['body' => 'Primeiro!'], $token);
        $this->assertStatus(201);
        $reply = $this->request('POST', $uri, ['body' => 'Resposta', 'parentId' => $first['id']], $token);
        $this->assertStatus(201);

        $page = $this->request('GET', $uri);
        $this->assertStatus(200);
        self::assertSame([[$first['id'], null], [$reply['id'], $first['id']]], array_map(static fn ($c) => [$c['id'], $c['parentId']], $page['items']));
        self::assertSame(2, $this->fresh(Post::class, $post->getId())->getCommentCount());
    }

    public function testParentMustBelongToSamePost(): void
    {
        $author = $this->createUser('camila');
        $postA = $this->createPost($author, null, 'A');
        $postB = $this->createPost($author, null, 'B');
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $onA = $this->request('POST', '/posts/'.$postA->getId()->toRfc4122().'/comments', ['body' => 'em A'], $token);

        $body = $this->request('POST', '/posts/'.$postB->getId()->toRfc4122().'/comments', ['body' => 'em B', 'parentId' => $onA['id']], $token);

        $this->assertStatus(422);
        self::assertSame('parentId', $body['violations'][0]['propertyPath']);
    }

    public function testNonMemberCannotCommentInComuna(): void
    {
        $comuna = $this->createComuna('tarifa');
        $author = $this->createUser('camila');
        $this->addMember($comuna, $author);
        $this->createUser('visitante');
        $post = $this->createPost($author, $comuna);

        $body = $this->request('POST', '/posts/'.$post->getId()->toRfc4122().'/comments', ['body' => 'Oi'], $this->login('visitante@exemplo.org')['accessToken']);

        $this->assertStatus(403);
        self::assertSame('/errors/not-a-member', $body['type']);
    }

    public function testCommentsOnInvisiblePostAre404(): void
    {
        $this->createUser('camila');

        $this->request('GET', '/posts/01a17687-b285-7504-9ccc-697c1ab5b90d/comments');
        $this->assertStatus(404);
        $this->request('POST', '/posts/01a17687-b285-7504-9ccc-697c1ab5b90d/comments', ['body' => 'Oi'], $this->login('camila@exemplo.org')['accessToken']);
        $this->assertStatus(404);
    }
}
