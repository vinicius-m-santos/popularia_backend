<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Entity\UserBlock;
use App\Enum\AccountStatus;
use App\Enum\ComunaVisibility;
use App\Tests\ApiTestCase;

final class SavedPostTest extends ApiTestCase
{
    public function testSaveIsIdempotentPrivateAndDoesNotNotify(): void
    {
        ['readerToken' => $reader, 'postId' => $id] = $this->readerSaved('Ata da reunião');
        $before = $this->tableCount('notification');
        $this->request('PUT', '/posts/'.$id.'/save', token: $reader);
        $this->assertStatus(204);
        self::assertSame(1, $this->tableCount('saved_post'));
        self::assertSame(0, $this->tableCount('library_save'));
        self::assertSame($before, $this->tableCount('notification'));

        $post = $this->request('GET', '/posts/'.$id, token: $reader);
        $this->assertStatus(200);
        self::assertTrue($post['viewer']['saved']);
        self::assertArrayNotHasKey('saveCount', $post);
        self::assertArrayNotHasKey('savedBy', $post);

        $page = $this->request('GET', '/me/saved-posts', token: $reader);
        $this->assertStatus(200);
        self::assertSame($id, $page['items'][0]['post']['id']);
        self::assertTrue($page['items'][0]['post']['viewer']['saved']);
        self::assertNotSame('', $page['items'][0]['savedAt']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testReadersDoNotShareSaves(): void
    {
        ['authorToken' => $author, 'readerToken' => $reader, 'postId' => $id] = $this->readerSaved();
        $other = $this->createUser('outra');
        $otherToken = $this->login($other->getEmail())['accessToken'];

        $forOther = $this->request('GET', '/posts/'.$id, token: $otherToken);
        self::assertFalse($forOther['viewer']['saved']);
        self::assertSame([], $this->request('GET', '/me/saved-posts', token: $otherToken)['items']);
        $feed = $this->request('GET', '/feed', token: $reader);
        $match = array_values(array_filter($feed['items'], static fn (array $item): bool => $item['id'] === $id));
        self::assertTrue($match[0]['viewer']['saved']);
        $profile = $this->request('GET', '/users/camila/posts');
        $this->assertStatus(200);
        self::assertFalse($profile['items'][0]['viewer']['saved']);
        self::assertTrue($this->request('GET', '/users/camila/posts', token: $reader)['items'][0]['viewer']['saved']);
        self::assertFalse($this->request('GET', '/posts/'.$id, token: $author)['viewer']['saved']);
    }

    public function testUnsaveIsIdempotent(): void
    {
        ['readerToken' => $reader, 'postId' => $id] = $this->readerSaved();
        $this->request('DELETE', '/posts/'.$id.'/save', token: $reader);
        $this->assertStatus(204);
        $this->request('DELETE', '/posts/'.$id.'/save', token: $reader);
        $this->assertStatus(204);
        $this->request('DELETE', '/posts/'.\Symfony\Component\Uid\Uuid::v7()->toRfc4122().'/save', token: $reader);
        $this->assertStatus(204);
        self::assertSame([], $this->request('GET', '/me/saved-posts', token: $reader)['items']);
        self::assertFalse($this->request('GET', '/posts/'.$id, token: $reader)['viewer']['saved']);
        self::assertSame(0, $this->tableCount('saved_post'));
    }

    public function testListIsOrderedBySaveTimeAndPaginated(): void
    {
        $this->createUser('camila');
        $reader = $this->createUser('leitor');
        $authorToken = $this->login('camila@exemplo.org')['accessToken'];
        $readerToken = $this->login($reader->getEmail())['accessToken'];
        $first = $this->publish($authorToken, 'Primeira');
        $second = $this->publish($authorToken, 'Segunda');
        $this->request('PUT', '/posts/'.$first.'/save', token: $readerToken);
        $this->assertStatus(204);
        $this->request('PUT', '/posts/'.$second.'/save', token: $readerToken);
        $this->assertStatus(204);
        $this->request('PUT', '/posts/'.$first.'/save', token: $readerToken);
        $this->assertStatus(204);

        $page = $this->request('GET', '/me/saved-posts?limit=1', token: $readerToken);
        $this->assertStatus(200);
        self::assertSame([$second], array_column(array_column($page['items'], 'post'), 'id'));
        self::assertNotNull($page['nextCursor']);
        $next = $this->request('GET', '/me/saved-posts?limit=1&cursor='.$page['nextCursor'], token: $readerToken);
        self::assertSame([$first], array_column(array_column($next['items'], 'post'), 'id'));
        self::assertNull($next['nextCursor']);
    }

    public function testSavingRequiresAuthenticationAndAVisiblePost(): void
    {
        $author = $this->createUser('camila');
        $author->setPrivate(true, new \DateTimeImmutable());
        $this->em()->flush();
        $readerToken = $this->login($this->createUser('leitor')->getEmail())['accessToken'];
        $id = $this->publish($this->login('camila@exemplo.org')['accessToken'], 'Só para quem segue');

        $this->request('PUT', '/posts/'.$id.'/save', token: $readerToken);
        $this->assertStatus(404);
        $this->request('GET', '/me/saved-posts');
        $this->assertStatus(401);
        $this->request('PUT', '/posts/'.$id.'/save');
        $this->assertStatus(401);
        self::assertSame(0, $this->tableCount('saved_post'));
    }

    public function testDeletedModeratedAndInaccessiblePostsStayOutOfTheLibrary(): void
    {
        ['authorToken' => $author, 'readerToken' => $reader, 'postId' => $id] = $this->readerSaved('Texto que vai sumir');
        $this->request('DELETE', '/posts/'.$id, token: $author);
        $this->assertStatus(204);
        $gone = $this->request('GET', '/me/saved-posts', token: $reader);
        self::assertSame([], $gone['items']);
        self::assertStringNotContainsString('Texto que vai sumir', (string) json_encode($gone));

        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', ComunaVisibility::Public, $owner);
        $member = $this->createUser('membro');
        $this->addMember($comuna, $member);
        $memberToken = $this->login($member->getEmail())['accessToken'];
        $post = $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => 'Texto moderado'], $memberToken);
        $this->assertStatus(201);
        $this->request('PUT', '/posts/'.$post['id'].'/save', token: $reader);
        $this->assertStatus(204);
        $this->request('POST', '/posts/'.$post['id'].'/removal', ['reason' => 'Fora do combinado'], $this->login($owner->getEmail())['accessToken']);
        $this->assertStatus(204);
        $hidden = $this->request('GET', '/me/saved-posts', token: $reader);
        self::assertSame([], $hidden['items']);
        self::assertStringNotContainsString('Texto moderado', (string) json_encode($hidden));
    }

