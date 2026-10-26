<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;

final class LogoutTest extends ApiTestCase
{
    public function testLogoutEndsOnlyTheCurrentSession(): void
    {
        $this->createUser('camila');
        $phone = $this->login('camila@exemplo.org');
        $tablet = $this->login('camila@exemplo.org');

        $this->request('POST', '/auth/logout', token: $phone['accessToken']);
        $this->assertStatus(204);

        $this->request('GET', '/me', token: $phone['accessToken']);
        $this->assertStatus(401);
        $this->request('GET', '/me', token: $tablet['accessToken']);
        $this->assertStatus(200);
    }

    public function testLogoutAllEndsEverySession(): void
    {
        $this->createUser('camila');
        $phone = $this->login('camila@exemplo.org');
        $tablet = $this->login('camila@exemplo.org');

        $this->request('POST', '/auth/logout-all', token: $phone['accessToken']);
        $this->assertStatus(204);

        foreach ([$phone, $tablet] as $tokens) {
            $this->request('GET', '/me', token: $tokens['accessToken']);
            $this->assertStatus(401);
            $this->request('POST', '/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);
            $this->assertStatus(401);
        }
    }

    public function testLogoutRequiresAuthentication(): void
    {
        $this->request('POST', '/auth/logout');

        $this->assertStatus(401);
    }
}
