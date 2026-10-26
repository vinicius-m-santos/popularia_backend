<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Comuna;
use App\Enum\AccountStatus;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Tests\ApiTestCase;

final class DiscoveryTest extends ApiTestCase
{
    public function testSearchPeopleHandleAccentsPartialAndPagination(): void
    {
        $this->createUser('viewer');
        $ana = $this->createUser('devlinux');
        $ana->updateProfile('Álvaro Linux', null, null, ['tec'], new \DateTimeImmutable());
        $this->createUser('linuxoutra');
        $this->em()->flush();
        $token = $this->login('viewer@exemplo.org')['accessToken'];
        $page = $this->request('GET', '/search?type=people&q=LINUX&limit=1', token: $token);
        $this->assertStatus(200);
        self::assertCount(1, $page['people']);
        self::assertNotNull($page['next']['people']);
        $next = $this->request('GET', '/search?type=people&q=linux&limit=1&cursor='.$page['next']['people'], token: $token);
        self::assertCount(1, $next['people']);
        self::assertNotSame($page['people'][0]['id'], $next['people'][0]['id']);
        self::assertNull($next['next']['people']);
        $accent = $this->request('GET', '/search?type=people&q=alvaro', token: $token);
        self::assertSame('devlinux', $accent['people'][0]['handle']);
        self::assertArrayNotHasKey('interests', $accent['people'][0]);
        $handle = $this->request('GET', '/search?q=%40devlin', token: $token);
        self::assertCount(1, $handle['people']);
        self::assertSame([], $this->request('GET', '/search?q=viewer', token: $token)['people']);
    }

    public function testPublicPrivateRemovedArchivedAndBilateralBlocks(): void
    {
        $viewer = $this->createUser('viewer');
        $author = $this->createUser('linuxauthor');
        $public = $this->createComuna('linux-publica');
        $private = $this->createComuna('linux-privada', ComunaVisibility::Private);
        $archived = $this->createComuna('linux-arquivada');
        $this->createPost($author, $public, 'Linux público');
        $this->createPost($author, $private, 'Linux segredo');
        $removed = $this->createPost($author, null, 'Linux removido');
        $removed->removeByModeration();
        $deleted = $this->createPost($author, null, 'Linux apagado');
        $deleted->deleteByAuthor();
        $this->createPost($author, $archived, 'Linux histórico');
        $archived->archive(new \DateTimeImmutable());
        $this->em()->flush();
        $token = $this->login('viewer@exemplo.org')['accessToken'];
        $result = $this->request('GET', '/search?q=linux', token: $token);
        $this->assertStatus(200);
        self::assertSame(['Linux público'], array_column($result['posts'], 'body'));
        self::assertCount(2, $result['comunas']);
        self::assertSame(['Linux público'], array_column($this->request('GET', '/explore', token: $token)['posts'], 'body'));
        $managedComuna = $this->fresh(Comuna::class, $private->getId());
        $managedViewer = $this->em()->find(\App\Entity\User::class, $viewer->getId());
        self::assertNotNull($managedViewer);
        $this->addMember($managedComuna, $managedViewer);
        self::assertCount(2, $this->request('GET', '/search?q=linux&type=posts', token: $token)['posts']);
        self::assertSame(['Linux público'], array_column($this->request('GET', '/explore?topic=comun', token: $token)['recentPosts'], 'body'));
        // Perdendo membership, a consulta seguinte não mantém acesso.
        $this->request('DELETE', '/comunas/linux-privada/membership', token: $token);
        self::assertCount(1, $this->request('GET', '/search?q=linux&type=posts', token: $token)['posts']);
        $otherToken = $this->login('linuxauthor@exemplo.org')['accessToken'];
        $this->request('PUT', '/me/blocks/viewer', token: $otherToken);
        foreach (['/search?q=linux', '/explore', '/explore?topic=comun', '/search?q=linux&topic=comun'] as $uri) {
            $result = $this->request('GET', $uri, token: $token);
            self::assertSame([], $result['posts']);
            self::assertSame([], $result['people']);
            self::assertSame([], $result['recentPosts']);
        }
    }

    public function testTopicEditAuthorizationAndDynamicInheritance(): void
    {
        $owner = $this->createUser('owner');
        $moderator = $this->createUser('moderator');
        $member = $this->createUser('member');
        $comuna = $this->createComuna('linux', owner: $owner);
        $comuna->changeTopic('tec');
        $this->addMember($comuna, $moderator, ComunaRole::Moderator);
        $this->addMember($comuna, $member);
        $this->createPost($owner, $comuna, 'Linux na comuna');
        $this->em()->flush();
        $token = $this->login('owner@exemplo.org')['accessToken'];
        self::assertCount(1, $this->request('GET', '/search?q=linux&topic=tec', token: $token)['posts']);
        foreach (['moderator', 'member'] as $handle) {
            $this->request('PATCH', '/comunas/linux', ['topic' => 'trab'], $this->login($handle.'@exemplo.org')['accessToken'], 'application/merge-patch+json');
            $this->assertStatus(403);
        }
        $result = $this->request('PATCH', '/comunas/linux', ['topic' => 'trab'], $token, 'application/merge-patch+json');
        $this->assertStatus(200);
        self::assertSame(['trab'], $result['topics']);
        self::assertSame('trab', $result['primaryTopic']['id']);
        self::assertSame([], $this->request('GET', '/search?q=linux&topic=tec', token: $token)['posts']);
        $moved = $this->request('GET', '/search?q=linux&topic=trab', token: $token);
        self::assertCount(1, $moved['posts']);
        self::assertSame('trab', $moved['posts'][0]['topic']);
        self::assertCount(1, $this->request('GET', '/explore?topic=trab', token: $token)['recentPosts']);
        $this->request('PATCH', '/comunas/linux', ['topic' => 'inventado'], $token, 'application/merge-patch+json');
        $this->assertStatus(422);
        $this->request('POST', '/comunas/linux/archive', token: $token);
        $this->assertStatus(200);
        $this->request('PATCH', '/comunas/linux', ['topic' => 'tec'], $token, 'application/merge-patch+json');
        $this->assertStatus(409);
    }

