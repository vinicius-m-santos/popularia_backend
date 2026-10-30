<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Comuna;
use App\Entity\LibraryCollection;
use App\Entity\LibraryDocument;
use App\Entity\LibraryFile;
use App\Enum\AccountStatus;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\ContentStatus;
use App\Enum\LibraryAccess;
use App\Tests\ApiTestCase;
use App\Tests\EventRequests;

final class DiscoveryTest extends ApiTestCase
{
    use EventRequests;

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

    public function testRankingPrefersExactThenPrefixAndKeepsTypoBelow(): void
    {
        $this->createUser('viewer');
        $this->createUser('vinicius');
        $this->createUser('viniciusmore');
        $partial = $this->createUser('outrapessoa');
        $partial->updateProfile('Ana Vinicius Souza', null, null, [], new \DateTimeImmutable());
        $exact = $this->createComuna('popularia');
        $prefix = $this->createComuna('norte');
        $weak = $this->createComuna('notas');
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('UPDATE comuna SET name = ? WHERE id = ?', ['Popularia Norte', $prefix->getId()->toRfc4122()]);
        $this->em()->getConnection()->executeStatement('UPDATE comuna SET description = ? WHERE id = ?', ['Um texto que cita popularia no meio.', $weak->getId()->toRfc4122()]);
        $token = $this->login('viewer@exemplo.org')['accessToken'];

        $people = $this->request('GET', '/search?type=people&q=%40Vinicius', token: $token);
        $this->assertStatus(200);
        self::assertSame(['vinicius', 'viniciusmore', 'outrapessoa'], array_column($people['people'], 'handle'));

        $comunas = $this->request('GET', '/search?type=comunas&q=popularia', token: $token);
        self::assertSame(['popularia', 'norte', 'notas'], array_column($comunas['comunas'], 'slug'));
        self::assertSame($exact->getId()->toRfc4122(), $comunas['comunas'][0]['id']);

        $typo = $this->request('GET', '/search?type=comunas&q=populaira', token: $token);
        self::assertSame(['popularia'], array_column($typo['comunas'], 'slug'));
    }

