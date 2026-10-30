<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Comuna;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\UserFollow;
use App\Enum\AccountStatus;
use App\Enum\ComunaVisibility;
use App\Tests\ApiTestCase;

final class HashtagTest extends ApiTestCase
{
    public function testCreationNormalizationCursorAndDelete(): void
    {
        $this->createUser('ana');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $first = $this->request('POST', '/posts', ['body' => '#Política #politica'], $token);
        $this->assertStatus(201);
        $second = $this->request('POST', '/posts', ['body' => 'Debate sobre #POLITICA'], $token);
        $this->assertStatus(201);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM hashtag'));
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM post_hashtag'));
        $topic = $this->request('GET', '/topics/politica');
        self::assertSame('Política', $topic['displayName']);
        $page = $this->request('GET', '/topics/politica/posts?limit=1');
        self::assertSame([$second['id']], array_column($page['items'], 'id'));
        self::assertNotNull($page['nextCursor']);
        $next = $this->request('GET', '/topics/politica/posts?limit=1&cursor='.$page['nextCursor']);
        self::assertSame([$first['id']], array_column($next['items'], 'id'));
        self::assertNull($next['nextCursor']);
        $this->request('DELETE', '/posts/'.$first['id'], token: $token);
        $this->assertStatus(204);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM post_hashtag'));
        self::assertSame([$second['id']], array_column($this->request('GET', '/topics/politica/posts')['items'], 'id'));
    }

    public function testVisibilityIsFilteredBeforeLimitAndTopicNameDoesNotLeak(): void
    {
        $public = $this->createComuna('aberta');
        $private = $this->createComuna('fechada', ComunaVisibility::Private);
        $author = $this->createUser('ana');
        $this->createUser('bia');
        $this->addMember($public, $author);
        $this->addMember($private, $author);
        $authorToken = $this->login('ana@exemplo.org')['accessToken'];
        $viewerToken = $this->login('bia@exemplo.org')['accessToken'];
        $visible = $this->request('POST', '/posts', ['comunaId' => $public->getId()->toRfc4122(), 'body' => '#Trabalho público'], $authorToken);
        $hidden = $this->request('POST', '/posts', ['comunaId' => $private->getId()->toRfc4122(), 'body' => '#Trabalho privado'], $authorToken);
        self::assertSame([$visible['id']], array_column($this->request('GET', '/topics/trabalho/posts?limit=1', token: $viewerToken)['items'], 'id'));
        self::assertSame([], $this->request('GET', '/topics/trabalho/posts?cursor='.$visible['id'], token: $viewerToken)['items']);
        $this->request('GET', '/topics/trabalho', token: $viewerToken);
        $this->assertStatus(200);
        $this->request('PUT', '/me/blocks/ana', token: $viewerToken);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', '/topics/trabalho/posts', token: $viewerToken)['items']);
        $this->request('GET', '/topics/trabalho', token: $viewerToken);
        $this->assertStatus(404);
        self::assertNotSame($visible['id'], $hidden['id']);
    }

    public function testPrivateProfileFollowModerationAndSuspension(): void
    {
        $author = $this->createUser('ana');
        $this->createUser('bia');
        $author->setPrivate(true, new \DateTimeImmutable());
        $this->em()->flush();
        $authorToken = $this->login('ana@exemplo.org')['accessToken'];
        $viewerToken = $this->login('bia@exemplo.org')['accessToken'];
        $post = $this->request('POST', '/posts', ['body' => '#Privacidade debate'], $authorToken);
        $this->assertStatus(201);
        self::assertSame([], $this->request('GET', '/topics/privacidade/posts', token: $viewerToken)['items']);
        $this->request('GET', '/topics/privacidade', token: $viewerToken);
        $this->assertStatus(404);
        $author = $this->fresh(User::class, $author->getId());
        $viewer = $this->em()->getRepository(User::class)->findOneBy(['handle' => 'bia']);
        self::assertNotNull($viewer);
        $this->em()->persist(new UserFollow($viewer, $author, new \DateTimeImmutable(), true));
        $this->em()->flush();
        self::assertSame([$post['id']], array_column($this->request('GET', '/topics/privacidade/posts', token: $viewerToken)['items'], 'id'));
        $stored = $this->fresh(Post::class, $post['id']);
        $stored->removeByModeration();
        $this->em()->flush();
        self::assertSame([], $this->request('GET', '/topics/privacidade/posts', token: $viewerToken)['items']);
        $stored = $this->fresh(Post::class, $post['id']);
        $stored->restoreByModeration();
        $this->em()->flush();
        self::assertSame([$post['id']], array_column($this->request('GET', '/topics/privacidade/posts', token: $viewerToken)['items'], 'id'));
        $author = $this->fresh(User::class, $author->getId());
        $author->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        self::assertSame([], $this->request('GET', '/topics/privacidade/posts', token: $viewerToken)['items']);
    }

