<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Poll;
use App\Entity\PollOption;
use App\Entity\PollVote;
use App\Poll\PollPercents;
use App\Tests\ApiTestCase;

final class PollTest extends ApiTestCase
{
    public function testValidPollIsCreatedWithThePostAndListedWithoutAnotherRequest(): void
    {
        $author = $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $post = $this->publish($token, 'Qual livro vamos ler?', [
            ['label' => '  O cortiço  '],
            ['label' => 'Capitães da Areia'],
        ], '');

        $this->assertStatus(201);
        self::assertSame('', $post['body']);
        self::assertSame('Qual livro vamos ler?', $post['poll']['question']);
        self::assertNull($post['poll']['votedOptionId']);
        self::assertSame(0, $post['poll']['totalVotes']);
        self::assertSame(['O cortiço', 'Capitães da Areia'], array_column($post['poll']['options'], 'label'));
        self::assertSame([0, 0], array_column($post['poll']['options'], 'voteCount'));
        self::assertSame([0, 0], array_column($post['poll']['options'], 'percent'));
        self::assertSame([false, false], array_column($post['poll']['options'], 'selected'));

        $feed = $this->request('GET', '/feed', token: $token);
        $this->assertStatus(200);
        self::assertSame($post['poll']['id'], $feed['items'][0]['poll']['id']);
        self::assertSame('O cortiço', $feed['items'][0]['poll']['options'][0]['label']);
        self::assertSame($author->getId()->toRfc4122(), $this->fresh(Poll::class, $post['poll']['id'])->getPost()->getAuthor()->getId()->toRfc4122());
    }

    public function testInvalidPollIsRejected(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];

