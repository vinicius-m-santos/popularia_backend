<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\ApiTestCase;

final class HandleSuggestionTest extends ApiTestCase
{
    public function testSuggestsAValidAvailableHandleWithoutAuthentication(): void
    {
        $body = $this->request('GET', '/handles/suggestion');

        $this->assertStatus(200);
        self::assertArrayHasKey('handle', $body);
        self::assertMatchesRegularExpression(User::HANDLE_PATTERN, $body['handle']);
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['handle' => $body['handle']]));
    }
}
