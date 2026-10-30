<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\ContentReport;
use App\Enum\PlatformRole;
use App\Tests\ApiTestCase;

final class ReportPreservationTest extends ApiTestCase
{
    public function testReporterDeletionAnonymizesReportAndComunaDeletionPreservesPlatformQueue(): void
    {
        $author = $this->createUser('autora');
        $reporter = $this->createUser('leitora');
        $platform = $this->createUser('equipe');
        $platform->changePlatformRole(PlatformRole::PlatformModerator, new \DateTimeImmutable());
        $this->em()->flush();
        $comuna = $this->createComuna('coletivo', owner: $author);
        $post = $this->createPost($author, $comuna, 'Evidência mínima');
        $token = $this->login($reporter->getEmail())['accessToken'];
        $this->request('POST', '/reports', ['targetType' => 'post', 'targetId' => $post->getId()->toRfc4122(), 'reasonCode' => 'spam', 'description' => 'Texto livre da denunciante'], $token);
        $this->assertStatus(204);
        $report = $this->em()->getRepository(ContentReport::class)->findOneBy(['targetId' => $post->getId()]);
        self::assertInstanceOf(ContentReport::class, $report);
        $this->request('POST', '/me/account-deletion', ['confirmation' => 'EXCLUIR', 'password' => self::PASSWORD], $token);
        $this->assertStatus(204);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT reporter_id, reason, reason_code, snapshot FROM content_report WHERE id = ?', [$report->id->toRfc4122()]);
        self::assertIsArray($row);
        self::assertNull($row['reporter_id']);
        self::assertSame('', $row['reason']);
        self::assertSame('spam', $row['reason_code']);
        self::assertSame('Evidência mínima', $row['snapshot']);

        // Exercita a FK real, inclusive quando a exclusão não passa pelo serviço HTTP.
        $this->em()->getConnection()->executeStatement('DELETE FROM comuna WHERE id = ?', [$comuna->getId()->toRfc4122()]);
        $this->em()->clear();
        $row = $this->em()->getConnection()->fetchAssociative('SELECT comuna_id, scope FROM content_report WHERE id = ?', [$report->id->toRfc4122()]);
        self::assertSame(['comuna_id' => null, 'scope' => 'platform'], $row);
        $queue = $this->request('GET', '/moderation/reports', token: $this->login('equipe@exemplo.org')['accessToken']);
        $this->assertStatus(200);
        self::assertSame($report->id->toRfc4122(), $queue['items'][0]['id']);
        self::assertStringNotContainsString('leitora', json_encode($queue, \JSON_THROW_ON_ERROR));
    }
}
