<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Post;
use App\Entity\User;
use App\Entity\UserBlock;
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
        self::assertSame(1, $first['supportCount']);
        self::assertTrue($first['viewer']['supported']);
        $reply = $this->request('POST', $uri, ['body' => 'Resposta', 'parentId' => $first['id']], $token);
        $this->assertStatus(201);

        $page = $this->request('GET', $uri, token: $token);
        $this->assertStatus(200);
        self::assertSame([[$first['id'], null], [$reply['id'], $first['id']]], array_map(static fn ($c) => [$c['id'], $c['parentId']], $page['items']));
        self::assertSame(2, $this->fresh(Post::class, $post->getId())->getCommentCount());
    }

    public function testAuthorCanRemoveAndRestoreAutomaticSupportIdempotently(): void
    {
        $author = $this->createUser('camila');
        $post = $this->createPost($author, null);
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $comment = $this->request('POST', '/posts/'.$post->getId()->toRfc4122().'/comments', ['body' => 'Comentário'], $token);
        $uri = '/comments/'.$comment['id'].'/support';

        $removed = $this->request('DELETE', $uri, token: $token);
        $this->assertStatus(200);
        self::assertSame(0, $removed['supportCount']);
        self::assertFalse($removed['viewer']['supported']);

        $supported = $this->request('PUT', $uri, [], $token);
        $this->assertStatus(200);
        self::assertSame(1, $supported['supportCount']);
        self::assertTrue($supported['viewer']['supported']);
        $again = $this->request('PUT', $uri, [], $token);
        self::assertSame(1, $again['supportCount']);
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

    public function testMostSupportedTopLevelCommentIsHighlightedInThePostAndInTheFeed(): void
    {
        $author = $this->createUser('camila');
        $this->createUser('ana');
        $biaUser = $this->createUser('bia');
        $this->createUser('caio');
        $post = $this->createPost($author, null, 'Publicação com conversa');
        $postId = $post->getId()->toRfc4122();
        $postToken = $this->login('camila@exemplo.org')['accessToken'];
        $anaToken = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $caioToken = $this->login('caio@exemplo.org')['accessToken'];

        $ana = $this->request('POST', '/posts/'.$postId.'/comments', ['body' => 'Comentário da Ana'], $anaToken);
        $this->assertStatus(201);
        $bia = $this->request('POST', '/posts/'.$postId.'/comments', ['body' => 'Comentário da Bia'], $biaToken);
        $this->assertStatus(201);

        // Empate: fica o mais antigo, para o destaque não mudar de dono sem um apoio novo.
        $tie = $this->request('GET', '/posts/'.$postId, token: $postToken);
        $this->assertStatus(200);
        self::assertSame($ana['id'], $tie['topComment']['id']);
        self::assertSame('Comentário da Ana', $tie['topComment']['body']);
        self::assertSame('ana', $tie['topComment']['author']['handle']);
        self::assertSame(1, $tie['topComment']['supportCount']);
        self::assertSame(['id', 'body', 'supportCount', 'author'], array_keys($tie['topComment']));

        $this->request('PUT', '/comments/'.$bia['id'].'/support', [], $caioToken);
        $this->assertStatus(200);

        // Resposta com mais apoios não rouba o destaque: sem o pai, o trecho perderia o contexto.
        $reply = $this->request('POST', '/posts/'.$postId.'/comments', ['body' => 'Resposta da Bia', 'parentId' => $ana['id']], $biaToken);
        $this->assertStatus(201);
        $this->request('PUT', '/comments/'.$reply['id'].'/support', [], $postToken);
        $this->request('PUT', '/comments/'.$reply['id'].'/support', [], $caioToken);

        $view = $this->request('GET', '/posts/'.$postId, token: $postToken);
        $this->assertStatus(200);
        self::assertSame($bia['id'], $view['topComment']['id']);
        self::assertSame('Comentário da Bia', $view['topComment']['body']);
        self::assertSame('bia', $view['topComment']['author']['handle']);
        self::assertSame(2, $view['topComment']['supportCount']);

        $feed = $this->request('GET', '/feed', token: $postToken);
        $this->assertStatus(200);
        self::assertSame($bia['id'], $feed['items'][0]['topComment']['id']);

        // Bloqueio em qualquer sentido tira o comentário da pessoa do destaque, como já acontece na conversa.
        $this->em()->clear();
        $blocker = $this->em()->find(User::class, $author->getId());
        $blocked = $this->em()->find(User::class, $biaUser->getId());
        self::assertInstanceOf(User::class, $blocker);
        self::assertInstanceOf(User::class, $blocked);
        $this->em()->persist(new UserBlock($blocker, $blocked, new \DateTimeImmutable()));
        $this->em()->flush();

        $withoutBlocked = $this->request('GET', '/posts/'.$postId, token: $postToken);
        $this->assertStatus(200);
        self::assertSame($ana['id'], $withoutBlocked['topComment']['id']);
    }

    public function testAuthorCanSoftDeleteOwnCommentAndOthersCannot(): void
    {
        $author = $this->createUser('camila');
        $other = $this->createUser('outra');
        $post = $this->createPost($author, null);
        $authorToken = $this->login('camila@exemplo.org')['accessToken'];
        $otherToken = $this->login('outra@exemplo.org')['accessToken'];
        $uri = '/posts/'.$post->getId()->toRfc4122().'/comments';
        $comment = $this->request('POST', $uri, ['body' => 'Comentário'], $authorToken);
        $this->assertStatus(201);

        $forbidden = $this->request('DELETE', '/comments/'.$comment['id'], token: $otherToken);
        $this->assertStatus(403);
        self::assertSame('/errors/not-the-author', $forbidden['type']);

        $this->request('DELETE', '/comments/'.$comment['id'], token: $authorToken);
        $this->assertStatus(204);
        $page = $this->request('GET', $uri, token: $authorToken);
        $saved = $page['items'][0];
        self::assertSame('deleted', $saved['status']);
        self::assertNull($saved['body']);
        self::assertNull($saved['author']);
        self::assertSame(0, $this->fresh(Post::class, $post->getId())->getCommentCount());

        // Repetir a exclusão é idempotente.
        $this->request('DELETE', '/comments/'.$comment['id'], token: $authorToken);
        $this->assertStatus(204);
        unset($other);
    }
}
