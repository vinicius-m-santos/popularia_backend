<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Legal\LegalDocuments;
use App\Tests\ApiTestCase;

final class LegalTest extends ApiTestCase
{
    public function testDocumentsArePublicAndContainRealContact(): void
    {
        foreach (['privacidade', 'termos', 'regras', 'suporte', 'excluir-conta'] as $page) {
            $this->client->request('GET', '/'.$page);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('meta[name="viewport"]');
            self::assertStringContainsString('no-transform', (string) $this->client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString(LegalDocuments::CONTACT, (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('localhost', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testLegalAcceptanceIsRecordedAndRequiredBeforeUgc(): void
    {
        $user = $this->createUser();
        $this->createComuna('saida-sem-aceite', owner: $user);
        $id = $user->getId()->toRfc4122();
        $this->em()->getConnection()->executeStatement('UPDATE app_user SET legal_version = NULL, legal_accepted_at = NULL WHERE id = ?', [$id]);
        $tokens = $this->login($user->getEmail());
        $me = $this->request('GET', '/me', token: $tokens['accessToken']);
        self::assertFalse($me['legalAccepted']);
        $this->request('POST', '/comunas/saida-sem-aceite/archive', token: $tokens['accessToken']);
        $this->assertStatus(200);
        $this->request('PATCH', '/me', ['bio' => 'Não publicar sem aceite'], $tokens['accessToken']);
        $this->assertStatus(403);
        $this->request('POST', '/posts', ['body' => 'Antes do aceite'], $tokens['accessToken']);
        $this->assertStatus(403);
        $this->request('POST', '/me/legal-acceptance', ['version' => 'old', 'accepted' => true], $tokens['accessToken']);
        $this->assertStatus(422);
        $me = $this->request('POST', '/me/legal-acceptance', ['version' => LegalDocuments::VERSION, 'accepted' => true], $tokens['accessToken']);
        $this->assertStatus(201);
        self::assertTrue($me['legalAccepted']);
        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT legal_accepted_at FROM app_user WHERE id = ?', [$id]));
        $this->request('POST', '/posts', ['body' => 'Depois do aceite'], $tokens['accessToken']);
        $this->assertStatus(201);
    }
}