    public function testPrivateProfilePendingFollowAndBlockDoNotGrantAccess(): void
    {
        $author = $this->createUser('camila');
        ['readerToken' => $reader, 'readerId' => $readerId, 'postId' => $id] = $this->readerSaved('Diário aberto', $author);
        $this->em()->clear();
        $reloaded = $this->em()->find(User::class, $author->getId());
        self::assertInstanceOf(User::class, $reloaded);
        $reloaded->setPrivate(true, new \DateTimeImmutable());
        $this->em()->flush();

        $this->request('GET', '/posts/'.$id, token: $reader);
        $this->assertStatus(404);
        $hidden = $this->request('GET', '/me/saved-posts', token: $reader);
        self::assertSame([], $hidden['items']);
        self::assertStringNotContainsString('Diário aberto', (string) json_encode($hidden));
        $this->request('PUT', '/users/camila/follow', token: $reader);
        $this->assertStatus(200);
        $pending = $this->request('GET', '/me/saved-posts', token: $reader);
        self::assertSame([], $pending['items']);

        $this->em()->clear();
        $blocker = $this->em()->find(User::class, $author->getId());
        $blocked = $this->em()->find(User::class, \Symfony\Component\Uid\Uuid::fromString($readerId));
        self::assertInstanceOf(User::class, $blocker);
        self::assertInstanceOf(User::class, $blocked);
        $blocker->setPrivate(false, new \DateTimeImmutable());
        $this->em()->persist(new UserBlock($blocker, $blocked, new \DateTimeImmutable()));
        $this->em()->flush();
        $blockedList = $this->request('GET', '/me/saved-posts', token: $reader);
        self::assertSame([], $blockedList['items']);
        self::assertStringNotContainsString('Diário aberto', (string) json_encode($blockedList));
    }

