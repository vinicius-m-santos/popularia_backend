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
        foreach (['/api/v1/health', '/api/v1/auth/register', '/api/v1/auth/login', '/api/v1/auth/refresh', '/api/v1/me', '/api/v1/feed', '/api/v1/posts/{id}/comments'] as $path) {
            self::assertArrayHasKey($path, $doc['paths'], $path);
        }
        // A conta própria documenta o e-mail; o perfil público, não.
        self::assertArrayHasKey('email', $doc['components']['schemas']['Me']['properties']);
        self::assertArrayNotHasKey('email', $doc['components']['schemas']['Profile']['properties']);
    }
}
