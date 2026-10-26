<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class BlockTest extends ApiTestCase
{
    public function testBlockHidesContentBothWays(): void
    {
        $comuna = $this->createComuna('tarifa');
        $me = $this->createUser('camila');
        $other = $this->createUser('assediador');
        $this->addMember($comuna, $me);
        $this->addMember($comuna, $other);
        $theirPost = $this->createPost($other, $comuna, 'post dele');
        $myPost = $this->createPost($me, $comuna, 'post meu');
        $mine = $this->login('camila@exemplo.org')['accessToken'];
        $theirs = $this->login('assediador@exemplo.org')['accessToken'];

        $this->request('PUT', '/me/blocks/assediador', token: $mine);
        $this->assertStatus(204);

        // Para mim: o conteúdo e o perfil dele somem.
        self::assertSame(['post meu'], array_column($this->request('GET', '/comunas/tarifa/posts', token: $mine)['items'], 'body'));
        self::assertSame(['post meu'], array_column($this->request('GET', '/feed', token: $mine)['items'], 'body'));
        $this->request('GET', '/posts/'.$theirPost->getId()->toRfc4122(), token: $mine);
        $this->assertStatus(404);
        $this->request('GET', '/users/assediador', token: $mine);
        $this->assertStatus(404);

        // Para ele: o meu também some, e ele não consegue comentar no que eu publiquei.
        self::assertSame(['post dele'], array_column($this->request('GET', '/feed', token: $theirs)['items'], 'body'));
        $this->request('POST', '/posts/'.$myPost->getId()->toRfc4122().'/comments', ['body' => 'oi'], $theirs);
        $this->assertStatus(404);
        $this->request('GET', '/users/camila', token: $theirs);
        $this->assertStatus(404);

        $blocks = $this->request('GET', '/me/blocks', token: $mine);
        self::assertSame('assediador', $blocks[0]['handle']);
        self::assertSame([], $this->request('GET', '/me/blocks', token: $theirs));
    }

    public function testBlockedPersonsCommentsBecomePlaceholders(): void
    {
        $me = $this->createUser('camila');
        $other = $this->createUser('outra');
        $third = $this->createUser('terceira');
        $post = $this->createPost($third, null, 'conversa');
        $uri = '/posts/'.$post->getId()->toRfc4122().'/comments';
        $comment = $this->request('POST', $uri, ['body' => 'comentário da outra'], $this->login('outra@exemplo.org')['accessToken']);
        $mine = $this->login('camila@exemplo.org')['accessToken'];
        $this->request('PUT', '/me/blocks/outra', token: $mine);

        $page = $this->request('GET', $uri, token: $mine);

        self::assertSame(['id' => $comment['id'], 'status' => 'hidden', 'body' => null, 'author' => null], array_intersect_key($page['items'][0], array_flip(['id', 'status', 'body', 'author'])));
        $this->request('POST', $uri, ['body' => 'resposta', 'parentId' => $comment['id']], $mine);
        $this->assertStatus(422);
        unset($me, $other);
    }

    public function testCannotBlockSelfAndUnblockWorks(): void
    {
        $this->createUser('camila');
        $this->createUser('outra');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $this->request('PUT', '/me/blocks/camila', token: $token);
        $this->assertStatus(400);

        $this->request('PUT', '/me/blocks/outra', token: $token);
        $this->request('PUT', '/me/blocks/outra', token: $token);
        $this->assertStatus(204);
        self::assertCount(1, $this->request('GET', '/me/blocks', token: $token));
        $this->request('DELETE', '/me/blocks/outra', token: $token);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', '/me/blocks', token: $token));
        $this->request('GET', '/users/outra', token: $token);
        $this->assertStatus(200);
    }
}
