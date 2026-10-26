<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AdminAuditLog;
use App\Entity\Comment;
use App\Entity\Comuna;
use App\Entity\ComunaJoinRequest;
use App\Entity\ComunaMembership;
use App\Entity\ComunaOwnershipTransfer;
use App\Entity\Post;
use App\Entity\SecurityEvent;
use App\Entity\User;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\JoinRequestStatus;
use App\Enum\OwnershipTransferStatus;
use App\Tests\ApiTestCase;

/** Pedidos de entrada, arquivamento, exclusão definitiva e transferência de propriedade. */
final class ComunaLifecycleTest extends ApiTestCase
{
    public function testPrivateComunaEntryNeedsApproval(): void
    {
        $dona = $this->createUser('dona');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $dona);
        $this->createPost($dona, $comuna, 'Assunto interno');
        $this->createUser('camila');
        $camila = $this->login('camila@exemplo.org')['accessToken'];
        $owner = $this->login('dona@exemplo.org')['accessToken'];

        $this->request('PUT', '/comunas/fechada/membership', token: $camila);
        $this->assertStatus(403);
        $request = $this->request('PUT', '/comunas/fechada/join-request', token: $camila);
        $this->assertStatus(200);
        self::assertSame('pending', $request['status']);
        self::assertSame($request['id'], $this->request('PUT', '/comunas/fechada/join-request', token: $camila)['id']);
        self::assertSame('pending', $this->request('GET', '/comunas/fechada', token: $camila)['viewer']['joinRequest']);
        // Pedir não dá acesso.
        $this->request('GET', '/comunas/fechada/posts', token: $camila);
        $this->assertStatus(403);

        $pending = $this->request('GET', '/comunas/fechada/join-requests', token: $owner);
        self::assertSame(['camila'], array_map(static fn ($r) => $r['user']['handle'], $pending['items']));
        $decided = $this->request('POST', '/comunas/fechada/join-requests/'.$request['id'].'/approve', token: $owner);
        $this->assertStatus(200);
        self::assertSame('approved', $decided['status']);