    public function testGeneralPostTopicAndValidation(): void
    {
        $owner = $this->createUser('owner');
        $comuna = $this->createComuna('linux', owner: $owner);
        $token = $this->login('owner@exemplo.org')['accessToken'];
        $post = $this->request('POST', '/posts', ['body' => 'Discussão sobre Linux', 'topic' => 'tec'], $token);
        $this->assertStatus(201);
        self::assertSame('tec', $post['topic']);
        $this->request('POST', '/posts', ['body' => 'Discussão sem assunto'], $token);
        self::assertCount(2, $this->request('GET', '/search?q=discussao', token: $token)['posts']);
        self::assertCount(1, $this->request('GET', '/search?q=discussao&topic=tec', token: $token)['posts']);
        $this->request('POST', '/posts', ['body' => 'Linux', 'topic' => 'tec', 'comunaId' => $comuna->getId()->toRfc4122()], $token);
        $this->assertStatus(400);
        foreach (['/search?q=li', '/search?q=%25%25%25', '/search?type=unknown', '/search?topic=unknown', '/search?topic=tec&type=people', '/search?limit=999', '/search?cursor=invalid'] as $uri) {
            $this->request('GET', $uri, token: $token);
            $this->assertStatus(400);
        }
        $this->request('POST', '/posts', ['body' => 'Linux', 'topic' => 'inventado'], $token);
        $this->assertStatus(422);
        $this->request('GET', '/explore');
        $this->assertStatus(401);
    }

    public function testDiscoveryWorksWithoutInterestsAndExcludesFollowedSelfInactive(): void
    {
        $this->createUser('viewer');
        $this->createUser('followed');
        $inactive = $this->createUser('inactive');
        $this->createPost($inactive, null, 'Linux inativo');
        $inactive->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->createUser('suggested');
        $this->em()->flush();
        $token = $this->login('viewer@exemplo.org')['accessToken'];
        $this->request('PUT', '/users/followed/follow', token: $token);
        $result = $this->request('GET', '/explore', token: $token);
        $this->assertStatus(200);
        self::assertSame(['suggested'], array_column($result['people'], 'handle'));
        self::assertCount(18, $result['topics']);
        self::assertSame([], $result['posts']);
        self::assertSame([], $this->request('GET', '/search?q=linux', token: $token)['posts']);
    }

    public function testRankingUsesAffinityWithoutFilteringAndExpiresOldEngagement(): void
    {
        $viewer = $this->createUser('viewer');
        $viewer->updateProfile(null, null, null, ['tec'], new \DateTimeImmutable());
        $author = $this->createUser('author');
        $preferred = $this->createComuna('tecnologia');
        $preferred->changeTopic('tec');
        $other = $this->createComuna('outros');
        $old = new \App\Entity\Post($author, $preferred, 'Linux antigo', new \DateTimeImmutable('-30 days'));
        $old->setTopic(null);
        $this->em()->persist($old);
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('UPDATE post SET comment_count = 10000 WHERE id = ?', [$old->getId()->toRfc4122()]);
        $this->createPost($author, $other, 'Linux recente');
        $this->createPost($author, $preferred, 'Linux afinidade');
        $this->em()->flush();
        $token = $this->login('viewer@exemplo.org')['accessToken'];
        $result = $this->request('GET', '/explore', token: $token);
        $this->assertStatus(200);
        self::assertSame('tecnologia', $result['comunas'][0]['slug']);
        self::assertCount(2, $result['comunas']);
        self::assertSame(['Linux afinidade', 'Linux recente'], array_column($result['posts'], 'body'));
    }

    public function testComunaDescriptionAndPostPartialAccentedSearchHaveStablePages(): void
    {
        $author = $this->createUser('viewer');
        for ($i = 0; $i < 5; ++$i) {
            $comuna = $this->createComuna('linux-'.$i);
            $this->createPost($author, $comuna, 'Debate de Computação '.$i);
        }
        $token = $this->login('viewer@exemplo.org')['accessToken'];
        $all = $this->request('GET', '/search?q=linux', token: $token);
        self::assertCount(3, $all['comunas']);
        foreach (['comunas' => 'DESCRICAO', 'posts' => 'computac'] as $kind => $term) {
            $ids = [];
            $cursor = '';
            do {
                $result = $this->request('GET', '/search?type='.$kind.'&q='.$term.'&limit=2'.$cursor, token: $token);
                $this->assertStatus(200);
                $ids = array_merge($ids, array_column($result[$kind], 'id'));
                $next = $result['next'][$kind];
                $cursor = null === $next ? '' : '&cursor='.$next;
            } while ('' !== $cursor);
            self::assertCount(5, $ids);
            self::assertCount(5, array_unique($ids));
        }
    }

    public function testBlockedOwnerIdentityDoesNotLeakInCollectiveComunaPreview(): void
    {
        $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $this->createComuna('linux', owner: $owner);
        $token = $this->login('viewer@exemplo.org')['accessToken'];
        $this->request('PUT', '/me/blocks/owner', token: $token);
        $result = $this->request('GET', '/search?q=linux', token: $token);
        self::assertCount(1, $result['comunas']);
        self::assertNull($result['comunas'][0]['owner']);
    }
}
