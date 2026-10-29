<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AdminAuditLog;
use App\Entity\Comment;
use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\Post;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Enum\ContentStatus;
use App\Tests\ApiTestCase;

/** Autorização com escopo de comuna: proprietário, moderação, membros e quem é de fora. */
final class ComunaPermissionsTest extends ApiTestCase
{
    private function role(Comuna $comuna, string $handle): ?ComunaRole
    {
        $this->em()->clear();
        foreach ($this->em()->getRepository(ComunaMembership::class)->findBy(['comuna' => $comuna->getId()]) as $m) {
            if ($m->getUser()->getHandle() === $handle) {
                return $m->getRole();
            }
        }

        return null;
    }

    public function testStrangerCannotChangeSomeoneElsesComuna(): void
    {
        $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->createUser('estranha');
        $token = $this->login('estranha@exemplo.org')['accessToken'];

        foreach ([
            ['PATCH', '/comunas/tarifa', ['description' => 'Tomada'], '/errors/not-comuna-owner'],
            ['POST', '/comunas/tarifa/archive', null, '/errors/not-comuna-owner'],
            ['DELETE', '/comunas/tarifa', null, '/errors/not-comuna-owner'],
            ['PUT', '/comunas/tarifa/moderators/estranha', null, '/errors/not-comuna-owner'],
            ['PUT', '/comunas/tarifa/ownership-transfer', ['handle' => 'estranha'], '/errors/not-comuna-owner'],
            ['DELETE', '/comunas/tarifa/members/dona', null, '/errors/not-comuna-moderator'],
            ['GET', '/comunas/tarifa/join-requests', null, '/errors/not-comuna-moderator'],
            ['GET', '/comunas/tarifa/members', null, '/errors/members-only'],
        ] as [$method, $uri, $body, $type]) {
            $response = $this->request($method, $uri, $body, $token, 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json');
            $this->assertStatus(403);
            self::assertSame($type, $response['type'], $method.' '.$uri);
        }
        self::assertSame('Descrição de tarifa', $this->fresh(Comuna::class, $this->em()->getRepository(Comuna::class)->findOneBySlug('tarifa')?->getId())->getDescription());
    }

    public function testOwnerEditsDescriptionAndRulesButNeverTheName(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $patch = 'application/merge-patch+json';

        $three = [['title' => 'Um', 'body' => 'a'], ['title' => 'Dois', 'body' => 'b'], ['title' => 'Três', 'body' => 'c']];
        $updated = $this->request('PATCH', '/comunas/tarifa', ['description' => 'Nova descrição', 'rules' => $three], $token, $patch);
        $this->assertStatus(200);
        self::assertSame('Nova descrição', $updated['description']);
        self::assertSame($three, $updated['rules']);

        $updated = $this->request('PATCH', '/comunas/tarifa', ['rules' => [['title' => 'Só uma', 'body' => '']]], $token, $patch);
        $this->assertStatus(200);
        self::assertSame([['title' => 'Só uma', 'body' => '']], $updated['rules']);
        self::assertSame('Nova descrição', $updated['description']);

        foreach ([['name' => 'Outro nome'], ['slug' => 'outro'], ['visibility' => 'private'], ['owner' => 'x'], ['status' => 'active']] as $forbidden) {
            $this->request('PATCH', '/comunas/tarifa', $forbidden, $token, $patch);
            $this->assertStatus(400);
        }
        self::assertSame('Tarifa', $this->fresh(Comuna::class, $comuna->getId())->getName());
        self::assertSame('tarifa', $this->fresh(Comuna::class, $comuna->getId())->getSlug());
    }

    public function testModeratorDoesOnlyWhatModerationAllows(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $this->createUser('mod'), ComunaRole::Moderator);
        $this->addMember($comuna, $this->createUser('outramod'), ComunaRole::Moderator);
        $member = $this->createUser('membro');
        $this->addMember($comuna, $member);
        $this->addMember($comuna, $this->createUser('chato'));
        $memberPost = $this->createPost($member, $comuna, 'Fora do tema');
        $ownerPost = $this->createPost($owner, $comuna, 'Recado da dona');
        $this->createUser('quer.entrar');
        $this->request('PUT', '/comunas/tarifa/join-request', token: $this->login('quer.entrar@exemplo.org')['accessToken']);
        $requestId = $this->em()->getRepository(\App\Entity\ComunaJoinRequest::class)->findOneBy([])?->getId()->toRfc4122();
        $token = $this->login('mod@exemplo.org')['accessToken'];
        $patch = 'application/merge-patch+json';

        // Proibido para a moderação.
        foreach ([
            ['PATCH', '/comunas/tarifa', ['description' => 'x'], '/errors/not-comuna-owner'],
            ['POST', '/comunas/tarifa/archive', null, '/errors/not-comuna-owner'],
            ['DELETE', '/comunas/tarifa', null, '/errors/not-comuna-owner'],
            ['PUT', '/comunas/tarifa/moderators/membro', null, '/errors/not-comuna-owner'],
            ['DELETE', '/comunas/tarifa/moderators/outramod', null, '/errors/not-comuna-owner'],
            ['DELETE', '/comunas/tarifa/members/outramod', null, '/errors/not-comuna-owner'],
            ['DELETE', '/comunas/tarifa/members/dona', null, '/errors/cannot-remove-owner'],
            ['PUT', '/comunas/tarifa/ownership-transfer', ['handle' => 'mod'], '/errors/not-comuna-owner'],
            ['POST', '/posts/'.$ownerPost->getId()->toRfc4122().'/removal', ['reason' => 'Não gostei'], '/errors/cannot-moderate-content'],
        ] as [$method, $uri, $body, $type]) {
            $response = $this->request($method, $uri, $body, $token, 'PATCH' === $method ? $patch : 'application/json');
            $this->assertStatus(403);
            self::assertSame($type, $response['type'], $method.' '.$uri);
        }

        // Permitido para a moderação.
        $this->request('GET', '/comunas/tarifa/join-requests', token: $token);
        $this->assertStatus(200);
        $this->request('POST', '/comunas/tarifa/join-requests/'.$requestId.'/approve', token: $token);
        $this->assertStatus(200);
        $this->request('POST', '/posts/'.$memberPost->getId()->toRfc4122().'/removal', ['reason' => 'Fora do tema da comuna'], $token);
        $this->assertStatus(204);
        $this->request('DELETE', '/comunas/tarifa/members/chato', token: $token);
        $this->assertStatus(204);

        self::assertSame(ContentStatus::Removed, $this->fresh(Post::class, $memberPost->getId())->getStatus());
        self::assertSame(ContentStatus::Published, $this->fresh(Post::class, $ownerPost->getId())->getStatus());
        self::assertNull($this->role($comuna, 'chato'));
        self::assertSame(ComunaRole::Member, $this->role($comuna, 'quer.entrar'));
        self::assertSame(ComunaRole::Moderator, $this->role($comuna, 'outramod'));
        $removal = $this->em()->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'comuna.post_removed']);
        self::assertSame('Fora do tema da comuna', $removal?->getReason());
        self::assertTrue($removal->getTargetUserId()?->equals($member->getId()));
    }

    public function testModeratorGetsNoPlatformPowers(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('mod'), ComunaRole::Moderator);
        $token = $this->login('mod@exemplo.org')['accessToken'];

        $this->request('GET', '/admin/security-events?reason=investigacao-qualquer', token: $token);
        $this->assertStatus(403);
        $this->request('GET', '/admin/evidence-holds', token: $token);
        $this->assertStatus(403);
        self::assertSame('user', $this->request('GET', '/me', token: $token)['platformRole']);
    }

    public function testModerationOfOneComunaDoesNotReachAnother(): void
    {
        $owner = $this->createUser('dona');
        $a = $this->createComuna('comuna-a', owner: $owner);
        $b = $this->createComuna('comuna-b', ComunaVisibility::Private, $this->createUser('outra.dona'));
        $this->addMember($a, $this->createUser('mod'), ComunaRole::Moderator);
        $member = $this->createUser('membro');
        $this->addMember($b, $member);
        $postInB = $this->createPost($member, $b, 'Assunto de B');
        $token = $this->login('mod@exemplo.org')['accessToken'];

        $this->request('DELETE', '/comunas/comuna-b/members/membro', token: $token);
        $this->assertStatus(403);
        $this->request('GET', '/comunas/comuna-b/join-requests', token: $token);
        $this->assertStatus(403);
        // Comuna privada de que não participa: a publicação nem aparece.
        $this->request('POST', '/posts/'.$postInB->getId()->toRfc4122().'/removal', ['reason' => 'Tentativa'], $token);
        $this->assertStatus(404);
        self::assertSame(ContentStatus::Published, $this->fresh(Post::class, $postInB->getId())->getStatus());
    }

    public function testRemovedMemberImmediatelyLosesPrivateAccess(): void
    {
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $this->createUser('dona'));
        $member = $this->createUser('membro');
        $this->addMember($comuna, $member);
        $post = $this->createPost($member, $comuna, 'Assunto interno');
        $memberToken = $this->login('membro@exemplo.org')['accessToken'];
        $this->request('GET', '/comunas/fechada/posts', token: $memberToken);
        $this->assertStatus(200);

        $this->request('DELETE', '/comunas/fechada/members/membro', token: $this->login('dona@exemplo.org')['accessToken']);
        $this->assertStatus(204);

        // O mesmo token, sem login novo: o acesso é conferido a cada requisição.
        $this->request('GET', '/comunas/fechada/posts', token: $memberToken);
        $this->assertStatus(403);
        $this->request('GET', '/posts/'.$post->getId()->toRfc4122(), token: $memberToken);
        $this->assertStatus(404);
        $this->request('GET', '/comunas/fechada/members', token: $memberToken);
        $this->assertStatus(403);
        $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => 'Ainda estou aqui?'], $memberToken);
        $this->assertStatus(403);
        self::assertSame(1, $this->fresh(Comuna::class, $comuna->getId())->getMemberCount());
    }

    public function testNoLocalActionRemovesTheOwner(): void
    {
        $dona = $this->createUser('dona');
        $comuna = $this->createComuna('tarifa', owner: $dona);
        $this->addMember($comuna, $this->createUser('mod'), ComunaRole::Moderator);
        $owner = $this->login('dona@exemplo.org')['accessToken'];
        $mod = $this->login('mod@exemplo.org')['accessToken'];

        $this->request('DELETE', '/comunas/tarifa/members/dona', token: $mod);
        $this->assertStatus(403);
        $this->request('DELETE', '/comunas/tarifa/members/dona', token: $owner);
        $this->assertStatus(403);
        $this->request('DELETE', '/comunas/tarifa/moderators/dona', token: $owner);
        $this->assertStatus(403);
        $this->request('PUT', '/comunas/tarifa/moderators/dona', token: $owner);
        $this->assertStatus(403);
        $this->request('DELETE', '/comunas/tarifa/membership', token: $owner);
        $this->assertStatus(409);

        self::assertSame(ComunaRole::Founder, $this->role($comuna, 'dona'));
        self::assertTrue($this->fresh(Comuna::class, $comuna->getId())->isOwnedBy($dona));
    }

    public function testOwnerAppointsAndDismissesModeratorsWithAudit(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('membro'));
        $this->createUser('fora');
        $token = $this->login('dona@exemplo.org')['accessToken'];

        $view = $this->request('PUT', '/comunas/tarifa/moderators/membro', token: $token);
        $this->assertStatus(200);
        self::assertSame(['handle' => 'membro', 'role' => 'moderator'], array_intersect_key($view, array_flip(['handle', 'role'])));
        self::assertSame(ComunaRole::Moderator, $this->role($comuna, 'membro'));

        $body = $this->request('PUT', '/comunas/tarifa/moderators/fora', token: $token);
        $this->assertStatus(409);
        self::assertSame('/errors/target-not-member', $body['type']);

        $this->request('DELETE', '/comunas/tarifa/moderators/membro', token: $token);
        $this->assertStatus(204);
        self::assertSame(ComunaRole::Member, $this->role($comuna, 'membro'));

        $actions = array_map(static fn (AdminAuditLog $l) => $l->getAction(), $this->em()->getRepository(AdminAuditLog::class)->findBy([], ['occurredAt' => 'ASC']));
        self::assertSame(['comuna.moderator_appointed', 'comuna.moderator_dismissed'], $actions);
    }

    public function testMembersListIsOnlyForMembersAndModerationIsPublic(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $this->addMember($comuna, $this->createUser('mod'), ComunaRole::Moderator);
        $this->addMember($comuna, $this->createUser('membro'));
        $this->createUser('fora');

        $list = $this->request('GET', '/comunas/tarifa/members', token: $this->login('membro@exemplo.org')['accessToken']);
        $this->assertStatus(200);
        self::assertSame([['dona', 'founder'], ['mod', 'moderator'], ['membro', 'member']], array_map(static fn ($m) => [$m['handle'], $m['role']], $list['items']));
        self::assertSame(['id', 'handle', 'displayName', 'role', 'joinedAt', 'avatarUrl'], array_keys($list['items'][0]));

        $this->request('GET', '/comunas/tarifa/members', token: $this->login('fora@exemplo.org')['accessToken']);
        $this->assertStatus(403);
        $detail = $this->request('GET', '/comunas/tarifa');
        self::assertSame(['dona', 'mod'], array_column($detail['moderation'], 'handle'));
    }

    public function testModerationRemovesCommentsInsideTheComuna(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona'));
        $member = $this->createUser('membro');
        $this->addMember($comuna, $member);
        $post = $this->createPost($member, $comuna);
        $comment = new Comment($post, null, $member, 'Ofensa', new \DateTimeImmutable());
        $this->em()->persist($comment);
        $this->em()->flush();
        $token = $this->login('dona@exemplo.org')['accessToken'];

        $page = $this->request('GET', '/posts/'.$post->getId()->toRfc4122().'/comments', token: $token);
        self::assertTrue($page['items'][0]['viewer']['canRemove']);

        $this->request('POST', '/comments/'.$comment->getId()->toRfc4122().'/removal', ['reason' => 'Ataque pessoal'], $token);
        $this->assertStatus(204);
        $page = $this->request('GET', '/posts/'.$post->getId()->toRfc4122().'/comments', token: $token);
        self::assertSame('removed', $page['items'][0]['status']);
        self::assertNull($page['items'][0]['body']);

        // Um membro comum não modera.
        $this->request('POST', '/comments/'.$comment->getId()->toRfc4122().'/removal', ['reason' => 'Tentativa'], $this->login('membro@exemplo.org')['accessToken']);
        $this->assertStatus(403);
    }

    /** @ com ponto é válido: listas e ações pelo @ precisam funcionar com ele. */
    public function testHandlesWithDotsWorkInListsAndActions(): void
    {
        $comuna = $this->createComuna('tarifa', owner: $this->createUser('dona.ponto'));
        $this->addMember($comuna, $this->createUser('membro.ponto'));
        $token = $this->login('dona.ponto@exemplo.org')['accessToken'];

        self::assertSame(['dona.ponto'], array_column($this->request('GET', '/comunas/tarifa')['moderation'], 'handle'));
        $this->assertStatus(200);
        self::assertSame('moderator', $this->request('PUT', '/comunas/tarifa/moderators/membro.ponto', token: $token)['role']);
        self::assertSame(['dona.ponto', 'membro.ponto'], array_column($this->request('GET', '/comunas/tarifa/members', token: $token)['items'], 'handle'));
        $this->request('DELETE', '/comunas/tarifa/members/membro.ponto', token: $token);
        $this->assertStatus(204);
    }
}
