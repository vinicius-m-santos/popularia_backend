<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Square;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Tests\ApiTestCase;

/** HTTP real: autorização, privacidade e reconciliação do contrato usado pelos dois aparelhos. */
final class SquareTest extends ApiTestCase
{
    private string $ownerToken;
    private string $memberToken;
    private string $outsiderToken;
    private string $moderatorToken;
    private string $squareId;
    private string $privateId;
    private int $clientCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('dona');
        $member = $this->createUser('marina');
        $outsider = $this->createUser('fora');
        $moderator = $this->createUser('modera');
        $public = $this->createComuna('praca-publica', owner: $owner);
        $private = $this->createComuna('praca-privada', ComunaVisibility::Private, $owner);
        $this->addMember($public, $member);
        $this->addMember($private, $member);
        $this->addMember($public, $moderator, ComunaRole::Moderator);
        $this->addMember($private, $moderator, ComunaRole::Moderator);
        $this->ownerToken = $this->login($owner->getEmail())['accessToken'];
        $this->memberToken = $this->login($member->getEmail())['accessToken'];
        $this->outsiderToken = $this->login($outsider->getEmail())['accessToken'];
        $this->moderatorToken = $this->login($moderator->getEmail())['accessToken'];
        $this->squareId = $this->request('GET', '/comunas/praca-publica/square', token: $this->ownerToken)['id'];
        $this->assertStatus(200);
        $this->privateId = $this->request('GET', '/comunas/praca-privada/square', token: $this->moderatorToken)['id'];
        $this->assertStatus(200);
    }

    /** @param array<string, mixed> $extra
     * @return array<mixed>
     */
    private function send(?string $square = null, ?string $token = null, array $extra = []): array
    {
        return $this->request('POST', '/squares/'.($square ?? $this->squareId).'/messages', $extra + ['body' => 'Uma conversa coletiva', 'clientId' => 'test_client_'.++$this->clientCounter], $token ?? $this->memberToken);
    }

    public function testSquareExistsWithoutManualCreationAndLifecycleFollowsComuna(): void
    {
        $again = $this->request('GET', '/comunas/praca-publica/square', token: $this->memberToken);
        $this->assertStatus(200);
        self::assertSame($this->squareId, $again['id']);
        self::assertSame('active', $again['status']);
        self::assertSame(2, $this->em()->getRepository(Square::class)->count([]));
        $this->request('PUT', '/comunas/praca-publica/square', token: $this->ownerToken);
        $this->assertStatus(405);
        foreach ([$this->ownerToken, $this->moderatorToken] as $token) {
            foreach (['active', 'closed'] as $status) {
                $this->request('PATCH', '/squares/'.$this->squareId, ['status' => $status], $token);
                $this->assertStatus(405);
            }
        }
        $message = $this->send();
        $this->assertStatus(201);
        $this->request('POST', '/comunas/praca-publica/archive', token: $this->moderatorToken);
        $this->assertStatus(403);
        $archived = $this->request('POST', '/comunas/praca-publica/archive', token: $this->ownerToken);
        $this->assertStatus(200);
        $closed = $this->request('GET', '/squares/'.$this->squareId, token: $this->memberToken);
        self::assertSame('closed', $closed['status']);
        self::assertNotNull($closed['closedAt']);
        self::assertSame($archived['archivedAt'], $closed['closedAt']);
        $this->send();
        $this->assertStatus(409);
        $this->request('PUT', '/squares/'.$this->squareId.'/presence', token: $this->memberToken);
        $this->assertStatus(409);
        $history = $this->request('GET', '/squares/'.$this->squareId.'/messages', token: $this->memberToken);
        self::assertSame($message['id'], $history['items'][0]['id']);
        $listed = $this->request('GET', '/squares?section=mine', token: $this->memberToken);
        self::assertContains($this->squareId, array_column($listed['items'], 'id'));
        $discover = $this->request('GET', '/squares?section=discover', token: $this->outsiderToken);
        self::assertNotContains($this->squareId, array_column($discover['items'], 'id'));
    }

    public function testPublicReadAndExistingJoinFlowUnlocksWriting(): void
    {
        $this->send();
        $history = $this->request('GET', '/squares/'.$this->squareId.'/messages', token: $this->outsiderToken);
        $this->assertStatus(200);
        self::assertSame('Uma conversa coletiva', $history['items'][0]['body']);
        self::assertFalse($history['square']['viewer']['canWrite']);
        $this->send(token: $this->outsiderToken);
        $this->assertStatus(403);
        $this->request('PUT', '/comunas/praca-publica/membership', token: $this->outsiderToken);
        $this->assertStatus(200);
        $this->send(token: $this->outsiderToken);
        $this->assertStatus(201);
    }

    public function testPrivateSquareDoesNotLeakThroughAnyEndpointAndJoinRequestStillWorks(): void
    {
        $this->send($this->privateId, extra: ['body' => 'SEGREDO PRIVADO']);
        $this->request('PUT', '/squares/'.$this->privateId.'/presence', token: $this->memberToken);
        $locked = $this->request('GET', '/squares/'.$this->privateId, token: $this->outsiderToken);
        $this->assertStatus(200);
        self::assertFalse($locked['viewer']['canRead']);
        foreach (['lastMessage', 'presence', 'unread', 'lastReadId', 'version', 'status', 'closedAt'] as $field) {
            self::assertNull($locked[$field], $field);
        }
        foreach (['/messages', '/messages?after=0', '/presence', '/sticker-packs'] as $suffix) {
            $response = $this->request('GET', '/squares/'.$this->privateId.$suffix, token: $this->outsiderToken);
            $this->assertStatus(403);
            self::assertStringNotContainsString('SEGREDO', json_encode($response, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('marina', json_encode($response, JSON_THROW_ON_ERROR));
        }
        $discovery = $this->request('GET', '/squares?section=discover', token: $this->outsiderToken);
        self::assertNotContains($this->privateId, array_column($discovery['items'], 'id'));
        $mine = $this->request('GET', '/squares?section=mine', token: $this->memberToken);
        self::assertContains($this->privateId, array_column($mine['items'], 'id'));
        $request = $this->request('PUT', '/comunas/praca-privada/join-request', token: $this->outsiderToken);
        $this->assertStatus(200);
        $locked = $this->request('GET', '/squares/'.$this->privateId, token: $this->outsiderToken);
        self::assertSame('pending', $locked['viewer']['joinRequest']);
        $this->request('POST', '/comunas/praca-privada/join-requests/'.$request['id'].'/approve', token: $this->moderatorToken);
        $this->assertStatus(200);
        $unlocked = $this->request('GET', '/squares/'.$this->privateId.'/messages', token: $this->outsiderToken);
        $this->assertStatus(200);
        self::assertSame('SEGREDO PRIVADO', $unlocked['items'][0]['body']);
        // Revogação é imediata, inclusive com cursor de uma sessão anterior.
        $this->request('DELETE', '/comunas/praca-privada/members/fora', token: $this->ownerToken);
        $this->assertStatus(204);
        $this->request('GET', '/squares/'.$this->privateId.'/messages?after='.$unlocked['changeCursor'], token: $this->outsiderToken);
        $this->assertStatus(403);
    }

    public function testReplyMustBelongToSameSquareAndDeletedContentNeverLeaks(): void
    {
        $parent = $this->send();
        $reply = $this->send(token: $this->ownerToken, extra: ['replyToId' => $parent['id'], 'body' => 'Resposta direta']);
        $this->assertStatus(201);
        self::assertSame($parent['id'], $reply['replyTo']['id']);
        $this->send($this->privateId, extra: ['replyToId' => $parent['id']]);
        $this->assertStatus(404);
        $notifications = $this->request('GET', '/me/notifications', token: $this->memberToken);
        self::assertSame('square_reply', $notifications['items'][0]['type']);
        self::assertSame(['kind' => 'square', 'id' => $this->squareId], $notifications['items'][0]['target']);
        $this->request('DELETE', '/squares/'.$this->squareId.'/messages/'.$parent['id'], token: $this->ownerToken);
        $this->assertStatus(403);
        $this->request('DELETE', '/squares/'.$this->squareId.'/messages/'.$parent['id'], token: $this->memberToken);
        $this->assertStatus(204);
        $page = $this->request('GET', '/squares/'.$this->squareId.'/messages?after=0', token: $this->ownerToken);
        foreach ($page['items'] as $m) {
            if ($m['id'] === $parent['id']) {
                self::assertSame('deleted', $m['status']);
                self::assertNull($m['body']);
                self::assertNull($m['author']);
                self::assertSame([], $m['reactions']);
            } else {
                self::assertNull($m['replyTo']['author']);
                self::assertNull($m['replyTo']['body']);
            }
        }
        $notifications = $this->request('GET', '/me/notifications', token: $this->memberToken);
        self::assertSame([], $notifications['items']);
    }

    public function testReactionIsIdempotentAndModerationUsesExistingPermissionsAndAudit(): void
    {
        $m = $this->send();
        $reactionPath = '/squares/'.$this->squareId.'/messages/'.$m['id'].'/reactions/'.rawurlencode('✊');
        for ($i = 0; $i < 2; ++$i) {
            $r = $this->request('PUT', $reactionPath, token: $this->ownerToken);
            $this->assertStatus(200);
            self::assertSame([['emoji' => '✊', 'count' => 1, 'mine' => true]], $r['reactions']);
        }
        $r = $this->request('DELETE', $reactionPath, token: $this->ownerToken);
        $this->assertStatus(200);
        self::assertSame([], $r['reactions']);
        $this->request('POST', '/squares/'.$this->squareId.'/messages/'.$m['id'].'/reports', ['reason' => 'Fere as regras da comuna'], $this->outsiderToken);
        $this->assertStatus(204);
        $this->request('GET', '/comunas/praca-publica/reports', token: $this->memberToken);
        $this->assertStatus(403);
        $reports = $this->request('GET', '/comunas/praca-publica/reports', token: $this->moderatorToken);
        $this->assertStatus(200);
        self::assertCount(1, $reports['items']);
        self::assertArrayNotHasKey('reporter', $reports['items'][0]);
        $this->request('PATCH', '/comunas/praca-publica/reports/'.$reports['items'][0]['id'], ['status' => 'removed'], $this->moderatorToken);
        $this->assertStatus(204);
        $page = $this->request('GET', '/squares/'.$this->squareId.'/messages', token: $this->outsiderToken);
        self::assertSame('removed', $page['items'][0]['status']);
        self::assertNull($page['items'][0]['body']);
        self::assertGreaterThan(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'comuna.square_message_removed'"));
        // Moderadores não removem conteúdo do proprietário, como no feed.
        $ownerMessage = $this->send(token: $this->ownerToken);
        $this->request('POST', '/squares/'.$this->squareId.'/messages/'.$ownerMessage['id'].'/removal', ['reason' => 'Tentativa sem permissão'], $this->moderatorToken);
        $this->assertStatus(403);
    }

    public function testBlocksHideMessagesRepliesReactionsPresencePreviewsAndNotifications(): void
    {
        $parent = $this->send(extra: ['body' => 'CONTEUDO BLOQUEADO']);
        $reply = $this->send(token: $this->ownerToken, extra: ['replyToId' => $parent['id']]);
        $this->request('PUT', '/squares/'.$this->squareId.'/messages/'.$reply['id'].'/reactions/'.rawurlencode('❤️'), token: $this->memberToken);
        $this->request('PUT', '/squares/'.$this->squareId.'/presence', token: $this->memberToken);
        $this->request('PUT', '/me/blocks/marina', token: $this->outsiderToken);
        $this->assertStatus(200);
        $page = $this->request('GET', '/squares/'.$this->squareId.'/messages', token: $this->outsiderToken);
        $this->assertStatus(200);
        self::assertStringNotContainsString('CONTEUDO BLOQUEADO', json_encode($page, JSON_THROW_ON_ERROR));
        self::assertNull($page['items'][0]['replyTo']['author']);
        self::assertSame([], $page['items'][0]['reactions']);
        self::assertSame('hidden', $page['items'][1]['status']);
        $presence = $this->request('GET', '/squares/'.$this->squareId.'/presence', token: $this->outsiderToken);
        self::assertSame(0, $presence['count']);
        self::assertSame([], $presence['people']);
        $this->request('PUT', '/squares/'.$this->squareId.'/messages/'.$parent['id'].'/reactions/'.rawurlencode('✊'), token: $this->outsiderToken);
        $this->assertStatus(404);
        $this->send(token: $this->outsiderToken, extra: ['replyToId' => $parent['id']]);
        $this->assertStatus(403); // Ainda não membro.
        // Bloqueio bilateral também elimina resposta da central já existente.
        $this->request('PUT', '/me/blocks/dona', token: $this->memberToken);
        $notifications = $this->request('GET', '/me/notifications', token: $this->memberToken);
        self::assertSame([], $notifications['items']);
        // Revalida snapshots que já estavam carregados antes do bloqueio.
        $page = $this->request('GET', '/squares/'.$this->squareId.'/messages?after=999&refreshIds='.$parent['id'], token: $this->outsiderToken);
        self::assertSame('hidden', $page['refreshed'][0]['status']);
    }

    public function testPaginationIncrementalChangesAndUnreadAreStable(): void
    {
        $a = $this->send();
        $b = $this->send();
        $c = $this->send();
        $first = $this->request('GET', '/squares/'.$this->squareId.'/messages?limit=2', token: $this->ownerToken);
        self::assertSame([$c['id'], $b['id']], array_column($first['items'], 'id'));
        self::assertSame($b['id'], $first['nextCursor']);
        $second = $this->request('GET', '/squares/'.$this->squareId.'/messages?limit=2&cursor='.$first['nextCursor'], token: $this->ownerToken);
        self::assertSame([$a['id']], array_column($second['items'], 'id'));
        self::assertNull($second['nextCursor']);
        $detail = $this->request('GET', '/squares/'.$this->squareId, token: $this->ownerToken);
        self::assertSame(3, $detail['unread']);
        // Buscar não é ler. Leitura monotônica, até um ID desta praça.
        $this->request('PUT', '/squares/'.$this->squareId.'/read', ['messageId' => $b['id']], $this->ownerToken);
        $this->assertStatus(204);
        $this->request('PUT', '/squares/'.$this->squareId.'/read', ['messageId' => $a['id']], $this->ownerToken);
        $detail = $this->request('GET', '/squares/'.$this->squareId, token: $this->ownerToken);
        self::assertSame(1, $detail['unread']);
        self::assertSame($b['id'], $detail['lastReadId']);
        $d = $this->send(extra: ['clientId' => 'idempotent_123']);
        $duplicate = $this->send(extra: ['clientId' => 'idempotent_123']);
        self::assertSame($d['id'], $duplicate['id']);
        $this->request('DELETE', '/squares/'.$this->squareId.'/messages/'.$a['id'], token: $this->memberToken);
        $updates = $this->request('GET', '/squares/'.$this->squareId.'/messages?after='.$first['changeCursor'].'&limit=1', token: $this->ownerToken);
        self::assertTrue($updates['hasMoreChanges']);
        $more = $this->request('GET', '/squares/'.$this->squareId.'/messages?after='.$updates['changeCursor'], token: $this->ownerToken);
        self::assertFalse($more['hasMoreChanges']);
        self::assertSame('deleted', $more['items'][0]['status']);
        $foreign = $this->send($this->privateId);
        $this->request('PUT', '/squares/'.$this->squareId.'/read', ['messageId' => $foreign['id']], $this->ownerToken);
        $this->assertStatus(404);
        $this->request('GET', '/squares/'.$this->squareId.'/messages?after=nope', token: $this->ownerToken);
        $this->assertStatus(400);
    }

    public function testPresenceExpiresAndIsNotMembershipCount(): void
    {
        $empty = $this->request('GET', '/squares/'.$this->squareId.'/presence', token: $this->ownerToken);
        self::assertSame(0, $empty['count']);
        $this->request('PUT', '/squares/'.$this->squareId.'/presence', token: $this->memberToken);
        $this->assertStatus(200);
        $presence = $this->request('PUT', '/squares/'.$this->squareId.'/presence', token: $this->outsiderToken);
        self::assertSame(2, $presence['count']);
        $this->em()->getConnection()->executeStatement("UPDATE square_visit SET seen_at = NOW() - INTERVAL '2 minutes'");
        $expired = $this->request('GET', '/squares/'.$this->squareId.'/presence', token: $this->ownerToken);
        self::assertSame(0, $expired['count']);
    }

    public function testStickersPermissionsScopeAndValidation(): void
    {
        $packs = $this->request('GET', '/squares/'.$this->squareId.'/sticker-packs', token: $this->memberToken);
        $this->assertStatus(200);
        $global = array_values(array_filter($packs['items'], static fn (array $p) => 'popularia' === $p['scope']))[0];
        $this->send(extra: ['body' => null, 'stickerId' => $global['stickers'][0]['id']]);
        $this->assertStatus(201);
        $this->request('POST', '/squares/'.$this->squareId.'/stickers', ['label' => 'BORA', 'symbol' => '✊', 'tone' => 'red'], $this->memberToken);
        $this->assertStatus(403);
        $packs = $this->request('POST', '/squares/'.$this->squareId.'/stickers', ['label' => 'BORA', 'symbol' => '✊', 'tone' => 'red'], $this->moderatorToken);
        $this->assertStatus(201);
        $pack = array_values(array_filter($packs['items'], static fn (array $p) => 'comuna' === $p['scope']))[0];
        $sticker = $pack['stickers'][0];
        $this->send(extra: ['body' => null, 'stickerId' => $sticker['id']]);
        $this->assertStatus(201);
        $this->send($this->privateId, extra: ['body' => null, 'stickerId' => $sticker['id']]);
        $this->assertStatus(422);
        $this->request('DELETE', '/squares/'.$this->squareId.'/stickers/'.$sticker['id'], token: $this->ownerToken);
        $this->assertStatus(204);
        $this->send(extra: ['body' => null, 'stickerId' => $sticker['id']]);
        $this->assertStatus(422);
        foreach ([['body' => '   '], ['body' => null], ['body' => str_repeat('x', 2001)], ['body' => 'texto', 'stickerId' => $global['stickers'][0]['id']]] as $invalid) {
            $this->send(extra: $invalid);
            $this->assertStatus(422);
        }
        $this->send(extra: ['authorId' => 'untrusted']);
        $this->assertStatus(400);
    }

    public function testCrossSquareReactionClosedInteractionAndRateLimitAreEnforced(): void
    {
        $foreign = $this->send($this->privateId);
        $this->request('PUT', '/squares/'.$this->squareId.'/messages/'.$foreign['id'].'/reactions/'.rawurlencode('✊'), token: $this->ownerToken);
        $this->assertStatus(404);
        $message = $this->send();
        $this->request('POST', '/comunas/praca-publica/archive', token: $this->ownerToken);
        $this->assertStatus(200);
        $this->request('PUT', '/squares/'.$this->squareId.'/messages/'.$message['id'].'/reactions/'.rawurlencode('✊'), token: $this->memberToken);
        $this->assertStatus(409);
        $this->request('POST', '/squares/'.$this->squareId.'/stickers', ['label' => 'Fechada', 'symbol' => '✊'], $this->ownerToken);
        $this->assertStatus(409);
        for ($i = 0; $i < 30; ++$i) {
            $this->send($this->privateId, $this->ownerToken);
            $this->assertStatus(201);
        }
        $this->send($this->privateId, $this->ownerToken);
        $this->assertStatus(429);
    }
}
