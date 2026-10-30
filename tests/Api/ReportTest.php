<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\ContentReport;
use App\Tests\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReportTest extends ApiTestCase
{
    public function testReportIsPersistedDecidedAndIdenticalRepostIsFiltered(): void
    {
        $author = $this->createUser('autoria');
        $reader = $this->createUser('leitora');
        $post = $this->createPost($author, null, 'Conteúdo denunciado e removido');
        $authorToken = $this->login($author->getEmail())['accessToken'];
        $token = $this->login($reader->getEmail())['accessToken'];
        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'threat', 'description' => 'Ameaça na publicação'], $token);
        $this->assertStatus(204);
        $reports = $this->em()->getRepository(ContentReport::class)->findAll();
        self::assertCount(1, $reports);
        self::assertNotNull(self::$kernel);
        $command = new CommandTester((new Application(self::$kernel))->find('app:reports:review'));
        $command->execute(['id' => $reports[0]->id->toRfc4122(), 'decision' => 'removed', 'reason' => 'Violação de segurança confirmada']);
        self::assertSame(0, $command->getStatusCode());
        $inbox = $this->request('GET', '/me/notifications', token: $authorToken);
        self::assertCount(1, $inbox['items']);
        self::assertSame('post_removed', $inbox['items'][0]['type']);
        self::assertSame('notice', $inbox['items'][0]['target']['kind']);
        self::assertNull($inbox['items'][0]['actor']);
        self::assertNull($inbox['items'][0]['quote']);
        self::assertStringNotContainsString('leitora', json_encode($inbox, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Violação de segurança confirmada', json_encode($inbox, \JSON_THROW_ON_ERROR));
        $command->execute(['id' => $reports[0]->id->toRfc4122(), 'decision' => 'removed', 'reason' => 'Repetição']);
        self::assertCount(1, $this->request('GET', '/me/notifications', token: $authorToken)['items']);
        $this->request('GET', '/posts/'.$post->getId(), token: $token);
        $this->assertStatus(404);
        $this->request('POST', '/posts', ['body' => 'Conteúdo denunciado e removido'], $authorToken);
        $this->assertStatus(422);
    }
}
