<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Comuna;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Tests\ApiTestCase;

final class ComunaTest extends ApiTestCase
{
    public function testListIsPaginatedWithStableCursor(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
            $this->createComuna('comuna-'.$slug);
        }

        $first = $this->request('GET', '/comunas?limit=2');
        $this->assertStatus(200);
        $second = $this->request('GET', '/comunas?limit=2&cursor='.$first['nextCursor']);
        $third = $this->request('GET', '/comunas?limit=2&cursor='.$second['nextCursor']);

        $slugs = array_column([...$first['items'], ...$second['items'], ...$third['items']], 'slug');
        self::assertSame(['comuna-a', 'comuna-b', 'comuna-c', 'comuna-d', 'comuna-e'], $slugs);
        self::assertNull($third['nextCursor']);
    }

    public function testInvalidPaginationIsRejected(): void
    {
        $this->request('GET', '/comunas?cursor=nao-e-uuid');
        $this->assertStatus(400);
        $this->request('GET', '/comunas?limit=500');
        $this->assertStatus(400);
    }

    public function testDetailShowsViewerState(): void
    {
        $comuna = $this->createComuna('tarifa');
        $user = $this->createUser('camila');
        $this->addMember($comuna, $user, ComunaRole::Moderator);

        $anonymous = $this->request('GET', '/comunas/tarifa');
        self::assertFalse($anonymous['viewer']['isMember']);
        self::assertNull($anonymous['viewer']['role']);
        self::assertNotContains(true, $anonymous['viewer']['permissions']);
        self::assertSame([['title' => 'Respeito sempre', 'body' => 'Sem ataques pessoais.']], $anonymous['rules']);
        self::assertSame('active', $anonymous['status']);
        self::assertSame([['camila', 'moderator']], array_map(static fn ($m) => [$m['handle'], $m['role']], $anonymous['moderation']));

        $mine = $this->request('GET', '/comunas/tarifa', token: $this->login('camila@exemplo.org')['accessToken']);
        self::assertTrue($mine['viewer']['isMember']);
        self::assertSame('moderator', $mine['viewer']['role']);
    }

    public function testJoinAndLeaveAreIdempotentAndCountedOnServer(): void
    {
        $comuna = $this->createComuna('tarifa');
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $joined = $this->request('PUT', '/comunas/tarifa/membership', token: $token);
        $this->assertStatus(200);
        self::assertSame(['slug' => 'tarifa', 'isMember' => true, 'role' => 'member', 'memberCount' => 1], $joined);
        $again = $this->request('PUT', '/comunas/tarifa/membership', token: $token);
        self::assertSame(1, $again['memberCount']);

        $this->request('DELETE', '/comunas/tarifa/membership', token: $token);
        $this->assertStatus(204);
        $this->request('DELETE', '/comunas/tarifa/membership', token: $token);
        $this->assertStatus(204);
        self::assertSame(0, $this->fresh(Comuna::class, $comuna->getId())->getMemberCount());
    }

    public function testJoinRequiresAuthentication(): void
    {
        $this->createComuna('tarifa');

        $this->request('PUT', '/comunas/tarifa/membership');

        $this->assertStatus(401);
    }

    public function testPrivateComunaCannotBeJoinedDirectly(): void
    {
        $this->createComuna('fechada', ComunaVisibility::Private);
        $this->createUser('camila');

        $body = $this->request('PUT', '/comunas/fechada/membership', token: $this->login('camila@exemplo.org')['accessToken']);

        $this->assertStatus(403);
        self::assertSame('/errors/comuna-requires-approval', $body['type']);
    }

    public function testFounderCannotLeave(): void
    {
        $comuna = $this->createComuna('tarifa');
        $this->addMember($comuna, $this->createUser('fundadora'), ComunaRole::Founder);

        $this->request('DELETE', '/comunas/tarifa/membership', token: $this->login('fundadora@exemplo.org')['accessToken']);

        $this->assertStatus(409);
    }

    public function testPrivateComunaPostsAreOnlyForMembers(): void
    {
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private);
        $member = $this->createUser('membro');
        $this->createUser('visitante');
        $this->addMember($comuna, $member);
        $post = $this->createPost($member, $comuna, 'Assunto interno');

        $this->request('GET', '/comunas/fechada/posts');
        $this->assertStatus(403);
        $this->request('GET', '/comunas/fechada/posts', token: $this->login('visitante@exemplo.org')['accessToken']);
        $this->assertStatus(403);
        // Nem pelo endereço direto da publicação.
        $this->request('GET', '/posts/'.$post->getId()->toRfc4122(), token: $this->login('visitante@exemplo.org')['accessToken']);
        $this->assertStatus(404);

        $page = $this->request('GET', '/comunas/fechada/posts', token: $this->login('membro@exemplo.org')['accessToken']);
        $this->assertStatus(200);
        self::assertSame('Assunto interno', $page['items'][0]['body']);
    }
}
