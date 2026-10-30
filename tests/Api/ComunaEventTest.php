<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Enum\AccountStatus;
use App\Enum\ComunaRole;
use App\Enum\ComunaVisibility;
use App\Tests\ApiTestCase;
use App\Tests\EventRequests;

final class ComunaEventTest extends ApiTestCase
{
    use EventRequests;

    public function testModeratorAndOwnerCanCreate(): void
    {
        $owner = $this->createUser('dona');
        $mod = $this->createUser('modera');
        $comuna = $this->createComuna('londrina', owner: $owner);
        $this->addMember($comuna, $mod, ComunaRole::Moderator);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $modToken = $this->login('modera@exemplo.org')['accessToken'];

        $created = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $ownerToken);
        $this->assertStatus(201);
        self::assertSame('Assembleia mensal', $created['title']);
        self::assertSame('in_person', $created['format']);
        self::assertSame('America/Manaus', $created['timeZone']);
        self::assertSame('scheduled', $created['status']);
        self::assertSame(0, $created['goingCount']);
        self::assertNull($created['viewerAttendance']);
        self::assertTrue($created['canManage']);
        self::assertStringContainsString('-04:00', $created['startsAt']);

        $online = $this->request('POST', '/comunas/londrina/events', $this->eventBody('+4 days', override: [
            'title' => 'Reunião online',
            'format' => 'online',
            'placeName' => null,
            'address' => null,
            'onlineUrl' => 'https://meet.example.org/sala',
        ]), $modToken);
        $this->assertStatus(201);
        self::assertSame('https://meet.example.org/sala', $online['onlineUrl']);
        self::assertNull($online['placeName']);

