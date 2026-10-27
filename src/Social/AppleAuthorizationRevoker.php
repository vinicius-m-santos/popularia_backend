<?php

declare(strict_types=1);

namespace App\Social;

use App\Api\ApiProblem;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** TN3194: troca código novo, confere sub e revoga refresh token; nunca persiste tokens Apple. */
class AppleAuthorizationRevoker
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ClockInterface $clock,
        #[Autowire('%env(APPLE_BUNDLE_ID)%')] private readonly string $clientId,
        #[Autowire('%env(default:app.empty_string:APPLE_TEAM_ID)%')] private readonly string $teamId,
        #[Autowire('%env(default:app.empty_string:APPLE_KEY_ID)%')] private readonly string $keyId,
        #[Autowire('%env(default:app.empty_string:APPLE_PRIVATE_KEY)%')] private readonly string $privateKey,
    ) {
    }

    public function revokeCode(#[\SensitiveParameter] string $code, string $subject): void
    {
        if ('' === $code) {
            throw ApiProblem::badRequest('/errors/apple-reauth-required', 'Confirme novamente com Apple para excluir a conta.');
        }
        if ('' === $this->clientId || '' === $this->teamId || '' === $this->keyId || '' === $this->privateKey) {
            throw ApiProblem::serviceUnavailable();
        }
        try {
            $config = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText(str_replace('\\n', "\n", $this->privateKey)), InMemory::plainText($this->privateKey));
            $now = $this->clock->now();
            $secret = $config->builder()->withHeader('kid', $this->keyId)->issuedBy($this->teamId)->issuedAt($now)->expiresAt($now->modify('+5 minutes'))->permittedFor('https://appleid.apple.com')->relatedTo($this->clientId)->getToken($config->signer(), $config->signingKey())->toString();
            $base = ['client_id' => $this->clientId, 'client_secret' => $secret];
            $response = $this->http->request('POST', 'https://appleid.apple.com/auth/token', ['body' => $base + ['code' => $code, 'grant_type' => 'authorization_code'], 'timeout' => 15])->toArray();
            $identityToken = $response['id_token'] ?? null;
            if (!is_string($identityToken) || '' === $identityToken) {
                throw new \RuntimeException('Missing Apple token');
            }
            $token = $config->parser()->parse($identityToken);
            // O ID token separado já foi validado criptograficamente pelo processor. Este veio diretamente da Apple por TLS.
            if (!$token instanceof Plain || $subject !== $token->claims()->get('sub') || !is_string($response['refresh_token'] ?? null)) {
                throw new \RuntimeException('Unexpected Apple response');
            }
            $status = $this->http->request('POST', 'https://appleid.apple.com/auth/revoke', ['body' => $base + ['token' => $response['refresh_token'], 'token_type_hint' => 'refresh_token'], 'timeout' => 15])->getStatusCode();
            if (200 !== $status) {
                throw new \RuntimeException('Apple revocation failed');
            }
        } catch (\Throwable) {
            // Não propagar respostas OAuth, códigos, chaves ou client_secret para logs/Sentry.
            throw ApiProblem::serviceUnavailable();
        }
    }
}
