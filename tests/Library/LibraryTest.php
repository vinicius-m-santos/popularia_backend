<?php

declare(strict_types=1);

namespace App\Tests\Library;

use App\Entity\LibraryCollection;
use App\Entity\LibraryDocument;
use App\Entity\LibraryFile;
use App\Entity\User;
use App\Entity\UserBlock;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\ContentStatus;
use App\Enum\LibraryAccess;
use App\Enum\PlatformRole;
use App\Enum\Topic;
use App\Tests\ApiTestCase;

/** Requisições reais: autorização antes dos DTOs, referências e revogação pelo estado atual. */
final class LibraryTest extends ApiTestCase
{
    private function document(User $owner, ?\App\Entity\Comuna $comuna = null, LibraryAccess $access = LibraryAccess::Private, bool $public = false, string $title = 'Cartilha de organização'): LibraryDocument
    {
        $now = new \DateTimeImmutable();
        $collection = new LibraryCollection(null === $comuna ? $owner : null, $comuna, $owner, 'Formação popular', 'Coleção de estudo', $now);
        $file = new LibraryFile($owner, \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 'library/'.bin2hex(random_bytes(16)).'.pdf', 'original-privado.pdf', str_repeat('a', 64), 1024, 4, $now);
        $file->status = 'ready';
        $document = new LibraryDocument($file, $collection, $collection->user, $collection->comuna, $title, 'Descrição confidencial do material', $access, $public, Topic::Trab, $now);
        foreach ([$collection, $file, $document] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return $document;
    }

    public function testPersonalPrivateNeverLeaksAndPostDoesNotGrantAccess(): void
    {
        $owner = $this->createUser('camila');
        $other = $this->createUser('marina');
        $document = $this->document($owner);
        $id = $document->id->toRfc4122();
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $otherToken = $this->login($other->getEmail())['accessToken'];
        $this->request('GET', '/library/documents/'.$id, token: $ownerToken);
        $this->assertStatus(200);
        $this->request('GET', '/library/documents/'.$id, token: $otherToken);
        $this->assertStatus(404);
        $this->request('GET', '/library/documents/'.$id.'/file', token: $otherToken);
        $this->assertStatus(404);
        $this->request('GET', '/library/collections/'.$document->collection->id, token: $otherToken);
        $this->assertStatus(404);
        $empty = $this->request('GET', '/library/documents?scope=personal&owner='.$owner->getId(), token: $otherToken);
        self::assertSame([], $empty['items']);
        $post = $this->request('POST', '/posts', ['body' => 'Leia esta cartilha', 'documentId' => $id], $ownerToken);
        $this->assertStatus(201);
        self::assertSame($id, $post['document']['id']);
        self::assertSame(1, $this->em()->getRepository(LibraryFile::class)->count([]));
        $visible = $this->request('GET', '/posts/'.$post['id'], token: $otherToken);
        $this->assertStatus(200);
        self::assertSame('locked', $visible['document']['state']);
        foreach (['title', 'description', 'origin', 'collection', 'responsible', 'pageCount', 'byteSize', 'distributions'] as $field) {
            self::assertNull($visible['document'][$field]);
        }
        self::assertFalse($this->fresh(LibraryDocument::class, $document->id)->isPublic);
    }

    public function testPublicOptInSaveAndImmediateRevocation(): void
    {
        $owner = $this->createUser('camila');
        $other = $this->createUser('marina');
        $document = $this->document($owner);
        $id = $document->id->toRfc4122();
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $otherToken = $this->login($other->getEmail())['accessToken'];
        $this->request('PATCH', '/library/documents/'.$id, ['isPublic' => true], $ownerToken);
        $this->assertStatus(200);
        $public = $this->request('GET', '/library/documents?scope=public', token: $otherToken);
        self::assertCount(1, $public['items']);
        $this->request('PUT', '/library/documents/'.$id.'/saved', token: $otherToken);
        $this->assertStatus(204);
        $this->request('PUT', '/library/documents/'.$id.'/saved', token: $otherToken);
        self::assertSame(1, $this->em()->getRepository(\App\Entity\LibrarySave::class)->count([]));
        $this->request('PATCH', '/library/documents/'.$id, ['isPublic' => false], $ownerToken);
        $this->assertStatus(200);
        $this->request('GET', '/library/documents/'.$id.'/file', token: $otherToken);
        $this->assertStatus(404);
        $saved = $this->request('GET', '/me/saved-documents', token: $otherToken);
        self::assertCount(1, $saved['items']);
        self::assertSame('locked', $saved['items'][0]['state']);
        self::assertNull($saved['items'][0]['description']);
        self::assertNull($saved['items'][0]['origin']);
        $this->request('DELETE', '/library/documents/'.$id.'/saved', token: $otherToken);
        $this->assertStatus(204);
        $public = $this->request('GET', '/library/documents?scope=public', token: $otherToken);
        self::assertSame([], $public['items']);
    }

    public function testFollowersRequireApprovalAndProfileChangesNeverMakeDocumentsPublic(): void
    {
        $owner = $this->createUser('camila');
        $follower = $this->createUser('marina');
        $document = $this->document($owner, access: LibraryAccess::Followers);
        $id = $document->id->toRfc4122();
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $followerToken = $this->login($follower->getEmail())['accessToken'];
        $this->request('PATCH', '/me', ['isPrivate' => true], $ownerToken);
        $this->assertStatus(200);
        $follow = $this->request('PUT', '/users/camila/follow', token: $followerToken);
        self::assertFalse($follow['following']);
        self::assertTrue($follow['requested']);
        $this->request('GET', '/library/documents/'.$id, token: $followerToken);
        $this->assertStatus(404);
        $requests = $this->request('GET', '/me/follow-requests', token: $ownerToken);
        self::assertCount(1, $requests['items']);
        $this->request('PUT', '/me/follow-requests/'.$requests['items'][0]['id'], token: $ownerToken);
        $this->assertStatus(204);
        $this->request('GET', '/library/documents/'.$id, token: $followerToken);
        $this->assertStatus(200);
        $this->request('PATCH', '/me', ['isPrivate' => false], $ownerToken);
        $this->assertStatus(200);
        $this->request('DELETE', '/users/camila/follow', token: $followerToken);
        $this->request('GET', '/library/documents/'.$id, token: $followerToken);
        $this->assertStatus(404);
        $public = $this->request('GET', '/library/documents?scope=public', token: $followerToken);
        self::assertSame([], $public['items']);
        self::assertFalse($this->fresh(LibraryDocument::class, $document->id)->isPublic);
    }

    public function testMultipleSharesAreReferencesAndMembershipLossDoesNotDeleteOriginal(): void
    {
        $owner = $this->createUser('camila');
        $reader = $this->createUser('marina');
        $a = $this->createComuna('tarifa-zero', ComunaVisibility::Private);
        $b = $this->createComuna('ti', ComunaVisibility::Private);
        $this->addMember($a, $owner);
        $this->addMember($a, $reader);
        $this->addMember($b, $owner);
        $document = $this->document($owner);
        $id = $document->id->toRfc4122();
        $token = $this->login($owner->getEmail())['accessToken'];
        $readerToken = $this->login($reader->getEmail())['accessToken'];
        foreach (['tarifa-zero', 'tarifa-zero', 'ti'] as $slug) {
            $this->request('PUT', '/library/documents/'.$id.'/comunas/'.$slug, token: $token);
            $this->assertStatus(204);
        }
        self::assertSame(2, $this->em()->getRepository(\App\Entity\LibraryShare::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(LibraryFile::class)->count([]));
        $list = $this->request('GET', '/library/documents?scope=comuna&comuna=tarifa-zero', token: $readerToken);
        self::assertCount(1, $list['items']);
        $this->request('DELETE', '/comunas/tarifa-zero/membership', token: $readerToken);
        $this->assertStatus(204);
        $this->request('GET', '/library/documents/'.$id.'/file', token: $readerToken);
        $this->assertStatus(404);
        $this->request('DELETE', '/comunas/tarifa-zero/membership', token: $token);
        self::assertSame(1, $this->em()->getRepository(\App\Entity\LibraryShare::class)->count([]));
        $this->request('GET', '/library/documents/'.$id, token: $token);
        $this->assertStatus(200);
        $this->request('DELETE', '/library/documents/'.$id.'/comunas/ti', token: $token);
        $this->assertStatus(204);
        self::assertSame(1, $this->em()->getRepository(LibraryDocument::class)->count([]));
    }

    public function testPublicComunaMemberCardDoesNotLeakDescriptionOrSearchablePrivateMetadata(): void
    {
        $owner = $this->createUser('camila');
        $reader = $this->createUser('marina');
        $comuna = $this->createComuna('hortas', owner: $owner);
        $document = $this->document($owner, $comuna, LibraryAccess::Members);
        $token = $this->login($reader->getEmail())['accessToken'];
        $list = $this->request('GET', '/library/documents?scope=comuna&comuna=hortas', token: $token);
        self::assertSame('locked', $list['items'][0]['state']);
        self::assertSame($document->title, $list['items'][0]['title']);
        foreach (['description', 'collection', 'responsible', 'byteSize', 'pageCount'] as $field) {
            self::assertNull($list['items'][0][$field]);
        }
        $search = $this->request('GET', '/library/documents?scope=comuna&comuna=hortas&q=confidencial', token: $token);
        self::assertSame([], $search['items']);
        $this->request('GET', '/library/documents/'.$document->id.'/file', token: $token);
        $this->assertStatus(404);
        $this->request('PUT', '/comunas/hortas/membership', token: $token);
        $this->request('GET', '/library/documents/'.$document->id, token: $token);
        $this->assertStatus(200);
    }

    public function testPrivateComunaNeverLeaksEvenWhenOneDocumentIsExplicitlyPublic(): void
    {
        $owner = $this->createUser('camila');
        $outsider = $this->createUser('marina');
        $comuna = $this->createComuna('privada', ComunaVisibility::Private, $owner);
        $public = $this->document($owner, $comuna, LibraryAccess::Members, true, 'Material público');
        $private = $this->document($owner, $comuna, LibraryAccess::Members, false, 'Endereços privados');
        $token = $this->login($outsider->getEmail())['accessToken'];
        $this->request('GET', '/library/documents/'.$private->id, token: $token);
        $this->assertStatus(404);
        $this->request('GET', '/library/collections/'.$private->collection->id, token: $token);
        $this->assertStatus(404);
        $list = $this->request('GET', '/library/documents?scope=comuna&comuna=privada', token: $token);
        self::assertSame([], $list['items']);
        $list = $this->request('GET', '/library/documents?scope=public', token: $token);
        self::assertCount(1, $list['items']);
        self::assertSame($public->id->toRfc4122(), $list['items'][0]['id']);
    }

    public function testCollectionsRequireOwnershipAndPreventNonemptyDeletion(): void
    {
        $owner = $this->createUser('camila');
        $other = $this->createUser('marina');
        $document = $this->document($owner);
        $id = $document->id->toRfc4122();
        $token = $this->login($owner->getEmail())['accessToken'];
        $otherToken = $this->login($other->getEmail())['accessToken'];
        $created = $this->request('POST', '/library/collections', ['name' => 'Estudos', 'description' => 'Trabalho'], $token);
        $this->assertStatus(201);
        $this->request('PATCH', '/library/collections/'.$created['id'], ['name' => 'Renomeada', 'description' => 'Descrição nova'], $token);
        $this->assertStatus(200);
        $this->request('DELETE', '/library/collections/'.$document->collection->id, token: $token);
        $this->assertStatus(409);
        $this->request('PATCH', '/library/documents/'.$id, ['collectionId' => $created['id']], $otherToken);
        $this->assertStatus(404);
        $this->request('PATCH', '/library/documents/'.$id, ['collectionId' => $created['id']], $token);
        $this->assertStatus(200);
        $this->request('DELETE', '/library/collections/'.$document->collection->id, token: $token);
        $this->assertStatus(204);
    }

    public function testSearchAccentsFiltersPaginationAndGlobalSearch(): void
    {
        $owner = $this->createUser('camila');
        $reader = $this->createUser('marina');
        for ($i = 0; $i < 3; ++$i) {
            $this->document($owner, public: true, title: 'Organização trabalhista '.$i);
        }
        $this->document($owner, title: 'Organização secreta');
        $token = $this->login($reader->getEmail())['accessToken'];
        foreach (['ORGANIZACAO', 'confidencial', 'Formacao', 'camila', 'trabalho'] as $term) {
            $list = $this->request('GET', '/library/documents?scope=public&q='.urlencode($term).'&topic=trab&origin=people&limit=2', token: $token);
            $this->assertStatus(200);
            self::assertCount(2, $list['items']);
            self::assertNotNull($list['nextCursor']);
            $next = $this->request('GET', '/library/documents?scope=public&q='.urlencode($term).'&topic=trab&origin=people&limit=2&cursor='.$list['nextCursor'], token: $token);
            self::assertCount(1, $next['items']);
            self::assertNull($next['nextCursor']);
            self::assertNotSame($list['items'][0]['id'], $next['items'][0]['id']);
        }
        $global = $this->request('GET', '/search?q=organizacao&type=all', token: $token);
        self::assertCount(3, $global['documents']);
        $comunas = $this->request('GET', '/library/documents?scope=public&origin=comunas', token: $token);
        self::assertSame([], $comunas['items']);
    }

    public function testSoftDeletionLeavesPostAndSaveAsUnavailableAndNeverGrantsHistoricalAccess(): void
    {
        $owner = $this->createUser('camila');
        $reader = $this->createUser('marina');
        $document = $this->document($owner, public: true);
        $id = $document->id->toRfc4122();
        $token = $this->login($owner->getEmail())['accessToken'];
        $readerToken = $this->login($reader->getEmail())['accessToken'];
        $post = $this->request('POST', '/posts', ['body' => '', 'documentId' => $id], $token);
        $this->assertStatus(201);
        $this->request('PUT', '/library/documents/'.$id.'/saved', token: $readerToken);
        $this->request('DELETE', '/library/documents/'.$id, token: $token);
        $this->assertStatus(204);
        $oldPost = $this->request('GET', '/posts/'.$post['id'], token: $readerToken);
        self::assertSame('unavailable', $oldPost['document']['state']);
        self::assertNull($oldPost['document']['title']);
        $saved = $this->request('GET', '/me/saved-documents', token: $readerToken);
        self::assertSame('unavailable', $saved['items'][0]['state']);
        $this->request('GET', '/library/documents/'.$id.'/file', token: $readerToken);
        $this->assertStatus(404);
        self::assertSame(ContentStatus::Deleted, $this->fresh(LibraryDocument::class, $document->id)->status);
    }

    public function testBilateralBlocksExcludePublicDiscoveryAndBookmarks(): void
    {
        $owner = $this->createUser('camila');
        $reader = $this->createUser('marina');
        $document = $this->document($owner, public: true);
        $id = $document->id->toRfc4122();
        $token = $this->login($reader->getEmail())['accessToken'];
        $this->request('PUT', '/library/documents/'.$id.'/saved', token: $token);
        $blocker = $this->em()->find(User::class, $owner->getId());
        $blocked = $this->em()->find(User::class, $reader->getId());
        self::assertNotNull($blocker);
        self::assertNotNull($blocked);
        $this->em()->persist(new UserBlock($blocker, $blocked, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->request('GET', '/library/documents/'.$id, token: $token);
        $this->assertStatus(404);
        $list = $this->request('GET', '/library/documents?scope=public', token: $token);
        self::assertSame([], $list['items']);
        $saved = $this->request('GET', '/me/saved-documents', token: $token);
        self::assertNull($saved['items'][0]['title']);
        self::assertNull($saved['items'][0]['responsible']);
    }

    public function testMemberCannotPublicizeComunaDocumentAndModeratorCanRemoveWhileArchived(): void
    {
        $owner = $this->createUser('camila');
        $member = $this->createUser('marina');
        $moderator = $this->createUser('joana');
        $comuna = $this->createComuna('hortas', owner: $owner);
        $this->addMember($comuna, $member);
        $this->addMember($comuna, $moderator, ComunaRole::Moderator);
        $document = $this->document($member, $comuna, LibraryAccess::Members);
        $id = $document->id->toRfc4122();
        $memberToken = $this->login($member->getEmail())['accessToken'];
        $modToken = $this->login($moderator->getEmail())['accessToken'];
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $this->request('PATCH', '/library/documents/'.$id, ['isPublic' => true], $memberToken);
        $this->assertStatus(403);
        $this->request('PATCH', '/library/documents/'.$id, ['isPublic' => true], $modToken);
        $this->assertStatus(200);
        $this->request('POST', '/comunas/hortas/archive', token: $ownerToken);
        $this->assertStatus(200);
        $this->request('GET', '/library/documents/'.$id, token: $memberToken);
        $this->assertStatus(200);
        $this->request('POST', '/library/collections', ['name' => 'Outra', 'comunaSlug' => 'hortas'], $memberToken);
        $this->assertStatus(409);
        $this->request('POST', '/library/documents/'.$id.'/removal', ['reason' => 'Conteúdo inadequado'], $modToken);
        $this->assertStatus(204);
        $this->request('GET', '/library/documents/'.$id, token: $memberToken);
        $this->assertStatus(404);
    }

    public function testPlatformReportsReuseExistingReportsAndRemovalAudit(): void
    {
        $owner = $this->createUser('camila');
        $reader = $this->createUser('marina');
        $moderator = $this->createUser('joana', role: PlatformRole::PlatformModerator);
        $document = $this->document($owner, public: true);
        $id = $document->id->toRfc4122();
        $readerToken = $this->login($reader->getEmail())['accessToken'];
        $modToken = $this->login($moderator->getEmail())['accessToken'];
        $this->request('POST', '/library/documents/'.$id.'/reports', ['reason' => 'Direitos autorais'], $readerToken);
        $this->assertStatus(204);
        $this->request('GET', '/library/reports', token: $readerToken);
        $this->assertStatus(403);
        $reports = $this->request('GET', '/library/reports', token: $modToken);
        self::assertCount(1, $reports['items']);
        $this->request('PATCH', '/library/reports/'.$reports['items'][0]['id'], ['status' => 'removed'], $modToken);
        $this->assertStatus(204);
        $this->request('GET', '/library/documents/'.$id, token: $readerToken);
        $this->assertStatus(404);
    }
}
