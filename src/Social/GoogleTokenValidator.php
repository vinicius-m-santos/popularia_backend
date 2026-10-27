<?php

declare(strict_types=1);

namespace App\Social;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Valida ID tokens do Google conforme a especificação OIDC.
 *
 * Referência: https://developers.google.com/identity/openid-connect/openid-connect#validatinganidtoken
 *
 * Verificações:
 * - Assinatura RS256 com chave pública do Google (JWKS cacheado)
 * - Issuer: accounts.google.com ou https://accounts.google.com
 * - Audience: CLIENT_ID configurado no servidor (não o client ID mobile)
 * - exp: não expirado
 * - sub: presente e não vazio
 */
class GoogleTokenValidator implements ProviderTokenValidatorInterface
{
    private const string JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const array VALID_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(
        private readonly JwksClient $jwks,
        private readonly ClockInterface $clock,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        private readonly string $googleClientId,
    ) {
    }

    /**
     * Valida o ID token do Google e retorna as claims.
     *
     * @throws \InvalidArgumentException quando o token for inválido
     */
    public function validate(#[\SensitiveParameter] string $idToken, #[\SensitiveParameter] ?string $nonce = null): ProviderClaims
    {
        // Parse sem verificação primeiro para extrair o kid do header.
        $raw = Configuration::forUnsecuredSigner()->parser()->parse($idToken);
        if (!$raw instanceof Plain) {
            throw new \InvalidArgumentException('Google ID token malformado.');
        }

        $kid = $raw->headers()->get('kid');
        if (!\is_string($kid) || '' === $kid) {
            throw new \InvalidArgumentException('Google ID token sem kid no header.');
        }

        $key = $this->jwks->getKey(self::JWKS_URL, $kid);
        if (null === $key) {
            throw new \InvalidArgumentException('Chave pública Google não encontrada para kid: '.$kid);
        }

        $config = Configuration::forAsymmetricSigner(new Sha256(), $key, $key);
        try {
            $token = $config->parser()->parse($idToken);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Google ID token inválido: '.$e->getMessage(), previous: $e);
        }

        if (!$token instanceof Plain) {
            throw new \InvalidArgumentException('Google ID token inválido.');
        }

        // Valida todas as constraints de uma vez: assinatura, issuer, audience, tempo.
        $issuer = (string) $token->claims()->get('iss', '');
        if (!\in_array($issuer, self::VALID_ISSUERS, true)) {
            throw new \InvalidArgumentException('Google ID token: issuer inválido.');
        }

        $constraints = [
            new SignedWith(new Sha256(), $key),
            new PermittedFor($this->googleClientId),
            new StrictValidAt($this->clock),
        ];

        $violations = $config->validator()->validate($token, ...$constraints);
        if ($violations) {
            throw new \InvalidArgumentException('Google ID token falhou na validação: '.implode(', ', array_map(fn ($v) => $v->getMessage(), iterator_to_array($violations->getIterator()))));
        }

        $subject = (string) $token->claims()->get('sub', '');
        if ('' === $subject) {
            throw new \InvalidArgumentException('Google ID token sem claim sub.');
        }

        $email = $token->claims()->get('email');
        $emailVerified = (bool) $token->claims()->get('email_verified', false);
        $name = $token->claims()->get('name');

        return new ProviderClaims(
            subject: $subject,
            email: \is_string($email) && '' !== $email ? mb_strtolower(trim($email)) : null,
            emailVerified: $emailVerified,
            name: \is_string($name) && '' !== $name ? trim($name) : null,
        );
    }
}