        $this->publish($token, '   ', [['label' => 'Uma'], ['label' => 'Duas']]);
        $this->assertStatus(422);
        $this->publish($token, 'Pergunta', [['label' => 'Só uma']]);
        $this->assertStatus(422);
        $this->publish($token, 'Pergunta', array_map(static fn (int $i) => ['label' => 'Opção '.$i], range(1, 7)));
        $this->assertStatus(422);
        $this->publish($token, 'Pergunta', [['label' => 'Igual'], ['label' => '  igual  ']]);
        $this->assertStatus(422);
        $this->publish($token, "Pergunta\ncom quebra", [['label' => 'Uma'], ['label' => 'Duas']]);
        $this->assertStatus(422);
        $this->request('POST', '/posts', [
            'body' => 'Texto',
            'poll' => ['question' => 'Pergunta', 'options' => [['label' => 'Uma'], ['label' => 'Duas']], 'totalVotes' => 9],
        ], $token);
        $this->assertStatus(400);
        self::assertSame(0, $this->em()->getRepository(Poll::class)->count([]));
    }

    public function testVoteChangeStaysSingleAndUpdatesResults(): void
    {
        $this->createUser('camila');
        $other = $this->createUser('outra');
        $authorToken = $this->login('camila@exemplo.org')['accessToken'];
        $otherToken = $this->login('outra@exemplo.org')['accessToken'];
        $post = $this->publish($authorToken, 'Qual horário é melhor?', [
            ['label' => 'Manhã'],
            ['label' => 'Noite'],
            ['label' => 'Tarde'],
        ]);
        $this->assertStatus(201);
        [$morning, $night, $afternoon] = array_column($post['poll']['options'], 'id');
        $uri = '/posts/'.$post['id'].'/poll/vote';

        $first = $this->request('PUT', $uri, ['optionId' => $morning], $authorToken);
        $this->assertStatus(200);
        self::assertSame($morning, $first['poll']['votedOptionId']);
        self::assertSame(1, $first['poll']['totalVotes']);
        self::assertSame([1, 0, 0], array_column($first['poll']['options'], 'voteCount'));
        self::assertSame([100, 0, 0], array_column($first['poll']['options'], 'percent'));
        self::assertSame([true, false, false], array_column($first['poll']['options'], 'selected'));

        $repeat = $this->request('PUT', $uri, ['optionId' => $morning], $authorToken);
        $this->assertStatus(200);
        self::assertSame(1, $repeat['poll']['totalVotes']);
        self::assertSame(1, $this->countVotes($post['poll']['id']));

        $this->request('PUT', $uri, ['optionId' => $night], $otherToken);
        $this->assertStatus(200);
        $changed = $this->request('PUT', $uri, ['optionId' => $afternoon], $authorToken);
        $this->assertStatus(200);
        self::assertSame($afternoon, $changed['poll']['votedOptionId']);
        self::assertSame(2, $changed['poll']['totalVotes']);
        self::assertSame([0, 1, 1], array_column($changed['poll']['options'], 'voteCount'));
        self::assertSame(100, array_sum(array_column($changed['poll']['options'], 'percent')));
        self::assertSame(1, $this->countVotes($post['poll']['id'], $other->getId()->toRfc4122()));
        self::assertSame(2, $this->countVotes($post['poll']['id']));
        self::assertNotFalse($this->em()->getConnection()->fetchOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'uniq_poll_vote_user'"));
    }

    public function testPercentsStayCoherentWithAndWithoutVotes(): void
    {
        self::assertSame([0, 0, 0], PollPercents::of([0, 0, 0]));
        self::assertSame([100, 0], PollPercents::of([1, 0]));
        self::assertSame([67, 33], PollPercents::of([2, 1]));
        self::assertSame([34, 33, 33], PollPercents::of([1, 1, 1]));
    }

    public function testAnonymousCannotVoteAndStillSeesThePoll(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $post = $this->publish($token, 'O que vocês acham?', [['label' => 'Apoio'], ['label' => 'Não']]);
        $this->assertStatus(201);

        $public = $this->request('GET', '/posts/'.$post['id']);
        $this->assertStatus(200);
        self::assertNull($public['poll']['votedOptionId']);
        self::assertSame(0, $public['poll']['totalVotes']);

        $this->request('PUT', '/posts/'.$post['id'].'/poll/vote', ['optionId' => $post['poll']['options'][0]['id']]);
        $this->assertStatus(401);
        self::assertSame(0, $this->countVotes($post['poll']['id']));
    }

    public function testVoteRequiresMembershipVerifiedEmailAndARealOption(): void
    {
        $comuna = $this->createComuna('tarifa');
        $author = $this->createUser('camila');
        $this->addMember($comuna, $author);
        $this->createUser('fora');
        $this->createUser('nova', verified: false);
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $post = $this->request('POST', '/posts', [
            'comunaId' => $comuna->getId()->toRfc4122(),
            'body' => 'Pauta',
            'poll' => ['question' => 'O que vocês acham dessa pauta?', 'options' => [['label' => 'Seguir'], ['label' => 'Revisar']]],
        ], $token);
        $this->assertStatus(201);
        $uri = '/posts/'.$post['id'].'/poll/vote';
        $option = $post['poll']['options'][0]['id'];

        $denied = $this->request('PUT', $uri, ['optionId' => $option], $this->login('fora@exemplo.org')['accessToken']);
        $this->assertStatus(403);
        self::assertSame('/errors/not-a-member', $denied['type']);

        $unverified = $this->request('PUT', $uri, ['optionId' => $option], $this->login('nova@exemplo.org')['accessToken']);
        $this->assertStatus(403);
        self::assertSame('/errors/email-not-verified', $unverified['type']);

        $unknown = $this->request('PUT', $uri, ['optionId' => '00000000-0000-7000-8000-000000000099'], $token);
        $this->assertStatus(422);
        self::assertSame('optionId', $unknown['violations'][0]['propertyPath']);

        $plain = $this->request('POST', '/posts', ['body' => 'Sem enquete'], $token);
        $this->assertStatus(201);
        $missing = $this->request('PUT', '/posts/'.$plain['id'].'/poll/vote', ['optionId' => $option], $token);
        $this->assertStatus(400);
        self::assertSame('/errors/no-poll', $missing['type']);
    }

    public function testAuthorCanVoteAndDeletingThePostRemovesThePoll(): void
    {
        $this->createUser('camila');
        $token = $this->login('camila@exemplo.org')['accessToken'];
        $post = $this->publish($token, 'Qual livro?', [['label' => 'Um'], ['label' => 'Dois']]);
        $voted = $this->request('PUT', '/posts/'.$post['id'].'/poll/vote', ['optionId' => $post['poll']['options'][0]['id']], $token);
        $this->assertStatus(200);
        self::assertTrue($voted['viewer']['isAuthor']);
        self::assertSame(1, $voted['poll']['totalVotes']);

        $this->request('DELETE', '/posts/'.$post['id'], token: $token);
        $this->assertStatus(204);
        self::assertSame(0, $this->em()->getRepository(Poll::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(PollOption::class)->count([]));
        self::assertSame(0, $this->em()->getRepository(PollVote::class)->count([]));
        $this->request('GET', '/posts/'.$post['id'], token: $token);
        $this->assertStatus(404);
    }

    /**
     * @param list<array{label: string}> $options
     *
     * @return array<string, mixed>
     */
    private function publish(string $token, string $question, array $options, string $body = 'Contexto'): array
    {
        return $this->request('POST', '/posts', [
            'body' => $body,
            'poll' => ['question' => $question, 'options' => $options],
        ], $token);
    }

    private function countVotes(string $pollId, ?string $userId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM poll_vote WHERE poll_id = ?';
        $params = [$pollId];
        if (null !== $userId) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        return (int) $this->em()->getConnection()->fetchOne($sql, $params);
    }
}