    public function testPostTextOutranksRecencyAndAccentFoldingIsSymmetric(): void
    {
        $viewer = $this->createUser('viewer');
        $author = $this->createUser('author');
        $exact = $this->createPost($author, null, 'reforma tributaria');
        $prefix = $this->createPost($author, null, 'reforma tributaria comeca amanha');
        $recent = $this->createPost($author, null, 'ontem li a reforma tributaria no jornal');
        $accented = $this->createPost($author, null, 'A política local organiza a praça.');
        $plain = $this->createPost($author, null, 'Politica sem acento neste texto.');
        $this->em()->getConnection()->executeStatement('UPDATE post SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-12 days'))->format('Y-m-d H:i:sP'), $exact->getId()->toRfc4122()]);
        $this->em()->getConnection()->executeStatement('UPDATE post SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable('-8 days'))->format('Y-m-d H:i:sP'), $prefix->getId()->toRfc4122()]);
        $tagged = $this->createPost($author, null, 'Discussão com a tag #tecnologia no texto.');
        $this->em()->flush();
        $token = $this->login('viewer@exemplo.org')['accessToken'];

        $ranked = $this->request('GET', '/search?type=posts&q=reforma%20tributaria', token: $token);
        self::assertSame(
            ['reforma tributaria', 'reforma tributaria comeca amanha', 'ontem li a reforma tributaria no jornal'],
            array_column($ranked['posts'], 'body'),
        );
        self::assertNotSame($recent->getId()->toRfc4122(), $ranked['posts'][0]['id']);

        $plainQuery = array_column($this->request('GET', '/search?type=posts&q=politica', token: $token)['posts'], 'id');
        $accentQuery = array_column($this->request('GET', '/search?type=posts&q='.rawurlencode('política'), token: $token)['posts'], 'id');
        self::assertEqualsCanonicalizing([$accented->getId()->toRfc4122(), $plain->getId()->toRfc4122()], $plainQuery);
        self::assertEqualsCanonicalizing([$accented->getId()->toRfc4122(), $plain->getId()->toRfc4122()], $accentQuery);
        $taggedResult = $this->request('GET', '/search?type=posts&q=tecnologia', token: $token);
        self::assertSame([$tagged->getId()->toRfc4122()], array_column($taggedResult['posts'], 'id'));
        unset($viewer);
    }

    public function testNormalizationRejectsShortHugeAndBrokenCursors(): void
    {
        $this->createUser('viewer');
        $person = $this->createUser('linux');
        $person->updateProfile('Política Viva', null, null, [], new \DateTimeImmutable());
        $gone = $this->createUser('apagada');
        $gone->changeStatus(AccountStatus::Deleted, new \DateTimeImmutable());
        $this->em()->flush();
        $token = $this->login('viewer@exemplo.org')['accessToken'];

        $spaced = $this->request('GET', '/search?type=people&q='.rawurlencode('  LINUX  '), token: $token);
        $this->assertStatus(200);
        self::assertSame(['linux'], array_column($spaced['people'], 'handle'));
        $folded = $this->request('GET', '/search?type=people&q='.rawurlencode('política'), token: $token);
        self::assertSame('linux', $folded['people'][0]['handle']);
        self::assertSame([], $this->request('GET', '/search?type=people&q=apagada', token: $token)['people']);

        foreach (['/search?q='.rawurlencode(str_repeat('a', 101)), '/search?q=ab', '/search?type=events&q=abc&cursor=v1_nope', '/search?type=people&q=linux&cursor='.$person->getId()->toRfc4122()] as $uri) {
            $this->request('GET', $uri, token: $token);
            $this->assertStatus(400);
        }
        $this->request('GET', '/search?type=people&q=', token: $token);
        $this->assertStatus(200);
    }

    public function testRankedPagesDoNotRepeatOrSkip(): void
    {
        $this->createUser('viewer');
        foreach (['linuxaa', 'linuxbb', 'linuxcc', 'linuxdd', 'linuxee'] as $handle) {
            $this->createUser($handle);
        }
        $token = $this->login('viewer@exemplo.org')['accessToken'];
        $ids = [];
        $cursor = '';
        $pages = 0;
        do {
            $result = $this->request('GET', '/search?type=people&q=linux&limit=2'.$cursor, token: $token);
            $this->assertStatus(200);
            if ('' === $cursor) {
                self::assertStringStartsWith('v1_', (string) $result['next']['people']);
            }
            $ids = array_merge($ids, array_column($result['people'], 'id'));
            $next = $result['next']['people'];
            $cursor = null === $next ? '' : '&cursor='.rawurlencode((string) $next);
            ++$pages;
        } while ('' !== $cursor && $pages < 6);
        self::assertCount(5, $ids);
        self::assertCount(5, array_unique($ids));
        self::assertLessThan(6, $pages);
    }

    public function testEventsRespectPrivacyPlaceAndUpcomingOrder(): void
    {
        $viewer = $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $public = $this->createComuna('hortas-busca', owner: $owner);
        $private = $this->createComuna('sala-fechada', ComunaVisibility::Private, $owner);
        $ownerToken = $this->login('owner@exemplo.org')['accessToken'];
        $future = $this->request('POST', '/comunas/hortas-busca/events', $this->eventBody('+6 days', override: ['title' => 'Mutirao da praca', 'placeName' => 'Praca Central', 'address' => 'Rua Secretissima 999']), $ownerToken);
        $this->assertStatus(201);
        $past = $this->request('POST', '/comunas/hortas-busca/events', $this->eventBody('+9 days', override: ['title' => 'Mutirao da praca', 'placeName' => 'Praca Central']), $ownerToken);
        $this->assertStatus(201);
        $this->em()->getConnection()->executeStatement('UPDATE comuna_event SET starts_at = ? WHERE id = ?', [(new \DateTimeImmutable('-6 days'))->format('Y-m-d H:i:sP'), $past['id']]);
        $cancelled = $this->request('POST', '/comunas/hortas-busca/events', $this->eventBody('+2 days', override: ['title' => 'Oficina cancelada']), $ownerToken);
        $this->request('POST', '/events/'.$cancelled['id'].'/cancel', token: $ownerToken);
        $this->assertStatus(200);
        $secret = $this->request('POST', '/comunas/sala-fechada/events', $this->eventBody('+4 days', override: ['title' => 'Assembleia secreta']), $ownerToken);
        $token = $this->login('viewer@exemplo.org')['accessToken'];

        $hidden = $this->request('GET', '/search?type=events&q='.rawurlencode('assembleia secreta'), token: $token);
        self::assertSame([], $hidden['events']);
        self::assertSame([], $this->request('GET', '/search?type=events&q=secretissima', token: $token)['events']);

        $found = $this->request('GET', '/search?type=events&q='.rawurlencode('mutirao da praca'), token: $token);
        self::assertSame([$future['id'], $past['id']], array_column($found['events'], 'id'));
        self::assertFalse($found['events'][0]['past']);
        self::assertTrue($found['events'][1]['past']);
        $byPlace = $this->request('GET', '/search?type=events&q='.rawurlencode('praca central'), token: $token);
        self::assertCount(2, $byPlace['events']);
        $byComuna = $this->request('GET', '/search?type=events&q=hortas-busca', token: $token);
        self::assertGreaterThanOrEqual(3, count($byComuna['events']));
        $cancelledRow = $this->request('GET', '/search?type=events&q='.rawurlencode('oficina cancelada'), token: $token);
        self::assertSame('cancelled', $cancelledRow['events'][0]['status']);

        $managed = $this->fresh(Comuna::class, $private->getId());
        $managedViewer = $this->em()->find(\App\Entity\User::class, $viewer->getId());
        self::assertNotNull($managedViewer);
        $this->addMember($managed, $managedViewer);
        $visible = $this->request('GET', '/search?type=events&q='.rawurlencode('assembleia secreta'), token: $token);
        self::assertSame([$secret['id']], array_column($visible['events'], 'id'));
        unset($public);
    }

    public function testDocumentsFollowReadPolicyAndBlocksHidePeople(): void
    {
        $viewer = $this->createUser('viewer');
        $owner = $this->createUser('autoradoc');
        $blocked = $this->createUser('sumida');
        $comuna = $this->createComuna('arquivo-fechado', ComunaVisibility::Private, $owner);
        $public = $this->storeDocument($owner, 'Cartilha de tecnologia popular', true);
        $private = $this->storeDocument($owner, 'Cartilha secreta de tecnologia', false);
        $removed = $this->storeDocument($owner, 'Cartilha removida de tecnologia', true);
        $removed->status = ContentStatus::Removed;
        $removed->removedAt = new \DateTimeImmutable();
        $removed->removedBy = $owner;
        $removed->removalReason = 'fora da busca';
        $comunaDoc = $this->storeDocument($owner, 'Ata secreta da comuna', false, $comuna, LibraryAccess::Members);
        $this->em()->flush();
        $token = $this->login('viewer@exemplo.org')['accessToken'];
        $outsider = array_column($this->request('GET', '/search?type=documents&q=tecnologia', token: $token)['documents'], 'id');
        self::assertContains($public->id->toRfc4122(), $outsider);
        self::assertNotContains($private->id->toRfc4122(), $outsider);
        self::assertNotContains($removed->id->toRfc4122(), $outsider);
        self::assertSame([], $this->request('GET', '/search?type=documents&q='.rawurlencode('ata secreta'), token: $token)['documents']);

        $ownerToken = $this->login('autoradoc@exemplo.org')['accessToken'];
        $own = array_column($this->request('GET', '/search?type=documents&q=tecnologia', token: $ownerToken)['documents'], 'id');
        self::assertContains($private->id->toRfc4122(), $own);
        self::assertNotContains($removed->id->toRfc4122(), $own);

        $managed = $this->fresh(Comuna::class, $comuna->getId());
        $managedViewer = $this->em()->find(\App\Entity\User::class, $viewer->getId());
        self::assertNotNull($managedViewer);
        $this->addMember($managed, $managedViewer);
        self::assertSame([$comunaDoc->id->toRfc4122()], array_column($this->request('GET', '/search?type=documents&q='.rawurlencode('ata secreta'), token: $token)['documents'], 'id'));

        $this->request('PUT', '/me/blocks/sumida', token: $token);
        self::assertSame([], $this->request('GET', '/search?type=people&q=sumida', token: $token)['people']);
        $other = $this->login('sumida@exemplo.org')['accessToken'];
        self::assertSame([], $this->request('GET', '/search?type=people&q=viewer', token: $other)['people']);
        unset($blocked);
    }

    private function storeDocument(\App\Entity\User $owner, string $title, bool $public, ?Comuna $comuna = null, LibraryAccess $access = LibraryAccess::Private): LibraryDocument
    {
        $now = new \DateTimeImmutable();
        if (!$this->em()->contains($owner)) {
            $owner = $this->em()->find(\App\Entity\User::class, $owner->getId()) ?? $owner;
        }
        if (null !== $comuna && !$this->em()->contains($comuna)) {
            $comuna = $this->em()->find(Comuna::class, $comuna->getId()) ?? $comuna;
        }
        $collection = new LibraryCollection(null === $comuna ? $owner : null, $comuna, $owner, 'Formação', 'Coleção', $now);
        $file = new LibraryFile($owner, \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'library/'.bin2hex(random_bytes(8)).'.pdf', 'arquivo.pdf', str_repeat('b', 64), 1024, 2, $now);
        $file->status = 'ready';
        $document = new LibraryDocument($file, $collection, $collection->user, $collection->comuna, $title, 'Descrição do material '.$title, $access, $public, \App\Enum\Topic::Trab, $now);
        foreach ([$collection, $file, $document] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return $document;
    }
}
