<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\LibraryCollection;
use App\Entity\LibraryDocument;
use App\Entity\LibraryFile;
use App\Entity\User;
use App\Entity\UserAuthIdentity;
use App\Enum\AccountStatus;
use App\Enum\LibraryAccess;
use App\Tests\ApiTestCase;
use App\Tests\Library\MemoryStorage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class AccountDeletionTest extends ApiTestCase
{
    private function document(User $user, ?\App\Entity\Comuna $comuna = null): LibraryDocument
    {
        $now = new \DateTimeImmutable();
        $collection = new LibraryCollection(null === $comuna ? $user : null, $comuna, $user, 'Coleção original', 'Dados pessoais', $now);
        $file = new LibraryFile($user, Uuid::v7()->toRfc4122(), 'library/'.Uuid::v7().'.pdf', 'nome-pessoal.pdf', str_repeat('a', 64), 100, 1, $now);
        $file->status = 'ready';
        $document = new LibraryDocument($file, $collection, $collection->user, $comuna, 'Título pessoal', 'Descrição pessoal', null === $comuna ? LibraryAccess::Private : LibraryAccess::Members, false, null, $now);
        foreach ([$collection, $file, $document] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        MemoryStorage::$objects[$file->storageKey] = 'pdf';

        return $document;
    }

    public function testDeletionInvalidatesCredentialsErasesDataAndKeepsCollectiveStructure(): void
    {
        MemoryStorage::$objects = [];
        MemoryStorage::$fail = false;
        $user = $this->createUser('camila');
        $email = $user->getEmail();
        $id = $user->getId();
        $other = $this->createUser('marina');
        $comuna = $this->createComuna('coletivo', owner: $other);
        $this->addMember($comuna, $user);
        $personal = $this->document($user);
        $collective = $this->document($user, $comuna);
        $this->em()->persist(new UserAuthIdentity($user, 'google', 'sub-original', $email, new \DateTimeImmutable()));
        $this->em()->flush();
        $one = $this->login($email);
        $two = $this->login($email);
        $reader = $this->login($other->getEmail())['accessToken'];
        $post = $this->request('POST', '/posts', ['body' => 'Texto pessoal'], $one['accessToken']);
        $otherPost = $this->createPost($this->fresh(User::class, $other->getId()), null);
        $comment = $this->request('POST', '/posts/'.$otherPost->getId().'/comments', ['body' => 'Comentário pessoal'], $one['accessToken']);
        $this->assertStatus(201);
        $this->request('POST', '/posts/'.$otherPost->getId().'/comments', ['body' => 'Resposta de outra pessoa', 'parentId' => $comment['id']], $reader);
        $this->request('PUT', '/users/marina/follow', token: $one['accessToken']);
        $this->request('PUT', '/me/blocks/marina', token: $one['accessToken']);
        $this->request('DELETE', '/me/blocks/marina', token: $one['accessToken']);
        $this->createUser('bloqueada');
        $this->request('PUT', '/me/blocks/bloqueada', token: $one['accessToken']);
        $this->assertStatus(204);
        $this->request('PUT', '/users/marina/follow', token: $one['accessToken']);
        $square = $this->request('GET', '/comunas/coletivo/square', token: $reader)['id'];
        $message = $this->request('POST', '/squares/'.$square.'/messages', ['body' => 'Mensagem pessoal', 'clientId' => 'original'], $one['accessToken']);
        $this->assertStatus(201);
        $this->request('POST', '/squares/'.$square.'/messages', ['body' => 'Resposta coletiva', 'clientId' => 'resposta', 'replyToId' => $message['id']], $reader);
        $this->request('PUT', '/squares/'.$square.'/messages/'.$message['id'].'/reactions/❤️', token: $one['accessToken']);
        $this->request('PUT', '/squares/'.$square.'/presence', token: $one['accessToken']);
        $this->request('PUT', '/library/documents/'.$collective->id.'/saved', token: $one['accessToken']);
        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $one['accessToken']);
        $this->assertStatus(204);
        foreach ([$one, $two] as $session) {
            $this->request('GET', '/me', token: $session['accessToken']);
            $this->assertStatus(401);
            $this->request('POST', '/auth/refresh', ['refreshToken' => $session['refreshToken']]);
            $this->assertStatus(401);
        }
        $this->request('POST', '/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        $this->assertStatus(401);
        $erased = $this->fresh(User::class, $id);
        self::assertSame(AccountStatus::Deleted, $erased->getStatus());
        self::assertSame('', $erased->getPassword());
        self::assertSame([], $erased->getInterests());
        self::assertNotSame($email, $erased->getEmail());
        $db = $this->em()->getConnection();
        foreach (['auth_session', 'account_token', 'user_auth_identity', 'library_save', 'square_visit', 'square_reaction', 'comuna_membership'] as $table) {
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE user_id = ?', [$id->toRfc4122()]));
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM notification WHERE actor_id = ? OR recipient_id = ?', [$id->toRfc4122(), $id->toRfc4122()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM user_block WHERE blocker_id = ? OR blocked_id = ?', [$id->toRfc4122(), $id->toRfc4122()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM user_follow WHERE follower_id = ? OR followed_id = ?', [$id->toRfc4122(), $id->toRfc4122()]));
        self::assertSame('', $db->fetchOne('SELECT body FROM post WHERE id = ?', [$post['id']]));
        self::assertSame('', $db->fetchOne('SELECT body FROM comment WHERE id = ?', [$comment['id']]));
        self::assertNull($db->fetchOne('SELECT body FROM square_message WHERE id = ?', [$message['id']]));
        self::assertSame([], $this->request('GET', '/search?type=people&q=camila', token: $reader)['people']);
        $this->request('GET', '/users/camila', token: $reader);
        $this->assertStatus(404);
        $this->request('GET', '/library/documents/'.$personal->id.'/file', token: $reader);
        $this->assertStatus(404);
        $doc = $this->request('GET', '/library/documents/'.$collective->id, token: $reader);
        $this->assertStatus(200);
        self::assertSame('available', $doc['state']);
        self::assertNull($doc['responsible']);
        $history = $this->request('GET', '/squares/'.$square.'/messages', token: $reader);
        self::assertStringNotContainsString('camila', json_encode($history, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Mensagem pessoal', json_encode($history, JSON_THROW_ON_ERROR));
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $purge = new CommandTester($application->find('app:library:purge'));
        $purge->execute(['--dry-run' => true]);
        self::assertArrayHasKey($personal->file->storageKey, MemoryStorage::$objects);
        MemoryStorage::$failDeletes = true;
        try {
            $purge->execute([]);
            self::fail('Falha de storage deveria ser propagada');
        } catch (\App\Api\ApiProblem $error) {
            self::assertSame(503, $error->getStatus());
        } finally {
            MemoryStorage::$failDeletes = false;
        }
        self::assertSame('purge_pending', $this->em()->getConnection()->fetchOne('SELECT status FROM library_file WHERE id = ?', [$personal->file->id->toRfc4122()]));
        $purge->execute([]);
        self::assertArrayNotHasKey($personal->file->storageKey, MemoryStorage::$objects);
        self::assertArrayHasKey($collective->file->storageKey, MemoryStorage::$objects);
        $new = $this->request('POST', '/auth/social', ['provider' => 'google', 'idToken' => 'google_valid|sub-original|'.$email.'|1|Camila']);
        $this->assertStatus(200);
        self::assertNotSame($id->toRfc4122(), $this->request('GET', '/me', token: $new['accessToken'])['id']);
    }

    public function testSocialOnlyAccountRequiresItsOwnIdentityAndAppleRevocation(): void
    {
        $user = $this->createUser('appleuser');
        $user->setPasswordHash('', new \DateTimeImmutable());
        $this->em()->persist(new UserAuthIdentity($user, 'apple', 'apple-sub', $user->getEmail(), new \DateTimeImmutable()));
        $this->em()->flush();
        $tokens = $this->request('POST', '/auth/social', ['provider' => 'apple', 'idToken' => 'apple_valid|apple-sub|appleuser@exemplo.org|1', 'nonce' => 'nonce']);
        $this->assertStatus(200);
        $this->client->disableReboot();
        $revoker = static::getContainer()->get(\App\Social\AppleAuthorizationRevoker::class);
        self::assertInstanceOf(\App\Tests\Social\FakeAppleAuthorizationRevoker::class, $revoker);
        $proof = ['confirmation' => 'EXCLUIR', 'provider' => 'apple', 'idToken' => 'apple_valid|apple-sub||1', 'nonce' => 'nonce', 'authorizationCode' => 'fresh-code'];
        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $tokens['accessToken']);
        $this->assertStatus(400);
        $this->request('POST', '/me/account-deletion', array_replace($proof, ['idToken' => 'apple_valid|another-sub||1']), $tokens['accessToken']);
        $this->assertStatus(400);
        $revoker->fail = true;
        $this->request('POST', '/me/account-deletion', $proof, $tokens['accessToken']);
        $this->assertStatus(503);
        self::assertSame(AccountStatus::Active, $this->fresh(User::class, $user->getId())->getStatus());
        $revoker->fail = false;
        $this->request('POST', '/me/account-deletion', $proof, $tokens['accessToken']);
        $this->assertStatus(204);
        self::assertSame(2, $revoker->calls);
    }

    public function testConfirmationPasswordAndOwnershipFailuresDoNotChangeTheAccount(): void
    {
        $user = $this->createUser();
        $this->createComuna('propria', owner: $user);
        $tokens = $this->login($user->getEmail());
        $this->request('POST', '/me/account-deletion', ['password' => self::PASSWORD], $tokens['accessToken']);
        $this->assertStatus(422);
        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => 'wrong'], $tokens['accessToken']);
        $this->assertStatus(400);
        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $tokens['accessToken']);
        $this->assertStatus(409);
        $this->request('POST', '/comunas/propria/archive', token: $tokens['accessToken']);
        $this->assertStatus(200);
        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $tokens['accessToken']);
        $this->assertStatus(204);
        self::assertNull($this->em()->getConnection()->fetchOne("SELECT owner_id FROM comuna WHERE slug = 'propria'"));
    }
}
