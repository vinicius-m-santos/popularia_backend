<?php

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Com debug desligado (como em produção), os erros não trazem trace, arquivos ou classes internas. */
final class ErrorDisclosureTest extends WebTestCase
{
    public function testErrorsDoNotLeakInternals(): void
    {
        $client = static::createClient(['debug' => false]);

        foreach ([
            ['POST', '/api/v1/auth/login', '{"email":"a@b.c","password":"x","role":"admin"}'],
            ['POST', '/api/v1/auth/login', '{nao e json'],
            ['GET', '/api/v1/me', null],
            ['GET', '/api/v1/nao-existe', null],
        ] as [$method, $uri, $body]) {
            $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body);
            $content = (string) $client->getResponse()->getContent();

            self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
            self::assertLessThan(500, $client->getResponse()->getStatusCode(), $content);
            foreach (['trace', '/app/', 'vendor', 'Exception', 'Doctrine'] as $leak) {
                self::assertStringNotContainsString($leak, $content, "$method $uri");
            }
        }
    }
}
