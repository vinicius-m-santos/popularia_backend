<?php

declare(strict_types=1);

namespace App\Tests\Social;

use App\Social\AppleTokenValidator;
use App\Social\GoogleTokenValidator;
use App\Social\JwksClient;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Os testes HTTP de social login usam stubs. Estes exercitam assinatura/JWKS de verdade. */
final class RealTokenValidationTest extends TestCase
{
    public function testSignedTokensWithoutNbfAreAcceptedAndInvalidTokensRejected(): void
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($private);
        openssl_pkey_export($private, $pem);
        $details = openssl_pkey_get_details($private);
        self::assertNotFalse($details);
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $jwks = ['keys' => [['kid' => 'test-key', 'kty' => 'RSA', 'use' => 'sig', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]];
        $http = new MockHttpClient(static fn () => new MockResponse(json_encode($jwks, JSON_THROW_ON_ERROR)));
        $keys = new JwksClient($http, new ArrayAdapter());
        $clock = new MockClock('2026-10-27T12:00:00Z');
        $config = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($pem), InMemory::plainText($details['key']));
        foreach (['google', 'apple'] as $provider) {
            $validator = 'google' === $provider ? new GoogleTokenValidator($keys, $clock, 'audience') : new AppleTokenValidator($keys, $clock, 'audience');
            $builder = $config->builder()->withHeader('kid', 'test-key')->issuedBy('google' === $provider ? 'https://accounts.google.com' : 'https://appleid.apple.com')->permittedFor('audience')->relatedTo('subject')->issuedAt($clock->now())->expiresAt($clock->now()->modify('+1 hour'))->withClaim('email', 'pessoa@example.org')->withClaim('email_verified', 'true')->withClaim('nonce_supported', true)->withClaim('nonce', hash('sha256', 'nonce'));
            $token = $builder->getToken($config->signer(), $config->signingKey())->toString();
            self::assertSame('subject', $validator->validate($token, 'nonce')->subject);
            $wrongAudience = 'google' === $provider ? new GoogleTokenValidator($keys, $clock, 'wrong') : new AppleTokenValidator($keys, $clock, 'wrong');
            try {
                $wrongAudience->validate($token, 'nonce');
                self::fail('Wrong audience accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            foreach (['malformed', $builder->expiresAt($clock->now()->modify('-1 second'))->getToken($config->signer(), $config->signingKey())->toString()] as $invalid) {
                try {
                    $validator->validate($invalid, 'nonce');
                    self::fail('Invalid token accepted');
                } catch (\InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