    public function testPrivateComunaRequiresMembershipAndSuspendedComunaDisappears(): void
    {
        $author = $this->createUser('ana');
        $viewer = $this->createUser('bia');
        $comuna = $this->createComuna('reservada', ComunaVisibility::Private);
        $this->addMember($comuna, $author);
        $authorToken = $this->login('ana@exemplo.org')['accessToken'];
        $viewerToken = $this->login('bia@exemplo.org')['accessToken'];
        $post = $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => '#Reserva'], $authorToken);
        self::assertSame([], $this->request('GET', '/topics/reserva/posts', token: $viewerToken)['items']);
        $comuna = $this->fresh(Comuna::class, $comuna->getId());
        $viewer = $this->em()->find(User::class, $viewer->getId());
        self::assertNotNull($viewer);
        $this->addMember($comuna, $viewer);
        self::assertSame([$post['id']], array_column($this->request('GET', '/topics/reserva/posts', token: $viewerToken)['items'], 'id'));
        $comuna = $this->fresh(Comuna::class, $comuna->getId());
        $comuna->suspend();
        $this->em()->flush();
        self::assertSame([], $this->request('GET', '/topics/reserva/posts', token: $viewerToken)['items']);
    }

    public function testPollOptionsDoNotConsumePageLimit(): void
    {
        $this->createUser('ana');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $older = $this->request('POST', '/posts', ['body' => '#Consulta primeira'], $token);
        $newer = $this->request('POST', '/posts', ['body' => '#Consulta segunda', 'poll' => [
            'question' => 'Qual caminho?', 'options' => [['label' => 'Um'], ['label' => 'Dois'], ['label' => 'Três']],
        ]], $token);
        $this->assertStatus(201);
        $first = $this->request('GET', '/topics/consulta/posts?limit=1');
        self::assertSame([$newer['id']], array_column($first['items'], 'id'));
        self::assertNotNull($first['nextCursor']);
        $second = $this->request('GET', '/topics/consulta/posts?limit=1&cursor='.$first['nextCursor']);
        self::assertSame([$older['id']], array_column($second['items'], 'id'));
    }

    public function testInvalidHashtagsRejectWholePublication(): void
    {
        $this->createUser('ana');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $tooMany = $this->request('POST', '/posts', ['body' => implode(' ', array_fill(0, 11, '#tema'))], $token);
        $this->assertStatus(400);
        self::assertSame('/errors/hashtag-limit', $tooMany['type']);
        $tooLong = $this->request('POST', '/posts', ['body' => '#'.str_repeat('a', 51)], $token);
        $this->assertStatus(400);
        self::assertSame('/errors/hashtag-length', $tooLong['type']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM post'));
    }

    public function testPublicPreviewUsesOnlyAnonymouslyVisiblePosts(): void
    {
        $this->createUser('ana');
        $private = $this->createUser('bia');
        $private->setPrivate(true, new \DateTimeImmutable());
        $this->em()->flush();
        $this->request('POST', '/posts', ['body' => '#Política'], $this->login('ana@exemplo.org')['accessToken']);
        $this->request('POST', '/posts', ['body' => '#Sigilo CONTEUDO-PRIVADO'], $this->login('bia@exemplo.org')['accessToken']);
        $this->client->request('GET', '/t/politica');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[property="og:title"][content="#Política no Popularia"]');
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost:8080/t/politica"]');
        $this->client->request('GET', '/t/sigilo');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('CONTEUDO-PRIVADO', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('#Sigilo', (string) $this->client->getResponse()->getContent());
    }

    public function testUnicodeSlugWorksInApiAndPublicLink(): void
    {
        $this->createUser('ana');
        $post = $this->request('POST', '/posts', ['body' => '#SãoPaulo #東京 #किताब'], $this->login('ana@exemplo.org')['accessToken']);
        $this->assertStatus(201);
        self::assertSame([$post['id']], array_column($this->request('GET', '/topics/saopaulo/posts')['items'], 'id'));
        self::assertSame([$post['id']], array_column($this->request('GET', '/topics/'.rawurlencode('東京').'/posts')['items'], 'id'));
        self::assertSame([$post['id']], array_column($this->request('GET', '/topics/'.rawurlencode('किताब').'/posts')['items'], 'id'));
        $this->client->request('GET', '/t/'.rawurlencode('東京'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[property="og:title"][content="#東京 no Popularia"]');
        $this->client->request('GET', '/t/'.rawurlencode('किताब'));
        self::assertResponseIsSuccessful();
    }
}
