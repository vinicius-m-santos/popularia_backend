<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ComunaVisibility;
use App\Tests\ApiTestCase;
use App\Tests\EventRequests;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

final class EventAttendanceTest extends ApiTestCase
{
    use EventRequests;

    public function testResponseReplacesThePreviousOneAndCountsWithoutLoadingPeople(): void
    {
        [$token, $event] = $this->openEvent();
        $going = $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'going'], $token);
        $this->assertStatus(200);
        self::assertSame('going', $going['viewerAttendance']);
        self::assertSame(1, $going['goingCount']);
        self::assertSame(0, $going['maybeCount']);

        $same = $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'going'], $token);
        $this->assertStatus(200);
        self::assertSame(1, $same['goingCount']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM event_attendance WHERE event_id = ?', [$event]));

        $maybe = $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'maybe'], $token);
        self::assertSame('maybe', $maybe['viewerAttendance']);
        self::assertSame(0, $maybe['goingCount']);
        self::assertSame(1, $maybe['maybeCount']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM event_attendance WHERE event_id = ?', [$event]));

        $no = $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'not_going'], $token);
        self::assertSame(0, $no['goingCount']);
        self::assertSame(0, $no['maybeCount']);
        self::assertSame('not_going', $no['viewerAttendance']);
    }

    public function testListsPaginateGoingAndMaybeAndHideNotGoingBlocksAndInactiveAccounts(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('londrina', owner: $owner);
        $people = [];
        foreach (['ana', 'bia', 'caio', 'duda'] as $handle) {
            $user = $this->createUser($handle);
            $this->addMember($comuna, $user);
            $people[$handle] = $user;
        }
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $token)['id'];
        $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'going'], $this->login('ana@exemplo.org')['accessToken']);
        $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'going'], $this->login('bia@exemplo.org')['accessToken']);
        $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'maybe'], $this->login('caio@exemplo.org')['accessToken']);
        $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'not_going'], $this->login('duda@exemplo.org')['accessToken']);

        $page = $this->request('GET', '/events/'.$event.'/attendees?status=going&limit=1', token: $token);
        $this->assertStatus(200);
        self::assertCount(1, $page['items']);
        self::assertNotNull($page['nextCursor']);
        $rest = $this->request('GET', '/events/'.$event.'/attendees?status=going&limit=1&cursor='.$page['nextCursor'], token: $token);
        self::assertCount(1, $rest['items']);
        $handles = [$page['items'][0]['handle'], $rest['items'][0]['handle']];
        sort($handles);
        self::assertSame(['ana', 'bia'], $handles);

        $maybe = $this->request('GET', '/events/'.$event.'/attendees?status=maybe', token: $token);
        self::assertSame(['caio'], array_column($maybe['items'], 'handle'));
        $this->request('GET', '/events/'.$event.'/attendees?status=not_going', token: $token);
        $this->assertStatus(400);

        $this->request('PUT', '/me/blocks/bia', token: $token);
        $this->assertStatus(204);
        $visible = $this->request('GET', '/events/'.$event.'/attendees?status=going', token: $token);
        self::assertSame(['ana'], array_column($visible['items'], 'handle'));
        $detail = $this->request('GET', '/events/'.$event, token: $token);
        self::assertSame(1, $detail['goingCount']);

        $bia = $this->fresh(User::class, $people['bia']->getId());
        $bia->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $ownerView = $this->request('GET', '/events/'.$event, token: $this->login('ana@exemplo.org')['accessToken']);
        self::assertSame(1, $ownerView['goingCount']);
        $listed = $this->request('GET', '/events/'.$event.'/attendees?status=going', token: $this->login('ana@exemplo.org')['accessToken']);
        self::assertNotContains('bia', array_column($listed['items'], 'handle'));
    }

    public function testClosedCancelledArchivedPrivateAndSuspendedCannotRespond(): void
    {
        $owner = $this->createUser('dona');
        $member = $this->createUser('membro');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $member);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $memberToken = $this->login('membro@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/fechada/events', $this->eventBody(), $ownerToken);

        $stranger = $this->login($this->createUser('fora')->getEmail())['accessToken'];
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'going'], $stranger);
        $this->assertStatus(404);

        $this->request('POST', '/events/'.$event['id'].'/cancel', token: $ownerToken);
        $denied = $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'going'], $memberToken);
        $this->assertStatus(409);
        self::assertSame('/errors/event-cancelled', $denied['type']);

        $live = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+4 days', override: ['title' => 'Outro']), $ownerToken);
        $this->mockTime('+6 days');
        $memberToken = $this->login('membro@exemplo.org')['accessToken'];
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $closed = $this->request('PUT', '/events/'.$live['id'].'/attendance', ['status' => 'going'], $memberToken);
        $this->assertStatus(409);
        self::assertSame('/errors/event-closed', $closed['type']);

        $this->mockTime(new \DateTimeImmutable());
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $memberToken = $this->login('membro@exemplo.org')['accessToken'];
        $future = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+8 days', override: ['title' => 'Ainda']), $ownerToken);
        $this->request('POST', '/comunas/fechada/archive', token: $ownerToken);
        $archived = $this->request('PUT', '/events/'.$future['id'].'/attendance', ['status' => 'maybe'], $memberToken);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-archived', $archived['type']);
        $readable = $this->request('GET', '/events/'.$future['id'], token: $memberToken);
        $this->assertStatus(200);
        self::assertFalse($readable['canRespond']);

        $member = $this->fresh(User::class, $member->getId());
        $host = $this->createUser('host');
        $public = $this->createComuna('aberta', owner: $host);
        $this->addMember($public, $member);
        $hostToken = $this->login('host@exemplo.org')['accessToken'];
        $open = $this->request('POST', '/comunas/aberta/events', $this->eventBody(), $hostToken);
        $guest = $this->login($member->getEmail())['accessToken'];
        $this->request('PUT', '/events/'.$open['id'].'/attendance', ['status' => 'going'], $guest);
        $this->assertStatus(200);
        $fresh = $this->fresh(User::class, $member->getId());
        $fresh->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $this->request('PUT', '/events/'.$open['id'].'/attendance', ['status' => 'maybe'], $guest);
        $this->assertStatus(403);
    }

    public function testUniqueConstraintRejectsASecondRow(): void
    {
        $opened = $this->openEvent(true);
        $token = $opened[0];
        $event = $opened[1];
        $user = $opened[2] ?? null;
        self::assertInstanceOf(User::class, $user);
        $this->request('PUT', '/events/'.$event.'/attendance', ['status' => 'going'], $token);
        $this->assertStatus(200);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO event_attendance (id, event_id, user_id, status, updated_at) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $event, $user->getId()->toRfc4122(), 'maybe', (new \DateTimeImmutable())->format(\DATE_ATOM)],
        );
    }

    /** @return array{0: string, 1: string, 2?: User} */
    private function openEvent(bool $withUser = false): array
    {
        $owner = $this->createUser('dona');
        $this->createComuna('londrina', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $token);
        $this->assertStatus(201);

        return $withUser ? [$token, $event['id'], $owner] : [$token, $event['id']];
    }
}