    public function testLeavingAPrivateComunaRemovesThePostFromSaved(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('privada', ComunaVisibility::Private, $owner);
        $reader = $this->createUser('leitor');
        $this->addMember($comuna, $reader);
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $readerToken = $this->login($reader->getEmail())['accessToken'];
        $post = $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => 'Ata secreta'], $ownerToken);
        $this->assertStatus(201);
        $this->request('PUT', '/posts/'.$post['id'].'/save', token: $readerToken);
        $this->assertStatus(204);
        self::assertCount(1, $this->request('GET', '/me/saved-posts', token: $readerToken)['items']);

        $this->request('DELETE', '/comunas/privada/membership', token: $readerToken);
        $this->assertStatus(204);
        $hidden = $this->request('GET', '/me/saved-posts', token: $readerToken);
        self::assertSame([], $hidden['items']);
        self::assertStringNotContainsString('Ata secreta', (string) json_encode($hidden));
        $this->request('GET', '/posts/'.$post['id'], token: $readerToken);
        $this->assertStatus(404);
    }

    public function testSuspendedAuthorDisappearsAndSuspendedReaderCannotList(): void
    {
        $author = $this->createUser('camila');
        $reader = $this->createUser('leitor');
        $authorToken = $this->login($author->getEmail())['accessToken'];
        $readerToken = $this->login($reader->getEmail())['accessToken'];
        $id = $this->publish($authorToken, 'Da conta suspensa');
        $this->request('PUT', '/posts/'.$id.'/save', token: $readerToken);
        $this->assertStatus(204);

        $this->em()->clear();
        $suspendedAuthor = $this->em()->find(User::class, $author->getId());
        self::assertInstanceOf(User::class, $suspendedAuthor);
        $suspendedAuthor->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $hidden = $this->request('GET', '/me/saved-posts', token: $readerToken);
        self::assertSame([], $hidden['items']);
        self::assertStringNotContainsString('Da conta suspensa', (string) json_encode($hidden));

        $kept = $this->createUser('ativa');
        $keptToken = $this->login($kept->getEmail())['accessToken'];
        $visible = $this->publish($keptToken, 'Continua visível');
        $this->request('PUT', '/posts/'.$visible.'/save', token: $readerToken);
        $this->em()->clear();
        $suspendedReader = $this->em()->find(User::class, $reader->getId());
        self::assertInstanceOf(User::class, $suspendedReader);
        $suspendedReader->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $body = $this->request('GET', '/me/saved-posts', token: $readerToken);
        $this->assertStatus(403);
        self::assertSame('/errors/account-inactive', $body['type']);
    }

    public function testAccountErasureDropsOnlyThatPersonsReferences(): void
    {
        $author = $this->createUser('camila');
        $other = $this->createUser('outra');
        $readerToken = $this->login($this->createUser('leitor')->getEmail())['accessToken'];
        $authorToken = $this->login($author->getEmail())['accessToken'];
        $otherToken = $this->login($other->getEmail())['accessToken'];
        $erased = $this->publish($authorToken, 'Some com a conta');
        $kept = $this->publish($otherToken, 'Fica na biblioteca');
        $this->request('PUT', '/posts/'.$erased.'/save', token: $readerToken);
        $this->request('PUT', '/posts/'.$erased.'/save', token: $authorToken);
        $this->request('PUT', '/posts/'.$kept.'/save', token: $readerToken);
        $this->assertStatus(204);

        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $authorToken);
        $this->assertStatus(204);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM saved_post WHERE post_id = ?', [$erased]));
        $page = $this->request('GET', '/me/saved-posts', token: $readerToken);
        self::assertSame([$kept], array_column(array_column($page['items'], 'post'), 'id'));
        self::assertStringNotContainsString('Some com a conta', (string) json_encode($page));
    }

    /** @return array{authorToken: string, readerToken: string, readerId: string, postId: string} */
    private function readerSaved(string $body = 'Texto para guardar', ?User $author = null): array
    {
        $author ??= $this->createUser('camila');
        $reader = $this->createUser('leitor');
        $authorToken = $this->login($author->getEmail())['accessToken'];
        $readerToken = $this->login($reader->getEmail())['accessToken'];
        $postId = $this->publish($authorToken, $body);
        $this->request('PUT', '/posts/'.$postId.'/save', token: $readerToken);
        $this->assertStatus(204);

        return ['authorToken' => $authorToken, 'readerToken' => $readerToken, 'readerId' => $reader->getId()->toRfc4122(), 'postId' => $postId];
    }

    private function publish(string $token, string $body): string
    {
        $post = $this->request('POST', '/posts', ['comunaId' => null, 'body' => $body], $token);
        $this->assertStatus(201);
        self::assertIsString($post['id']);

        return $post['id'];
    }

    private function tableCount(string $table): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
