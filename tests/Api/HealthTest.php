<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class HealthTest extends ApiTestCase
{
    public function testHealthReportsDatabaseWithoutInternalDetails(): void
    {
        $body = $this->request('GET', '/health');

        $this->assertStatus(200);
        self::assertSame(['status' => 'ok', 'database' => 'ok'], $body);
    }

    public function testUnknownRouteIsProblemJson(): void
    {
        $body = $this->request('GET', '/nao-existe');

        $this->assertStatus(404);
        self::assertSame('/errors/not-found', $body['type']);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testResponsesCarryRequestId(): void
    {
        $this->request('GET', '/health');

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $this->client->getResponse()->headers->get('X-Request-Id'));
    }
}
