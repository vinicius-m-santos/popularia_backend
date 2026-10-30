<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\AdminAuditLog;
use App\Entity\Comment;
use App\Entity\Comuna;
use App\Entity\ComunaMembership;
use App\Entity\ContentReport;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ComunaRole;
use App\Enum\PlatformRole;
use App\Tests\ApiTestCase;

final class ModerationTest extends ApiTestCase
{
    public function testReportValidatesTargetReasonPrivacyAndDedup(): void
    {
        $author = $this->createUser('autora');
        $reader = $this->createUser('leitora');
        $comuna = $this->createComuna('tarifa', owner: $author);
        $this->addMember($comuna, $reader);
        $post = $this->createPost($author, $comuna, 'Texto denunciável da tarifa');
        $comment = new Comment($post, null, $author, 'Resposta denunciável', new \DateTimeImmutable());
        $this->em()->persist($comment);
        $this->em()->flush();
        $token = $this->login($reader->getEmail())['accessToken'];
        $authorToken = $this->login($author->getEmail())['accessToken'];

        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'harassment', 'description' => "Linha 1\r\nLinha 2"], $token);
        $this->assertStatus(204);
        $this->request('POST', '/reports', ['targetType' => 'comment', 'targetId' => $comment->getId()->toRfc4122(), 'reasonCode' => 'spam'], $token);
        $this->assertStatus(204);
        $this->request('POST', '/reports', ['targetType' => 'profile', 'targetId' => $author->getId()->toRfc4122(), 'reasonCode' => 'fraud', 'description' => 'Pediu dinheiro'], $token);
        $this->assertStatus(204);
        $this->request('POST', '/reports', ['targetType' => 'comuna', 'targetId' => $comuna->getSlug(), 'reasonCode' => 'other', 'description' => 'A comuna inteira'], $token);
        $this->assertStatus(204);

        $stored = $this->em()->getRepository(ContentReport::class)->findOneBy(['targetType' => 'post']);
        self::assertInstanceOf(ContentReport::class, $stored);
        self::assertSame("Linha 1\nLinha 2", $stored->reason);
        self::assertSame('comuna', $stored->scope);
        self::assertSame('Texto denunciável da tarifa', $stored->snapshot);

        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'hate'], $token);
        $this->assertStatus(204);
        self::assertCount(1, $this->em()->getRepository(ContentReport::class)->findBy(['targetType' => 'post', 'reporter' => $this->fresh(User::class, $reader->getId())]));

        $selfReport = $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'spam'], $authorToken);
        $this->assertStatus(409);
        self::assertSame('/errors/cannot-report-self', $selfReport['type']);
        $this->request('POST', '/reports', ['targetType' => 'profile', 'targetId' => $author->getId()->toRfc4122(), 'reasonCode' => 'spam'], $authorToken);
        $this->assertStatus(409);
        $this->request('POST', '/reports', ['targetType' => 'comuna', 'targetId' => $comuna->getSlug(), 'reasonCode' => 'spam'], $authorToken);
        $this->assertStatus(409);

        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => '00000000-0000-0000-0000-000000000000', 'reasonCode' => 'spam'], $token);
        $this->assertStatus(404);
        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'spam', 'description' => str_repeat('a', 801)], $token);
        $this->assertStatus(422);
        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'spam', 'description' => '<b>html</b>'], $token);
        $this->assertStatus(400);

        $personal = $this->createPost($author, null, 'Publicação pessoal denunciável');
        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $personal->getId()->toRfc4122(), 'reasonCode' => 'illegal'], $token);
        $this->assertStatus(204);
        $personalReport = $this->em()->getRepository(ContentReport::class)->findOneBy(['targetId' => $personal->getId()]);
        self::assertInstanceOf(ContentReport::class, $personalReport);
        self::assertSame('platform', $personalReport->scope);
        $comunaReport = $this->em()->getRepository(ContentReport::class)->findOneBy(['targetType' => 'comuna']);
        self::assertInstanceOf(ContentReport::class, $comunaReport);
        self::assertSame('platform', $comunaReport->scope);
        self::assertNull($comunaReport->comuna);
    }

    public function testLocalQueueIsPrivateAndDecisionsAreServerAuthoritative(): void
    {
        $owner = $this->createUser('dona');
        $mod = $this->createUser('modera');
        $otherMod = $this->createUser('outra');
        $member = $this->createUser('membro');
        $platform = $this->createUser('plat', role: PlatformRole::PlatformModerator);
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $other = $this->createComuna('vizinha', owner: $otherMod);
        $this->addMember($comuna, $mod, ComunaRole::Moderator);
        $this->addMember($comuna, $member);
        $post = $this->createPost($member, $comuna, 'Fora do combinado na tarifa única');
        $replyParent = new Comment($post, null, $member, 'Comentário pai', new \DateTimeImmutable());
        $this->em()->persist($replyParent);
        $this->em()->flush();
        $reply = new Comment($post, $replyParent, $owner, 'Resposta que precisa continuar', new \DateTimeImmutable());
        $this->em()->persist($reply);
        $this->em()->flush();
        $memberToken = $this->login($member->getEmail())['accessToken'];
        $modToken = $this->login($mod->getEmail())['accessToken'];
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $otherToken = $this->login($otherMod->getEmail())['accessToken'];
        $platformToken = $this->login($platform->getEmail())['accessToken'];

        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'hate', 'description' => 'Contexto privado'], $ownerToken);
        $this->assertStatus(204);
        $this->request('POST', '/reports', ['targetType' => 'comment', 'targetId' => $replyParent->getId()->toRfc4122(), 'reasonCode' => 'threat'], $ownerToken);
        $this->assertStatus(204);
        $this->request('PUT', '/comunas/tarifa/pinned-posts/'.$post->getId()->toRfc4122(), token: $modToken);
        $this->assertStatus(200);

        $this->request('GET', '/comunas/tarifa/content-reports', token: $memberToken);
        $this->assertStatus(403);
        $this->request('GET', '/comunas/tarifa/content-reports', token: $otherToken);
        $this->assertStatus(403);
        $this->request('GET', '/moderation/reports', token: $modToken);
        $this->assertStatus(403);
        $this->request('POST', '/moderation/reports/'.$this->reportId('post').'/decision', ['action' => 'suspend', 'note' => 'Não pode'], $modToken);
        $this->assertStatus(403);

        $queue = $this->request('GET', '/comunas/tarifa/content-reports', token: $modToken);
        $this->assertStatus(200);
        self::assertCount(2, $queue['items']);
        self::assertStringNotContainsString('dona', json_encode($queue, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Contexto privado', json_encode($this->request('GET', '/posts/'.$post->getId()->toRfc4122(), token: $memberToken), \JSON_THROW_ON_ERROR));
        $ownerQueue = $this->request('GET', '/comunas/tarifa/content-reports', token: $ownerToken);
        $this->assertStatus(200);
        self::assertNotEmpty($ownerQueue['items']);

        $postReport = $this->reportId('post');
        $this->request('POST', '/moderation/reports/'.$postReport.'/decision', ['action' => 'remove', 'note' => 'Fora do combinado'], $modToken);
        $this->assertStatus(204);
        $again = $this->request('POST', '/moderation/reports/'.$postReport.'/decision', ['action' => 'dismiss'], $ownerToken);
        $this->assertStatus(409);
        self::assertSame('/errors/report-already-decided', $again['type']);

        $this->request('GET', '/posts/'.$post->getId()->toRfc4122(), token: $memberToken);
        $this->assertStatus(404);
        self::assertSame([], $this->request('GET', '/comunas/tarifa/posts', token: $memberToken)['items']);
        self::assertSame([], $this->request('GET', '/search?q='.rawurlencode('combinado na tarifa única').'&type=posts', token: $memberToken)['posts']);
        self::assertSame([], $this->request('GET', '/comunas/tarifa/pinned-posts', token: $memberToken)['items']);
        $this->client->request('GET', '/p/'.$post->getId()->toRfc4122());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('combinado na tarifa', (string) $this->client->getResponse()->getContent());
        $closed = $this->request('GET', '/comunas/tarifa/content-reports?bucket=closed', token: $modToken);
        self::assertSame('resolved', $closed['items'][0]['status']);
        $audit = $this->em()->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'report.resolved']);
        self::assertNotNull($audit);
        self::assertSame($postReport, $audit->getDetails()['report_id']);

        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'spam'], $ownerToken);
        $this->assertStatus(404);

        $commentReport = $this->reportId('comment');
        $this->request('POST', '/moderation/reports/'.$commentReport.'/decision', ['action' => 'remove', 'note' => 'Ameaça'], $modToken);
        $this->assertStatus(204);
        $comments = $this->request('GET', '/posts/'.$post->getId()->toRfc4122().'/comments', token: $memberToken);
        $this->assertStatus(404);

        $visible = $this->createPost($member, $comuna, 'Publicação que segue no ar');
        $parent = $this->request('POST', '/posts/'.$visible->getId()->toRfc4122().'/comments', ['body' => 'Pai removido'], $memberToken);
        $this->assertStatus(201);
        $child = $this->request('POST', '/posts/'.$visible->getId()->toRfc4122().'/comments', ['body' => 'Filha permanece', 'parentId' => $parent['id']], $ownerToken);
        $this->assertStatus(201);
        $this->request('POST', '/reports', ['targetType' => 'comment', 'targetId' => $parent['id'], 'reasonCode' => 'harassment'], $ownerToken);
        $this->assertStatus(204);
        $this->request('POST', '/moderation/reports/'.$this->reportId('comment', $parent['id']).'/decision', ['action' => 'remove'], $modToken);
        $this->assertStatus(204);
        $thread = $this->request('GET', '/posts/'.$visible->getId()->toRfc4122().'/comments', token: $memberToken);
        $byId = [];
        foreach ($thread['items'] as $item) {
            $byId[$item['id']] = $item;
        }
        self::assertSame('removed', $byId[$parent['id']]['status']);
        self::assertNull($byId[$parent['id']]['body']);
        self::assertSame('published', $byId[$child['id']]['status']);
        self::assertSame('Filha permanece', $byId[$child['id']]['body']);

        $this->request('POST', '/moderation/reports/'.$this->reportId('comment', $parent['id']).'/decision', ['action' => 'restore'], $modToken);
        $this->assertStatus(204);
        self::assertTrue($this->fresh(Comment::class, $parent['id'])->isPublished());

        $membership = $this->em()->getRepository(ComunaMembership::class)->findOneBy(['comuna' => $this->fresh(Comuna::class, $comuna->getId()), 'user' => $this->fresh(User::class, $mod->getId())]);
        self::assertInstanceOf(ComunaMembership::class, $membership);
        $membership->changeRole(ComunaRole::Member);
        $this->em()->flush();
        $this->request('GET', '/comunas/tarifa/content-reports', token: $modToken);
        $this->assertStatus(403);

        $personal = $this->createPost($member, null, 'Sem comuna responsável');
        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $personal->getId()->toRfc4122(), 'reasonCode' => 'spam'], $ownerToken);
        $this->assertStatus(204);
        $platformQueue = $this->request('GET', '/moderation/reports', token: $platformToken);
        $this->assertStatus(200);
        $types = array_column($platformQueue['items'], 'targetType');
        self::assertContains('post', $types);
        $this->request('GET', '/comunas/vizinha/content-reports', token: $modToken);
        $this->assertStatus(403);
        self::assertNotEmpty($other->getSlug());
    }

    public function testPlatformModeratesAccountsComunasAndEscalationWithoutMembership(): void
    {
        $owner = $this->createUser('dona');
        $mod = $this->createUser('modera');
        $target = $this->createUser('alvo');
        $platform = $this->createUser('plat', role: PlatformRole::PlatformModerator);
        $admin = $this->createUser('adma', role: PlatformRole::Admin);
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $this->addMember($comuna, $mod, ComunaRole::Moderator);
        $this->addMember($comuna, $target);
        $post = $this->createPost($target, $comuna, 'Comentário não, a publicação');
        $modToken = $this->login($mod->getEmail())['accessToken'];
        $targetToken = $this->login($target->getEmail())['accessToken'];
        $platformToken = $this->login($platform->getEmail())['accessToken'];
        $adminToken = $this->login($admin->getEmail())['accessToken'];

        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'threat', 'description' => 'Ameaça local'], $ownerToken);
        $this->assertStatus(204);
        $postReport = $this->reportId('post');
        $this->request('GET', '/moderation/reports/'.$postReport, token: $platformToken);
        $this->assertStatus(404);
        $this->request('POST', '/moderation/reports/'.$postReport.'/decision', ['action' => 'remove_and_escalate', 'note' => 'Tira da comuna e pede revisão'], $modToken);
        $this->assertStatus(204);
        self::assertFalse($this->fresh(Post::class, $post->getId())->isPublished());
        $escalated = $this->request('GET', '/moderation/reports', token: $platformToken);
        $this->assertStatus(200);
        self::assertTrue($escalated['items'][0]['escalated']);
        $this->request('POST', '/moderation/reports/'.$postReport.'/decision', ['action' => 'dismiss', 'note' => 'A remoção local bastou'], $platformToken);
        $this->assertStatus(204);

        $this->request('POST', '/reports', ['targetType' => 'profile', 'targetId' => $target->getId()->toRfc4122(), 'reasonCode' => 'illegal'], $ownerToken);
        $this->assertStatus(204);
        $userReport = $this->reportId('profile');
        $local = $this->request('GET', '/comunas/tarifa/content-reports', token: $modToken);
        $this->assertStatus(200);
        self::assertNotContains('profile', array_column($local['items'] ?? [], 'targetType'));
        $this->request('POST', '/moderation/reports/'.$userReport.'/decision', ['action' => 'suspend', 'note' => 'Conduta grave'], $modToken);
        $this->assertStatus(404);
        $this->request('POST', '/moderation/reports/'.$userReport.'/decision', ['action' => 'suspend', 'note' => 'Conduta grave'], $platformToken);
        $this->assertStatus(204);
        $this->request('GET', '/me', token: $targetToken);
        $this->assertStatus(401);
        self::assertSame(AccountStatus::Suspended, $this->fresh(User::class, $target->getId())->getStatus());
        self::assertNotNull($this->em()->getRepository(AdminAuditLog::class)->findOneBy(['action' => 'account.suspended']));
        $this->request('POST', '/moderation/reports/'.$userReport.'/decision', ['action' => 'reactivate', 'note' => 'Revisão concluída'], $adminToken);
        $this->assertStatus(204);
        self::assertTrue($this->fresh(User::class, $target->getId())->isActive());

        $targetToken = $this->login($target->getEmail())['accessToken'];
        $this->request('POST', '/reports', ['targetType' => 'comuna', 'targetId' => 'tarifa', 'reasonCode' => 'fraud'], $targetToken);
        $this->assertStatus(204);
        $comunaReport = $this->reportId('comuna');
        $this->request('GET', '/moderation/reports/'.$comunaReport, token: $modToken);
        $this->assertStatus(404);
        $this->request('GET', '/moderation/reports/'.$comunaReport, token: $ownerToken);
        $this->assertStatus(404);
        $this->request('POST', '/moderation/reports/'.$comunaReport.'/decision', ['action' => 'suspend_comuna', 'note' => 'A comuna inteira'], $platformToken);
        $this->assertStatus(204);
        $this->request('GET', '/comunas/tarifa', token: $targetToken);
        $this->assertStatus(404);
        $this->client->request('GET', '/c/tarifa');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Descrição de tarifa', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->request('GET', '/search?q=tarifa&type=comunas', token: $adminToken)['comunas']);
        $blockedPost = $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => 'Não entra'], $ownerToken);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-suspended', $blockedPost['type']);
        $this->request('POST', '/moderation/reports/'.$comunaReport.'/decision', ['action' => 'reactivate_comuna', 'note' => 'Pode voltar'], $adminToken);
        $this->assertStatus(204);
        $this->request('GET', '/comunas/tarifa', token: $targetToken);
        $this->assertStatus(200);

        $suspended = $this->fresh(User::class, $target->getId());
        $suspended->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $this->request('POST', '/reports', ['targetType' => 'profile', 'targetId' => $owner->getId()->toRfc4122(), 'reasonCode' => 'spam'], $targetToken);
        $this->assertStatus(403);
    }

    public function testFounderContentStaysWithTheFounderAndArchivedComunaKeepsTheQueue(): void
    {
        $owner = $this->createUser('dona');
        $mod = $this->createUser('modera');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $this->addMember($comuna, $mod, ComunaRole::Moderator);
        $post = $this->createPost($owner, $comuna, 'Texto da proprietária');
        $modToken = $this->login($mod->getEmail())['accessToken'];
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'other'], $modToken);
        $this->assertStatus(204);
        $this->request('POST', '/moderation/reports/'.$this->reportId('post').'/decision', ['action' => 'remove', 'note' => 'Tentativa'], $modToken);
        $this->assertStatus(403);
        self::assertTrue($this->fresh(Post::class, $post->getId())->isPublished());
        $this->request('POST', '/moderation/reports/'.$this->reportId('post').'/decision', ['action' => 'escalate'], $modToken);
        $this->assertStatus(204);
        $this->request('POST', '/comunas/tarifa/archive', token: $ownerToken);
        $this->assertStatus(200);
        $queue = $this->request('GET', '/comunas/tarifa/content-reports?bucket=open', token: $modToken);
        $this->assertStatus(200);
        self::assertTrue($queue['items'][0]['escalated']);
    }

    public function testOpenQueuePaginatesOldestFirstWithoutTheReporter(): void
    {
        $owner = $this->createUser('dona');
        $member = $this->createUser('membro');
        $comuna = $this->createComuna('tarifa', owner: $owner);
        $this->addMember($comuna, $member);
        $token = $this->login($member->getEmail())['accessToken'];
        $ownerToken = $this->login($owner->getEmail())['accessToken'];
        $ids = [];
        foreach (['Primeira peça', 'Segunda peça', 'Terceira peça'] as $body) {
            $post = $this->createPost($owner, $comuna, $body);
            $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'spam'], $token);
            $this->assertStatus(204);
            $ids[] = $this->reportId('post', $post->getId()->toRfc4122());
        }
        $page = $this->request('GET', '/comunas/tarifa/content-reports?limit=2', token: $ownerToken);
        $this->assertStatus(200);
        self::assertCount(2, $page['items']);
        self::assertNotNull($page['nextCursor']);
        $next = $this->request('GET', '/comunas/tarifa/content-reports?limit=2&cursor='.$page['nextCursor'], token: $ownerToken);
        self::assertCount(1, $next['items']);
        $seen = array_merge(array_column($page['items'], 'id'), array_column($next['items'], 'id'));
        self::assertEqualsCanonicalizing($ids, $seen);
        $ordered = $seen;
        sort($ordered);
        self::assertSame($ordered, $seen);
        self::assertStringNotContainsString($member->getHandle(), json_encode($page, \JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('reporter', $page['items'][0]);
    }

    private function reportId(string $type, ?string $targetId = null): string
    {
        $criteria = ['targetType' => $type];
        if (null !== $targetId) {
            $criteria['targetId'] = $targetId;
        }
        $report = $this->em()->getRepository(ContentReport::class)->findOneBy($criteria, ['createdAt' => 'DESC']);
        self::assertInstanceOf(ContentReport::class, $report);

        return $report->id->toRfc4122();
    }
}
