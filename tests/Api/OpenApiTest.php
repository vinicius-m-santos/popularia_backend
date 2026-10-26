<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class OpenApiTest extends ApiTestCase
{
    public function testDocumentListsTheVersionedEndpoints(): void
    {
        $this->client->request('GET', '/api/v1/docs.jsonopenapi');

        $this->assertStatus(200);
        $doc = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Popularia API', $doc['info']['title']);
        foreach (['/api/v1/health', '/api/v1/auth/register', '/api/v1/auth/login', '/api/v1/auth/refresh', '/api/v1/me', '/api/v1/feed', '/api/v1/posts/{id}/comments',
            '/api/v1/comunas', '/api/v1/comunas/{slug}', '/api/v1/comunas/{slug}/archive', '/api/v1/comunas/{slug}/members', '/api/v1/comunas/{slug}/members/{handle}',
            '/api/v1/comunas/{slug}/moderators/{handle}', '/api/v1/comunas/{slug}/join-request', '/api/v1/comunas/{slug}/join-requests/{id}/approve',
            '/api/v1/comunas/{slug}/ownership-transfer', '/api/v1/comunas/{slug}/ownership-transfer/accept', '/api/v1/me/comunas', '/api/v1/me/comuna-quota',
            '/api/v1/comuna-names/availability', '/api/v1/posts/{id}/removal', '/api/v1/comments/{id}/removal'] as $path) {
            self::assertArrayHasKey($path, $doc['paths'], $path);
        }
        // A conta própria documenta o e-mail; o perfil público, não.
        self::assertArrayHasKey('email', $doc['components']['schemas']['Me']['properties']);
        self::assertArrayNotHasKey('email', $doc['components']['schemas']['Profile']['properties']);
    }
}