        $this->request('GET', '/comunas/fechada/posts', token: $camila);
        $this->assertStatus(200);
        self::assertSame(2, $this->fresh(Comuna::class, $comuna->getId())->getMemberCount());
        $this->request('POST', '/comunas/fechada/join-requests/'.$request['id'].'/reject', token: $owner);
        $this->assertStatus(409);
    }

    public function testRejectedOrCancelledRequestsGiveNoAccess(): void
    {
        $this->createComuna('fechada', ComunaVisibility::Private, $this->createUser('dona'));
        $this->createComuna('aberta', owner: $this->createUser('outra'));
        $this->createUser('camila');
        $this->createUser('bia');
        $camila = $this->login('camila@exemplo.org')['accessToken'];

        $request = $this->request('PUT', '/comunas/fechada/join-request', token: $camila);
        $this->request('POST', '/comunas/fechada/join-requests/'.$request['id'].'/reject', token: $this->login('dona@exemplo.org')['accessToken']);
        $this->assertStatus(200);
        $this->request('GET', '/comunas/fechada/posts', token: $camila);
        $this->assertStatus(403);

        $bia = $this->login('bia@exemplo.org')['accessToken'];
        $this->request('PUT', '/comunas/fechada/join-request', token: $bia);
        $this->request('DELETE', '/comunas/fechada/join-request', token: $bia);
        $this->assertStatus(204);
        self::assertSame(JoinRequestStatus::Cancelled, $this->em()->getRepository(ComunaJoinRequest::class)->findOneBy(['user' => $this->fresh(User::class, $this->em()->getRepository(User::class)->findOneByHandle('bia')?->getId())])?->getStatus());

        $body = $this->request('PUT', '/comunas/aberta/join-request', token: $camila);
        $this->assertStatus(409);
        self::assertSame('/errors/join-request-not-needed', $body['type']);
    }

    public function testSoleMemberComunaIsDeletedAndNameIsFreedAgain(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('so-minha', ComunaVisibility::Private, $owner);
        $post = $this->createPost($owner, $comuna, 'Rascunho');
        $id = $comuna->getId();
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $securityEventsBefore = $this->em()->getRepository(SecurityEvent::class)->count([]);

        $this->request('DELETE', '/comunas/so-minha', token: $token);

        $this->assertStatus(204);
        $this->em()->clear();
        self::assertNull($this->em()->find(Comuna::class, $id));
        self::assertNull($this->em()->find(Post::class, $post->getId()));
        self::assertSame(0, $this->em()->getRepository(ComunaMembership::class)->count([]));
        // Registros que ficam: auditoria (com o ID da comuna) e eventos de segurança da conta.
        $audit = $this->em()->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'comuna.deleted']);
        self::assertSame($id->toRfc4122(), $audit?->getDetails()['comuna_id']);
        self::assertSame($securityEventsBefore, $this->em()->getRepository(SecurityEvent::class)->count([]));
        self::assertTrue($this->request('GET', '/comuna-names/availability?name=So%20Minha', token: $token)['available']);
    }

    public function testComunaWithOtherMembersIsOnlyArchivable(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('membro'));
        $token = $this->login('dona@exemplo.org')['accessToken'];

        $body = $this->request('DELETE', '/comunas/tarifa', token: $token);

        $this->assertStatus(409);
        self::assertSame('/errors/comuna-has-members', $body['type']);
        self::assertStringContainsString('arquivá-la', $body['detail']);
        // Ninguém foi removido para contornar a regra.
        self::assertSame(2, $this->fresh(Comuna::class, $comuna->getId())->getMemberCount());
        self::assertSame(2, $this->em()->getRepository(ComunaMembership::class)->count(['comuna' => $comuna->getId()]));

        $archived = $this->request('POST', '/comunas/tarifa/archive', token: $token);
        $this->assertStatus(200);
        self::assertSame('archived', $archived['status']);
        self::assertNotNull($archived['archivedAt']);
        self::assertSame(2, $archived['memberCount']);
    }

    public function testContentFromFormerMembersBlocksDeletion(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $former = $this->createUser('ex');
        $this->addMember($comuna, $former);
        $post = $this->createPost($former, $comuna, 'Texto de outra pessoa');
        $exToken = $this->login('ex@exemplo.org')['accessToken'];
        $this->request('DELETE', '/comunas/tarifa/membership', token: $exToken);
        $this->assertStatus(204);

        $body = $this->request('DELETE', '/comunas/tarifa', token: $this->login('dona@exemplo.org')['accessToken']);

        $this->assertStatus(409);
        self::assertSame('/errors/comuna-has-content', $body['type']);
        self::assertSame('Texto de outra pessoa', $this->fresh(Post::class, $post->getId())->getBody());

        // Se a própria autoria apagou, o texto já saiu do banco e não impede mais.
        $this->request('DELETE', '/posts/'.$post->getId()->toRfc4122(), token: $exToken);
        $this->request('DELETE', '/comunas/tarifa', token: $this->login('dona@exemplo.org')['accessToken']);
        $this->assertStatus(204);
    }

    public function testCommentsFromOthersAlsoBlockDeletion(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $post = $this->createPost($owner, $comuna);
        $this->em()->persist(new Comment($post, null, $this->createUser('visita'), 'Comentário de visita', new \DateTimeImmutable()));
        $this->em()->flush();

        $body = $this->request('DELETE', '/comunas/tarifa', token: $this->login('dona@exemplo.org')['accessToken']);

        $this->assertStatus(409);
        self::assertSame('/errors/comuna-has-content', $body['type']);
    }

    public function testArchivedComunaAcceptsNoNewContentOrMembers(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $member = $this->createUser('membro');
        $this->addMember($comuna, $member);
        $post = $this->createPost($member, $comuna, 'Histórico');
        $this->createUser('nova');
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $memberToken = $this->login('membro@exemplo.org')['accessToken'];
        $this->request('POST', '/comunas/tarifa/archive', token: $ownerToken);

        foreach ([
            ['POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => 'Nova'], $memberToken],
            ['POST', '/posts/'.$post->getId()->toRfc4122().'/comments', ['body' => 'Resposta'], $memberToken],
            ['PUT', '/comunas/tarifa/membership', null, $this->login('nova@exemplo.org')['accessToken']],
            ['PATCH', '/comunas/tarifa', ['description' => 'Mudança'], $ownerToken],
            ['PUT', '/comunas/tarifa/moderators/membro', null, $ownerToken],
            ['PUT', '/comunas/tarifa/ownership-transfer', ['handle' => 'membro'], $ownerToken],
        ] as [$method, $uri, $body, $token]) {
            $response = $this->request($method, $uri, $body, $token, 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json');
            $this->assertStatus(409);
            self::assertSame('/errors/comuna-archived', $response['type'], $method.' '.$uri);
        }

        // Excluir uma comuna arquivada não é possível pelo app.
        $body = $this->request('DELETE', '/comunas/tarifa', token: $ownerToken);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-archived', $body['type']);
        // Não existe "desarquivar".
        $this->request('POST', '/comunas/tarifa/unarchive', token: $ownerToken);
        $this->assertStatus(404);
        self::assertTrue($this->fresh(Comuna::class, $comuna->getId())->isArchived());
    }

    public function testFormerMembersKeepReadingAndPrivacyIsKept(): void
    {
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $this->createUser('dona'));
        $member = $this->createUser('membro');
        $this->addMember($comuna, $member);
        $post = $this->createPost($member, $comuna, 'Ata da assembleia');
        $this->createUser('fora');
        $this->request('POST', '/comunas/fechada/archive', token: $this->login('dona@exemplo.org')['accessToken']);
        $memberToken = $this->login('membro@exemplo.org')['accessToken'];
        $outsider = $this->login('fora@exemplo.org')['accessToken'];

        $page = $this->request('GET', '/comunas/fechada/posts', token: $memberToken);
        $this->assertStatus(200);
        self::assertSame('Ata da assembleia', $page['items'][0]['body']);
        self::assertFalse($page['items'][0]['viewer']['canComment']);
        $this->request('GET', '/posts/'.$post->getId()->toRfc4122().'/comments', token: $memberToken);
        $this->assertStatus(200);

        $this->request('GET', '/comunas/fechada/posts', token: $outsider);
        $this->assertStatus(403);
        $this->request('GET', '/comunas/fechada/posts');
        $this->assertStatus(403);
        $this->request('GET', '/posts/'.$post->getId()->toRfc4122(), token: $outsider);
        $this->assertStatus(404);
        $body = $this->request('PUT', '/comunas/fechada/join-request', token: $outsider);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-archived', $body['type']);
    }

    public function testArchivingClosesPendingRequestsAndTransfer(): void
    {
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('membro'));
        $this->createUser('quer');
        $this->request('PUT', '/comunas/fechada/join-request', token: $this->login('quer@exemplo.org')['accessToken']);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $this->request('PUT', '/comunas/fechada/ownership-transfer', ['handle' => 'membro'], $token);

        $this->request('POST', '/comunas/fechada/archive', token: $token);

        $this->em()->clear();
        self::assertSame(JoinRequestStatus::Cancelled, $this->em()->getRepository(ComunaJoinRequest::class)->findOneBy([])?->getStatus());
        self::assertSame(OwnershipTransferStatus::Cancelled, $this->em()->getRepository(ComunaOwnershipTransfer::class)->findOneBy([])?->getStatus());
        $this->request('POST', '/comunas/fechada/ownership-transfer/accept', token: $this->login('membro@exemplo.org')['accessToken']);
        $this->assertStatus(404);
    }

    public function testOwnershipTransferNeedsAcceptanceAndIsAtomic(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('herdeira'));
        $this->addMember($comuna, $this->createUser('outra'));
        $owner = $this->login('dona@exemplo.org')['accessToken'];
        $heir = $this->login('herdeira@exemplo.org')['accessToken'];

        $proposal = $this->request('PUT', '/comunas/tarifa/ownership-transfer', ['handle' => 'herdeira'], $owner);
        $this->assertStatus(200);
        self::assertSame('pending', $proposal['status']);

        // Antes do aceite, nada muda.
        $view = $this->request('GET', '/comunas/tarifa', token: $owner);
        self::assertSame('founder', $view['viewer']['role']);
        self::assertSame('outgoing', $view['viewer']['ownershipTransfer']['direction']);
        $heirView = $this->request('GET', '/comunas/tarifa', token: $heir);
        self::assertSame('incoming', $heirView['viewer']['ownershipTransfer']['direction']);
        self::assertSame('member', $heirView['viewer']['role']);
        self::assertNull($this->request('GET', '/comunas/tarifa', token: $this->login('outra@exemplo.org')['accessToken'])['viewer']['ownershipTransfer']);
        $this->request('PATCH', '/comunas/tarifa', ['description' => 'Ainda não'], $heir, 'application/merge-patch+json');
        $this->assertStatus(403);

        // Só quem recebeu pode aceitar.
        $this->request('POST', '/comunas/tarifa/ownership-transfer/accept', token: $this->login('outra@exemplo.org')['accessToken']);
        $this->assertStatus(404);

        $accepted = $this->request('POST', '/comunas/tarifa/ownership-transfer/accept', token: $heir);
        $this->assertStatus(200);
        self::assertSame('accepted', $accepted['status']);

        $this->em()->clear();
        $saved = $this->em()->find(Comuna::class, $comuna->getId());
        self::assertSame('herdeira', $saved?->getOwner()?->getHandle());
        $founders = $this->em()->getRepository(ComunaMembership::class)->findBy(['comuna' => $comuna->getId(), 'role' => ComunaRole::Founder]);
        self::assertCount(1, $founders);
        self::assertSame('herdeira', $founders[0]->getUser()->getHandle());
        self::assertSame(ComunaRole::Member, $this->em()->getRepository(ComunaMembership::class)->findOneBy(['comuna' => $comuna->getId(), 'user' => $this->em()->getRepository(User::class)->findOneByHandle('dona')])?->getRole());

        // A antiga proprietária perde os poderes; a nova os tem.
        $this->request('PATCH', '/comunas/tarifa', ['description' => 'Tentativa'], $owner, 'application/merge-patch+json');
        $this->assertStatus(403);
        $this->request('PATCH', '/comunas/tarifa', ['description' => 'Nova fase'], $heir, 'application/merge-patch+json');
        $this->assertStatus(200);
        $this->request('DELETE', '/comunas/tarifa/membership', token: $owner);
        $this->assertStatus(204);
    }

    public function testTransferRespectsRecipientLimitAndCanBeDeclined(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $busy = $this->createUser('ocupada');
        foreach (['uma', 'duas', 'tres'] as $slug) {
            $this->createComuna($slug, owner: $busy);
        }
        $this->addMember($comuna, $busy);
        $this->createUser('fora');
        $owner = $this->login('dona@exemplo.org')['accessToken'];
        $busyToken = $this->login('ocupada@exemplo.org')['accessToken'];

        $body = $this->request('PUT', '/comunas/tarifa/ownership-transfer', ['handle' => 'fora'], $owner);
        $this->assertStatus(409);
        self::assertSame('/errors/target-not-member', $body['type']);

        $this->request('PUT', '/comunas/tarifa/ownership-transfer', ['handle' => 'ocupada'], $owner);
        $body = $this->request('POST', '/comunas/tarifa/ownership-transfer/accept', token: $busyToken);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-limit-reached', $body['type']);
        self::assertSame('dona', $this->fresh(Comuna::class, $comuna->getId())->getOwner()?->getHandle());

        $declined = $this->request('POST', '/comunas/tarifa/ownership-transfer/decline', token: $busyToken);
        $this->assertStatus(200);
        self::assertSame('declined', $declined['status']);
        self::assertSame('dona', $this->fresh(Comuna::class, $comuna->getId())->getOwner()?->getHandle());
    }

    public function testRemovingTheRecipientCancelsTheTransfer(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('membro'));
        $owner = $this->login('dona@exemplo.org')['accessToken'];
        $this->request('PUT', '/comunas/tarifa/ownership-transfer', ['handle' => 'membro'], $owner);

        $this->request('DELETE', '/comunas/tarifa/members/membro', token: $owner);

        self::assertSame(OwnershipTransferStatus::Cancelled, $this->em()->getRepository(ComunaOwnershipTransfer::class)->findOneBy([])?->getStatus());
    }

    public function testMyComunasListsRolesFromTheServer(): void
    {
        $camila = $this->createUser('camila');
        $this->createComuna('minha', owner: $camila);
        $other = $this->createComuna('alheia', owner: $this->createUser('dona'));
        $this->addMember($other, $camila, ComunaRole::Moderator);
        $this->createComuna('nao-participo');

        $page = $this->request('GET', '/me/comunas', token: $this->login('camila@exemplo.org')['accessToken']);

        $this->assertStatus(200);
        self::assertSame([['minha', 'founder'], ['alheia', 'moderator']], array_map(static fn ($c) => [$c['slug'], $c['viewer']['role']], $page['items']));
    }

    public function testPlatformTeamTransfersOwnershipFromTheConsole(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('mod'), ComunaRole::Moderator);
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel ?? self::bootKernel());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($application->find('app:comuna:transfer-owner'));

        $tester->execute(['slug' => 'tarifa', 'handle' => 'mod', 'reason' => 'Conta proprietária suspensa']);

        $tester->assertCommandIsSuccessful();
        $this->em()->clear();
        self::assertSame('mod', $this->em()->find(Comuna::class, $comuna->getId())?->getOwner()?->getHandle());
        $audit = $this->em()->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'comuna.ownership_transferred']);
        self::assertSame('console', $audit?->getActorLabel());
        self::assertSame('Conta proprietária suspensa', $audit->getReason());
    }

    /** Defesa em profundidade: mesmo um erro no código não cria dois proprietários. */
    public function testDatabaseRefusesASecondFounder(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->addMember($comuna, $this->createUser('intrusa'), ComunaRole::Founder);
    }
}
