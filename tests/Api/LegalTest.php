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

    public function testAccountWithoutLegalAcceptanceCannotLogIn(): void
    {
        $user = $this->createUser();
        $this->em()->getConnection()->executeStatement('UPDATE app_user SET legal_version = NULL, legal_accepted_at = NULL WHERE id = ?', [$user->getId()->toRfc4122()]);
        $this->em()->clear();

        $body = $this->request('POST', '/auth/login', ['email' => $user->getEmail(), 'password' => self::PASSWORD]);

        $this->assertStatus(403);
        self::assertSame('/errors/legal-acceptance-required', $body['type']);
        self::assertSame(0, $this->em()->getRepository(\App\Entity\RefreshToken::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(\App\Entity\SecurityEvent::class)->count(['type' => \App\Enum\SecurityEventType::LoginFailed]));
    }

}