        $hybrid = $this->request('POST', '/comunas/londrina/events', $this->eventBody('+5 days', override: [
            'title' => 'Ato híbrido',
            'format' => 'hybrid',
            'onlineUrl' => 'https://jitsi.example.org/ato',
        ]), $modToken);
        $this->assertStatus(201);
        self::assertSame('Sindicato dos Bancários', $hybrid['placeName']);
        self::assertSame('https://jitsi.example.org/ato', $hybrid['onlineUrl']);
    }

    public function testMemberStrangerArchiveAndSuspensionCannotCreate(): void
    {
        $owner = $this->createUser('dona');
        $member = $this->createUser('membro');
        $comuna = $this->createComuna('londrina', owner: $owner);
        $this->addMember($comuna, $member);
        $memberToken = $this->login('membro@exemplo.org')['accessToken'];
        $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $memberToken);
        $this->assertStatus(403);
        self::assertSame('/errors/not-comuna-moderator', $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $memberToken)['type']);

        $stranger = $this->login($this->createUser('fora')->getEmail())['accessToken'];
        $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $stranger);
        $this->assertStatus(403);

        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $this->request('POST', '/comunas/londrina/archive', token: $ownerToken);
        $this->assertStatus(200);
        $denied = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $ownerToken);
        $this->assertStatus(409);
        self::assertSame('/errors/comuna-archived', $denied['type']);

        $active = $this->createUser('ativa');
        $other = $this->createComuna('outra', owner: $active);
        $token = $this->login('ativa@exemplo.org')['accessToken'];
        $fresh = $this->fresh(User::class, $active->getId());
        $fresh->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $this->request('POST', '/comunas/outra/events', $this->eventBody(), $token);
        $this->assertStatus(403);
        self::assertSame('/errors/account-inactive', $this->request('POST', '/comunas/outra/events', $this->eventBody(), $token)['type']);
        self::assertSame('outra', $other->getSlug());
    }

    public function testInvalidTitleTimeLinkAndTypeSwitch(): void
    {
        $owner = $this->createUser('dona');
        $this->createComuna('londrina', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];

        $this->request('POST', '/comunas/londrina/events', $this->eventBody(override: ['title' => '   ']), $token);
        $this->assertStatus(422);
        $this->request('POST', '/comunas/londrina/events', $this->eventBody(override: ['title' => "Linha\nquebrada"]), $token);
        $this->assertStatus(422);
        $this->request('POST', '/comunas/londrina/events', $this->eventBody(override: ['startsAt' => 'amanha']), $token);
        $this->assertStatus(400);
        $past = $this->eventBody('-2 days');
        $this->request('POST', '/comunas/londrina/events', $past, $token);
        $this->assertStatus(400);
        self::assertSame('/errors/event-time', $this->request('POST', '/comunas/londrina/events', $past, $token)['type']);

        $body = $this->eventBody();
        $body['endsAt'] = (new \DateTimeImmutable($body['startsAt']))->modify('-1 hour')->format(\DATE_ATOM);
        $this->request('POST', '/comunas/londrina/events', $body, $token);
        $this->assertStatus(400);

        $this->request('POST', '/comunas/londrina/events', $this->eventBody(override: [
            'startsAt' => (new \DateTimeImmutable('+3 days'))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i:sP'),
            'timeZone' => 'America/Manaus',
        ]), $token);
        $this->assertStatus(400);
        self::assertSame('/errors/event-timezone', $this->request('POST', '/comunas/londrina/events', $this->eventBody(override: [
            'startsAt' => (new \DateTimeImmutable('+3 days'))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i:sP'),
            'timeZone' => 'America/Manaus',
        ]), $token)['type']);

        $this->request('POST', '/comunas/londrina/events', $this->eventBody(override: ['format' => 'online', 'onlineUrl' => 'http://inseguro.example']), $token);
        $this->assertStatus(400);
        $this->request('POST', '/comunas/londrina/events', $this->eventBody(override: ['format' => 'online', 'onlineUrl' => 'https://user:senha@meet.example.org/sala', 'placeName' => null, 'address' => null]), $token);
        $this->assertStatus(400);

        $created = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $token);
        $this->assertStatus(201);
        $switched = $this->eventEdit($created, ['format' => 'online', 'placeName' => null, 'address' => null, 'onlineUrl' => null]);
        $this->request('PATCH', '/events/'.$created['id'], $switched, $token);
        $this->assertStatus(400);

        $switched['onlineUrl'] = 'https://meet.example.org/sala';
        $updated = $this->request('PATCH', '/events/'.$created['id'], $switched, $token);
        $this->assertStatus(200);
        self::assertSame('online', $updated['format']);
        self::assertSame('https://meet.example.org/sala', $updated['onlineUrl']);
    }

    public function testImportantChangesNotifyAndSmallEditsDoNot(): void
    {
        $owner = $this->createUser('dona');
        $going = $this->createUser('vai');
        $comuna = $this->createComuna('londrina', owner: $owner);
        $this->addMember($comuna, $going);
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $goingToken = $this->login('vai@exemplo.org')['accessToken'];
        $created = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $ownerToken);
        $this->assertStatus(201);
        $this->request('PUT', '/events/'.$created['id'].'/attendance', ['status' => 'going'], $goingToken);
        $this->assertStatus(200);

        $edited = $this->eventEdit($created, ['description' => 'Só um acento: reunião.']);
        $this->request('PATCH', '/events/'.$created['id'], $edited, $ownerToken);
        $this->assertStatus(200);
        $quiet = $this->request('GET', '/me/notifications', token: $goingToken);
        self::assertSame([], $quiet['items']);

        $edited['startsAt'] = $this->eventBody('+4 days')['startsAt'];
        $this->request('PATCH', '/events/'.$created['id'], $edited, $ownerToken);
        $this->assertStatus(200);
        $notices = $this->request('GET', '/me/notifications', token: $goingToken);
        self::assertSame('event_rescheduled', $notices['items'][0]['type']);
        self::assertSame('event', $notices['items'][0]['target']['kind']);
        self::assertSame($created['id'], $notices['items'][0]['target']['id']);
        self::assertSame('Assembleia mensal', $notices['items'][0]['quote']);

        $edited['placeName'] = 'Praça';
        $edited['address'] = 'Rua Y';
        $edited['onlineUrl'] = 'https://meet.example.org/nova';
        $edited['format'] = 'hybrid';
        $this->request('PATCH', '/events/'.$created['id'], $edited, $ownerToken);
        $this->assertStatus(200);
        $again = $this->request('GET', '/me/notifications', token: $goingToken);
        self::assertSame('event_updated', $again['items'][0]['type']);
    }

    public function testCancelKeepsTheEventAndNotifiesInterestedPeople(): void
    {
        $owner = $this->createUser('dona');
        $going = $this->createUser('vai');
        $maybe = $this->createUser('talvez');
        $no = $this->createUser('nao');
        $silent = $this->createUser('mudo');
        $comuna = $this->createComuna('londrina', owner: $owner);
        foreach ([$going, $maybe, $no, $silent] as $user) {
            $this->addMember($comuna, $user);
        }
        $ownerToken = $this->login('dona@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $ownerToken);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'going'], $this->login('vai@exemplo.org')['accessToken']);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'maybe'], $this->login('talvez@exemplo.org')['accessToken']);
        $this->request('PUT', '/events/'.$event['id'].'/attendance', ['status' => 'not_going'], $this->login('nao@exemplo.org')['accessToken']);

        $cancelled = $this->request('POST', '/events/'.$event['id'].'/cancel', token: $ownerToken);
        $this->assertStatus(200);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertNotNull($cancelled['cancelledAt']);
        $again = $this->request('POST', '/events/'.$event['id'].'/cancel', token: $ownerToken);
        $this->assertStatus(200);
        self::assertSame($cancelled['cancelledAt'], $again['cancelledAt']);

        self::assertSame('event_cancelled', $this->request('GET', '/me/notifications', token: $this->login('vai@exemplo.org')['accessToken'])['items'][0]['type']);
        self::assertSame('event_cancelled', $this->request('GET', '/me/notifications', token: $this->login('talvez@exemplo.org')['accessToken'])['items'][0]['type']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $this->login('nao@exemplo.org')['accessToken'])['items']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $this->login('mudo@exemplo.org')['accessToken'])['items']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM comuna_event WHERE id = ?', [$event['id']]));
    }

    public function testUpcomingAndPastOrderingPaginationAndPrivateAccess(): void
    {
        $owner = $this->createUser('dona');
        $member = $this->createUser('membro');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $member);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $soon = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+2 days', override: ['title' => 'Perto']), $token);
        $later = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+9 days', override: ['title' => 'Longe']), $token);
        $mid = $this->request('POST', '/comunas/fechada/events', $this->eventBody('+5 days', override: ['title' => 'Meio']), $token);
        $this->assertStatus(201);

        $page = $this->request('GET', '/comunas/fechada/events?limit=2', token: $token);
        $this->assertStatus(200);
        self::assertSame(['Perto', 'Meio'], array_column($page['items'], 'title'));
        self::assertNotNull($page['nextCursor']);
        $rest = $this->request('GET', '/comunas/fechada/events?limit=2&cursor='.rawurlencode((string) $page['nextCursor']), token: $token);
        $this->assertStatus(200);
        self::assertSame(['Longe'], array_column($rest['items'], 'title'));
        self::assertNull($rest['nextCursor']);

        $this->mockTime('+12 days');
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $past = $this->request('GET', '/comunas/fechada/events?when=past', token: $token);
        self::assertSame(['Longe', 'Meio', 'Perto'], array_column($past['items'], 'title'));
        self::assertTrue($past['items'][0]['past']);

        $stranger = $this->login($this->createUser('fora')->getEmail())['accessToken'];
        $this->request('GET', '/comunas/fechada/events', token: $stranger);
        $this->assertStatus(403);
        $this->request('GET', '/events/'.$soon['id'], token: $stranger);
        $this->assertStatus(404);
        self::assertStringNotContainsString('Perto', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/e/'.$soon['id']);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Perto', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Rua X', (string) $this->client->getResponse()->getContent());

        $seen = $this->request('GET', '/events/'.$later['id'], token: $this->login('membro@exemplo.org')['accessToken']);
        $this->assertStatus(200);
        self::assertSame('Longe', $seen['title']);
        self::assertNotNull($mid['id']);
    }

    public function testPublicPreviewOmitsAddressAndLink(): void
    {
        $owner = $this->createUser('dona');
        $this->createComuna('aberta', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/aberta/events', $this->eventBody(override: [
            'format' => 'hybrid',
            'onlineUrl' => 'https://meet.example.org/secreta',
            'description' => 'Pauta da assembleia.',
        ]), $token);
        $this->client->request('GET', '/e/'.$event['id']);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Assembleia mensal', $html);
        self::assertStringContainsString('Aberta', $html);
        self::assertStringContainsString('Sindicato dos Bancários', $html);
        self::assertStringContainsString('Pauta da assembleia.', $html);
        self::assertStringNotContainsString('Rua X', $html);
        self::assertStringNotContainsString('meet.example.org', $html);
    }

    public function testSuspendedOrganizerDoesNotDeleteTheEvent(): void
    {
        $owner = $this->createUser('dona');
        $member = $this->createUser('membro');
        $comuna = $this->createComuna('londrina', owner: $owner);
        $this->addMember($comuna, $member);
        $event = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $this->login('dona@exemplo.org')['accessToken']);
        $fresh = $this->fresh(User::class, $owner->getId());
        $fresh->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $seen = $this->request('GET', '/events/'.$event['id'], token: $this->login('membro@exemplo.org')['accessToken']);
        $this->assertStatus(200);
        self::assertSame('Assembleia mensal', $seen['title']);
        self::assertNull($seen['organizer']);
    }

    public function testPostMayReferenceAnEventInTheSameComuna(): void
    {
        $owner = $this->createUser('dona');
        $comuna = $this->createComuna('londrina', owner: $owner);
        $token = $this->login('dona@exemplo.org')['accessToken'];
        $event = $this->request('POST', '/comunas/londrina/events', $this->eventBody(), $token);
        $post = $this->request('POST', '/posts', ['comunaId' => $comuna->getId()->toRfc4122(), 'body' => 'Confira o evento', 'eventId' => $event['id']], $token);
        $this->assertStatus(201);
        self::assertSame($event['id'], $post['event']['id']);
        self::assertSame('Assembleia mensal', $post['event']['title']);

        $other = $this->createComuna('outra', owner: $this->fresh(User::class, $owner->getId()));
        $this->request('POST', '/posts', ['comunaId' => $other->getId()->toRfc4122(), 'body' => 'Errado', 'eventId' => $event['id']], $token);
        $this->assertStatus(400);
        $this->request('POST', '/posts', ['comunaId' => null, 'body' => 'No perfil', 'eventId' => $event['id']], $token);
        $this->assertStatus(400);
    }
}
