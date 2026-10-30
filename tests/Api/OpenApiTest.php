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
        foreach (['/api/v1/library/documents', '/api/v1/library/documents/{id}', '/api/v1/library/documents/{id}/file', '/api/v1/library/collections', '/api/v1/library/collections/{id}', '/api/v1/me/saved-documents', '/api/v1/library/reports', '/api/v1/me/follow-requests', '/api/v1/squares', '/api/v1/squares/{id}', '/api/v1/squares/{id}/messages', '/api/v1/squares/{id}/presence', '/api/v1/squares/{id}/read', '/api/v1/squares/{id}/sticker-packs', '/api/v1/comunas/{slug}/square', '/api/v1/comunas/{slug}/reports', '/api/v1/explore', '/api/v1/search', '/api/v1/health', '/api/v1/auth/register', '/api/v1/auth/login', '/api/v1/auth/refresh', '/api/v1/me', '/api/v1/me/gamification', '/api/v1/feed', '/api/v1/posts/{id}/comments',
            '/api/v1/comunas', '/api/v1/comunas/{slug}', '/api/v1/comunas/{slug}/archive', '/api/v1/comunas/{slug}/members', '/api/v1/comunas/{slug}/members/{handle}',
            '/api/v1/comunas/{slug}/moderators/{handle}', '/api/v1/comunas/{slug}/join-request', '/api/v1/comunas/{slug}/join-requests/{id}/approve',
            '/api/v1/comunas/{slug}/ownership-transfer', '/api/v1/comunas/{slug}/ownership-transfer/accept', '/api/v1/me/comunas', '/api/v1/me/comuna-quota',
            '/api/v1/comunas/{slug}/pinned-posts', '/api/v1/comunas/{slug}/pinned-posts/{postId}', '/api/v1/comunas/{slug}/pinned-posts/{postId}/position',
            '/api/v1/comuna-names/availability', '/api/v1/posts/{id}/removal', '/api/v1/posts/{id}/poll/vote', '/api/v1/posts/{id}/save', '/api/v1/me/saved-posts', '/api/v1/comments/{id}/removal',
            '/api/v1/me/avatar', '/api/v1/comunas/{slug}/cover', '/api/v1/media/avatars/{userId}/{fileId}', '/api/v1/media/comuna-covers/{comunaId}/{fileId}',
            '/api/v1/users/{handle}/followers', '/api/v1/users/{handle}/following',
            '/api/v1/comunas/{slug}/events', '/api/v1/events/{id}', '/api/v1/events/{id}/cancel', '/api/v1/events/{id}/attendance', '/api/v1/events/{id}/attendees',
            '/api/v1/reports', '/api/v1/comunas/{slug}/content-reports', '/api/v1/moderation/reports', '/api/v1/moderation/reports/{id}', '/api/v1/moderation/reports/{id}/decision'] as $path) {
            self::assertArrayHasKey($path, $doc['paths'], $path);
        }
        self::assertArrayNotHasKey('put', $doc['paths']['/api/v1/comunas/{slug}/square']);
        self::assertArrayNotHasKey('patch', $doc['paths']['/api/v1/squares/{id}']);
        // A conta própria documenta o e-mail; o perfil público, não.
        self::assertArrayHasKey('email', $doc['components']['schemas']['Me']['properties']);
        self::assertArrayNotHasKey('email', $doc['components']['schemas']['Profile']['properties']);
    }
}
