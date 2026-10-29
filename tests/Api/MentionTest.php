<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\ContentMention;
use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\UserBlock;
use App\Enum\AccountStatus;
use App\Enum\ComunaVisibility;
use App\Enum\NotificationType;
use App\Notification\ExpoPushProvider;
use App\Notification\NotificationCreator;
use App\Notification\PushWorker;
use App\Repository\ContentMentionRepository;
use App\Repository\NotificationRepository;
use App\Tests\ApiTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MentionTest extends ApiTestCase
{
    public function testPostStoresOneMentionAndNotifiesOnce(): void
    {
        $author = $this->createUser('ana');
        $bia = $this->createUser('bia');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $body = 'Olá @bia, tudo bem?';

        $post = $this->request('POST', '/posts', [
            'body' => '  '.$body.'  ',
            'mentions' => [$this->range($bia, $body, '@bia')],
        ], $token);

        $this->assertStatus(201);
        self::assertSame($body, $post['body']);
        self::assertSame([$this->view($bia, $body, '@bia')], $post['mentions']);
        $items = $this->request('GET', '/me/notifications', token: $biaToken)['items'];
        self::assertSame(['mention_post'], array_column($items, 'type'));
        self::assertSame('post', $items[0]['target']['kind']);
        self::assertSame($post['id'], $items[0]['target']['id']);
        self::assertSame($body, $items[0]['quote']);
        self::assertSame('ana', $items[0]['actor']['handle']);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $token)['unreadCount']);
        self::assertTrue($this->fresh(Post::class, $post['id'])->getAuthor()->getId()->equals($author->getId()));
    }

    public function testCommentAndReplyMentionDifferentPeopleWithoutDuplicatingTheReply(): void
    {
        $ana = $this->createUser('ana');
        $bia = $this->createUser('bia');
        $caio = $this->createUser('caio');
        $post = $this->createPost($ana, null, 'Discussão');
        $anaToken = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $caioToken = $this->login('caio@exemplo.org')['accessToken'];
        $uri = '/posts/'.$post->getId()->toRfc4122().'/comments';
        $parentBody = 'Começo @caio';
        $parent = $this->request('POST', $uri, [
            'body' => $parentBody,
            'mentions' => [$this->range($caio, $parentBody, '@caio')],
        ], $biaToken);
        $this->assertStatus(201);
        self::assertSame([$this->view($caio, $parentBody, '@caio')], $parent['mentions']);
        self::assertSame('mention_comment', $this->request('GET', '/me/notifications', token: $this->login('caio@exemplo.org')['accessToken'])['items'][0]['type']);
        self::assertSame('comment', $this->request('GET', '/me/notifications', token: $anaToken)['items'][0]['type']);

        $replyBody = '@bia @ana @caio olhem';
        $reply = $this->request('POST', $uri, [
            'body' => $replyBody,
            'parentId' => $parent['id'],
            'mentions' => [
                $this->range($bia, $replyBody, '@bia'),
                $this->range($ana, $replyBody, '@ana'),
                $this->range($caio, $replyBody, '@caio'),
            ],
        ], $caioToken);
        $this->assertStatus(201);
        self::assertCount(3, $reply['mentions']);
        self::assertSame(['reply'], array_column($this->request('GET', '/me/notifications', token: $biaToken)['items'], 'type'));
        $anaTypes = array_column($this->request('GET', '/me/notifications', token: $anaToken)['items'], 'type');
        self::assertSame(['comment', 'comment'], $anaTypes);
        self::assertNotContains('mention_comment', $anaTypes);
        self::assertSame([], array_values(array_filter(
            $this->request('GET', '/me/notifications', token: $caioToken)['items'],
            static fn (array $item): bool => 'mention_comment' === $item['type'] && str_contains((string) $item['quote'], '@bia @ana @caio'),
        )));
        self::assertSame($post->getId()->toRfc4122(), $this->request('GET', '/me/notifications', token: $biaToken)['items'][0]['target']['id']);
    }

    public function testSamePersonTwiceNotifiesOnceAndPlainHandleDoesNot(): void
    {
        $this->createUser('ana');
        $bia = $this->createUser('bia');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $body = '@bia veja isso, @bia';
        $first = mb_strpos($body, '@bia', 0, 'UTF-8');
        $second = mb_strpos($body, '@bia', $first + 1, 'UTF-8');
        self::assertNotFalse($first);
        self::assertNotFalse($second);

        $post = $this->request('POST', '/posts', ['body' => $body, 'mentions' => [
            ['userId' => $bia->getId()->toRfc4122(), 'start' => $first, 'end' => $first + mb_strlen('@bia', 'UTF-8')],
            ['userId' => $bia->getId()->toRfc4122(), 'start' => $second, 'end' => $second + mb_strlen('@bia', 'UTF-8')],
        ]], $token);

        $this->assertStatus(201);
        self::assertCount(2, $post['mentions']);
        self::assertSame([$bia->getId()->toRfc4122(), $bia->getId()->toRfc4122()], array_column($post['mentions'], 'userId'));
        self::assertCount(1, $this->request('GET', '/me/notifications', token: $biaToken)['items']);

        $before = $this->em()->getRepository(Notification::class)->count([]);
        $plain = $this->request('POST', '/posts', ['body' => '@bia sem selecionar'], $token);
        $this->assertStatus(201);
        self::assertSame([], $plain['mentions']);
        self::assertSame($before, $this->em()->getRepository(Notification::class)->count([]));
    }

    public function testSelfMentionRendersWithoutNotification(): void
    {
        $ana = $this->createUser('ana');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $body = 'Nota para @ana';
        $post = $this->request('POST', '/posts', ['body' => $body, 'mentions' => [$this->range($ana, $body, '@ana')]], $token);

        $this->assertStatus(201);
        self::assertSame($ana->getId()->toRfc4122(), $post['mentions'][0]['userId']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $token)['items']);
    }

    public function testInvalidStructuredMentionsAreRejectedAndCreateNothing(): void
    {
        $this->createUser('ana');
        $bia = $this->createUser('bia');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $beforePosts = $this->em()->getRepository(Post::class)->count([]);
        $beforeNotes = $this->em()->getRepository(Notification::class)->count([]);
        $cases = [
            ['body' => '@bia', 'mentions' => [['userId' => $bia->getId()->toRfc4122(), 'start' => 0, 'end' => 2]]],
            ['body' => '@bia', 'mentions' => [['userId' => '00000000-0000-4000-8000-000000000099', 'start' => 0, 'end' => 4]]],
            ['body' => '@bia e @bia', 'mentions' => [
                $this->range($bia, '@bia e @bia', '@bia'),
                $this->range($bia, '@bia e @bia', '@bia'),
            ]],
            ['body' => '@bias', 'mentions' => [['userId' => $bia->getId()->toRfc4122(), 'start' => 0, 'end' => 4]]],
            ['body' => 'teste@bia.com', 'mentions' => [['userId' => $bia->getId()->toRfc4122(), 'start' => 5, 'end' => 9]]],
            ['body' => '@bia', 'mentions' => array_fill(0, 11, $this->range($bia, '@bia', '@bia'))],
        ];
        foreach ($cases as $case) {
            $this->request('POST', '/posts', $case, $token);
            $this->assertStatus(422);
        }
        self::assertSame($beforePosts, $this->em()->getRepository(Post::class)->count([]));
        self::assertSame($beforeNotes, $this->em()->getRepository(Notification::class)->count([]));
    }

    public function testSuspendedAndMissingAccountsCannotBeMentioned(): void
    {
        $this->createUser('ana');
        $suspended = $this->createUser('bia');
        $suspended->changeStatus(AccountStatus::Suspended, new \DateTimeImmutable());
        $this->em()->flush();
        $token = $this->login('ana@exemplo.org')['accessToken'];

        $this->request('POST', '/posts', ['body' => '@bia', 'mentions' => [$this->range($suspended, '@bia', '@bia')]], $token);
        $this->assertStatus(422);
        $this->request('POST', '/posts', ['body' => '@bia', 'mentions' => [[
            'userId' => '00000000-0000-4000-8000-000000009999',
            'start' => 0,
            'end' => 4,
        ]]], $token);
        $this->assertStatus(422);
        self::assertSame(0, $this->em()->getRepository(Post::class)->count([]));
    }

    public function testBlockPreventsNotificationButKeepsTheReference(): void
    {
        $ana = $this->createUser('ana');
        $bia = $this->createUser('bia');
        $this->em()->persist(new UserBlock($bia, $ana, new \DateTimeImmutable()));
        $this->em()->flush();
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $body = 'Oi @bia';

        $post = $this->request('POST', '/posts', ['body' => $body, 'mentions' => [$this->range($bia, $body, '@bia')]], $token);

        $this->assertStatus(201);
        self::assertSame($bia->getId()->toRfc4122(), $post['mentions'][0]['userId']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $biaToken)['items']);
        $this->request('GET', '/posts/'.$post['id'], token: $biaToken);
        $this->assertStatus(404);
        self::assertSame([], $this->request('GET', '/search?type=people&q=bia', token: $token)['people']);
    }

    public function testPrivateComunaAndPrivateProfileDoNotDeliverMention(): void
    {
        $owner = $this->createUser('dona');
        $author = $this->createUser('ana');
        $outsider = $this->createUser('bia');
        $follower = $this->createUser('caio');
        $comuna = $this->createComuna('fechada', ComunaVisibility::Private, $owner);
        $this->addMember($comuna, $author);
        $authorToken = $this->login('ana@exemplo.org')['accessToken'];
        $outsiderToken = $this->login('bia@exemplo.org')['accessToken'];
        $this->register($outsiderToken);
        $body = 'Só quem entra @bia';
        $post = $this->request('POST', '/posts', [
            'comunaId' => $comuna->getId()->toRfc4122(),
            'body' => $body,
            'mentions' => [$this->range($outsider, $body, '@bia')],
        ], $authorToken);
        $this->assertStatus(201);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $outsiderToken)['items']);
        self::assertSame(0, $this->request('GET', '/me/notifications/unread-count', token: $outsiderToken)['unreadCount']);
        $stored = $this->em()->getRepository(Notification::class)->findOneBy(['type' => NotificationType::MentionPost]);
        self::assertInstanceOf(Notification::class, $stored);
        $storedId = $stored->getId()->toRfc4122();
        self::assertNull(static::getContainer()->get(NotificationRepository::class)->findVisible($this->fresh(User::class, $outsider->getId()), $storedId));
        self::assertSame(0, $this->worker()->run()['sent']);

        $this->request('PUT', '/users/ana/follow', token: $this->login('caio@exemplo.org')['accessToken']);
        $this->request('PATCH', '/me', ['isPrivate' => true], $authorToken, 'application/merge-patch+json');
        $profileBody = 'Perfil @bia';
        $this->request('POST', '/posts', ['body' => $profileBody, 'mentions' => [$this->range($outsider, $profileBody, '@bia')]], $authorToken);
        $this->assertStatus(201);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $outsiderToken)['items']);
        $followerToken = $this->login('caio@exemplo.org')['accessToken'];
        $followerBody = 'Perfil @caio';
        $visible = $this->request('POST', '/posts', ['body' => $followerBody, 'mentions' => [$this->range($follower, $followerBody, '@caio')]], $authorToken);
        $this->assertStatus(201);
        self::assertSame('mention_post', $this->request('GET', '/me/notifications', token: $followerToken)['items'][0]['type']);
        self::assertSame($visible['id'], $this->request('GET', '/me/notifications', token: $followerToken)['items'][0]['target']['id']);
    }

    public function testRemovedContentHidesMentionNotificationAndDeletionCascades(): void
    {
        $owner = $this->createUser('dona');
        $author = $this->createUser('ana');
        $bia = $this->createUser('bia');
        $comuna = $this->createComuna('aberta', ComunaVisibility::Public, $owner);
        $this->addMember($comuna, $author);
        $authorToken = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $body = 'Valeu @bia';
        $post = $this->request('POST', '/posts', [
            'comunaId' => $comuna->getId()->toRfc4122(),
            'body' => $body,
            'mentions' => [$this->range($bia, $body, '@bia')],
        ], $authorToken);
        $noteId = $this->request('GET', '/me/notifications', token: $biaToken)['items'][0]['id'];
        $this->request('POST', '/posts/'.$post['id'].'/removal', ['reason' => 'Fora do tema'], $this->login('dona@exemplo.org')['accessToken']);
        self::assertSame([], $this->request('GET', '/me/notifications', token: $biaToken)['items']);
        $this->request('GET', '/me/notifications/'.$noteId, token: $biaToken);
        $this->assertStatus(404);

        $own = $this->request('POST', '/posts', ['body' => $body, 'mentions' => [$this->range($bia, $body, '@bia')]], $authorToken);
        $commentBody = 'Resposta @bia';
        $comment = $this->request('POST', '/posts/'.$own['id'].'/comments', [
            'body' => $commentBody,
            'mentions' => [$this->range($bia, $commentBody, '@bia')],
        ], $authorToken);
        $this->request('DELETE', '/posts/'.$own['id'], token: $authorToken);
        $this->assertStatus(204);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM content_mention WHERE post_id = ?', [$own['id']]));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM content_mention WHERE comment_id = ?', [$comment['id']]));
        $this->em()->getConnection()->executeStatement('DELETE FROM comment WHERE id = ?', [$comment['id']]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM content_mention WHERE comment_id = ?', [$comment['id']]));
    }

    public function testHandleChangeKeepsTheOriginalTextAndTheSameAccount(): void
    {
        $this->createUser('ana');
        $bia = $this->createUser('bia');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $body = 'Valeu @bia';
        $created = $this->request('POST', '/posts', ['body' => $body, 'mentions' => [$this->range($bia, $body, '@bia')]], $token);
        $this->fresh(User::class, $bia->getId())->updateProfile(null, null, null, null, new \DateTimeImmutable(), 'novohandle');
        $this->em()->flush();

        $post = $this->request('GET', '/posts/'.$created['id'], token: $token);

        $this->assertStatus(200);
        self::assertSame($body, $post['body']);
        self::assertSame($bia->getId()->toRfc4122(), $post['mentions'][0]['userId']);
        self::assertSame('novohandle', $post['mentions'][0]['handle']);
        self::assertSame(6, $post['mentions'][0]['start']);
        self::assertSame(10, $post['mentions'][0]['end']);
    }

    public function testRepeatingTheSameMentionDoesNotCreateAnotherNotification(): void
    {
        $this->createUser('ana');
        $bia = $this->createUser('bia');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $body = 'Oi @bia';
        $post = $this->request('POST', '/posts', ['body' => $body, 'mentions' => [$this->range($bia, $body, '@bia')]], $token);
        $this->em()->clear();
        $creator = static::getContainer()->get(NotificationCreator::class);
        $creator->mentionedInPost(
            $this->fresh(Post::class, $post['id']),
            [$this->fresh(User::class, $bia->getId())],
        );
        $this->em()->flush();

        self::assertSame(1, $this->em()->getRepository(Notification::class)->count(['type' => NotificationType::MentionPost]));
    }

    public function testPushCarriesOnlyIdsAndSkipsHiddenMentions(): void
    {
        $this->createUser('ana');
        $bia = $this->createUser('bia');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $this->register($biaToken);
        $body = 'Segredo visível @bia';
        $this->request('POST', '/posts', ['body' => $body, 'mentions' => [$this->range($bia, $body, '@bia')]], $token);
        $result = $this->worker();
        self::assertSame(1, $result->run()['sent']);
        $payload = $this->pushRequests[0]['payload'][0];
        self::assertSame('Você foi mencionado em uma publicação.', $payload['body']);
        self::assertSame(['notificationId', 'recipientId'], array_keys($payload['data']));
        self::assertStringNotContainsString('Segredo', $payload['body']);
        self::assertStringNotContainsString('@bia', $payload['body']);
    }

    public function testFeedAndCommentsSerializeMentionsInOneRead(): void
    {
        $this->createUser('ana');
        $bia = $this->createUser('bia');
        $caio = $this->createUser('caio');
        $token = $this->login('ana@exemplo.org')['accessToken'];
        $ids = [];
        foreach ([$bia, $caio, $bia] as $index => $person) {
            $handle = $person->getHandle();
            $body = "Publicação {$index} @{$handle}";
            $created = $this->request('POST', '/posts', ['body' => $body, 'mentions' => [$this->range($person, $body, '@'.$handle)]], $token);
            $this->assertStatus(201);
            $ids[] = $created['id'];
        }
        ContentMentionRepository::resetReads();
        $feed = $this->request('GET', '/feed?limit=20', token: $token);
        $this->assertStatus(200);
        self::assertSame(1, ContentMentionRepository::takeReads());
        $byId = [];
        foreach ($feed['items'] as $item) {
            $byId[$item['id']] = $item['mentions'];
        }
        foreach ($ids as $id) {
            self::assertCount(1, $byId[$id]);
        }

        $commentIds = [];
        foreach ([$bia, $caio] as $person) {
            $body = 'Comentário @'.$person->getHandle();
            $comment = $this->request('POST', '/posts/'.$ids[0].'/comments', [
                'body' => $body,
                'mentions' => [$this->range($person, $body, '@'.$person->getHandle())],
            ], $token);
            $commentIds[] = $comment['id'];
        }
        ContentMentionRepository::resetReads();
        $page = $this->request('GET', '/posts/'.$ids[0].'/comments', token: $token);
        self::assertSame(1, ContentMentionRepository::takeReads());
        $comments = [];
        foreach ($page['items'] as $item) {
            $comments[$item['id']] = $item['mentions'];
        }
        foreach ($commentIds as $id) {
            self::assertCount(1, $comments[$id]);
        }
    }

    public function testDeletedAccountDropsMentionRowsWithoutRewritingTheText(): void
    {
        $this->createUser('ana');
        $bia = $this->createUser('bia');
        $authorToken = $this->login('ana@exemplo.org')['accessToken'];
        $body = 'Chamando @bia';
        $post = $this->request('POST', '/posts', ['body' => $body, 'mentions' => [$this->range($bia, $body, '@bia')]], $authorToken);
        $biaToken = $this->login('bia@exemplo.org')['accessToken'];
        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $biaToken);
        $this->assertStatus(204);

        $loaded = $this->request('GET', '/posts/'.$post['id'], token: $authorToken);
        $this->assertStatus(200);
        self::assertSame($body, $loaded['body']);
        self::assertSame([], $loaded['mentions']);
        self::assertSame(0, $this->em()->getRepository(ContentMention::class)->count([]));
    }

    /** @param list<array<string, mixed>>|null $tickets */
    private function worker(?array $tickets = null): PushWorker
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($tickets): MockResponse {
            $payload = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $this->pushRequests[] = ['url' => $url, 'payload' => $payload];
            $data = $tickets ?? array_map(static fn (mixed $message, int|string $i): array => ['status' => 'ok', 'id' => 'ticket-'.$i], $payload, array_keys($payload));

            return new MockResponse(json_encode(['data' => $data], \JSON_THROW_ON_ERROR));
        });

        return new PushWorker($this->em()->getConnection(), $this->em(), static::getContainer()->get(NotificationRepository::class), new ExpoPushProvider($http, ''), static::getContainer()->get(ClockInterface::class), new NullLogger());
    }

    /** @var list<array{url: string, payload: array<mixed>}> */
    private array $pushRequests = [];

    private function register(string $token): void
    {
        $this->request('PUT', '/me/push-devices/00000000-0000-4000-8000-0000000000aa', [
            'token' => 'ExpoPushToken[mention-device]',
            'platform' => 'ios',
            'revocationSecret' => '00000000-0000-4000-8000-000000000007',
        ], $token);
        $this->assertStatus(200);
    }

    /** @return array{userId: string, start: int, end: int} */
    private function range(User $user, string $body, string $token): array
    {
        $start = mb_strpos($body, $token, 0, 'UTF-8');
        self::assertNotFalse($start, $token);

        return ['userId' => $user->getId()->toRfc4122(), 'start' => $start, 'end' => $start + mb_strlen($token, 'UTF-8')];
    }

    /** @return array{userId: string, handle: string, displayName: string, avatarUrl: null, start: int, end: int} */
    private function view(User $user, string $body, string $token): array
    {
        $range = $this->range($user, $body, $token);

        return [
            'userId' => $user->getId()->toRfc4122(),
            'handle' => $user->getHandle(),
            'displayName' => $user->getDisplayName(),
            'avatarUrl' => null,
            'start' => $range['start'],
            'end' => $range['end'],
        ];
    }
}
