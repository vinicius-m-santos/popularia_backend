<?php

declare(strict_types=1);

namespace App\Social;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Valida identity tokens do Sign in with Apple conforme a documentação oficial.
 *
 * Referência: https://developer.apple.com/documentation/sign_in_with_apple/sign_in_with_apple_rest_api/verifying_a_user
 *
 * Verificações:
 * - Assinatura RS256 com chave pública da Apple (JWKS cacheado)
 * - Issuer: https://appleid.apple.com
 * - Audience: bundle identifier do app iOS
 * - exp: não expirado
 * - nonce: quando presente, sha256(rawNonce) deve bater com o claim
 * - sub: presente e não vazio
 *
 * E-mail da Apple:
 * - Pode ser relay (`xxxxx@privaterelay.appleid.com`) — isso é válido e esperado.
 * - Não disponível em logins após o primeiro quando o usuário usou Hide My Email.
 * - Identificador permanente é SEMPRE o `sub`.
 */
class AppleTokenValidator implements ProviderTokenValidatorInterface
{
    private const string JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const string ISSUER = 'https://appleid.apple.com';

    public function __construct(
        private readonly JwksClient $jwks,
        private readonly ClockInterface $clock,
        #[Autowire('%env(APPLE_BUNDLE_ID)%')]
        private readonly string $appleBundleId,
    ) {
    }

    /**
     * Valida o identity token da Apple e retorna as claims.
     *
     * @param string|null $rawNonce Nonce original (não hashado) gerado pelo mobile. Obrigatório.
     *
     * @throws \InvalidArgumentException quando o token for inválido
     */
    public function validate(#[\SensitiveParameter] string $identityToken, #[\SensitiveParameter] ?string $rawNonce = null): ProviderClaims
    {
        if (null === $rawNonce || '' === $rawNonce) {
            throw new \InvalidArgumentException('Nonce obrigatório para Sign in with Apple.');
        }

        // Parse inicial para extrair kid e alg.
        $raw = Configuration::forUnsecuredSigner()->parser()->parse($identityToken);
        if (!$raw instanceof Plain) {
            throw new \InvalidArgumentException('Apple identity token malformado.');
        }

        $kid = $raw->headers()->get('kid');
        if (!\is_string($kid) || '' === $kid) {
            throw new \InvalidArgumentException('Apple identity token sem kid no header.');
        }

        $alg = $raw->headers()->get('alg');
        $signer = match ($alg) {
            'ES256' => new Sha256(),
            default => new RsaSha256(),
        };

        $key = $this->jwks->getKey(self::JWKS_URL, $kid);
        if (null === $key) {
            throw new \InvalidArgumentException('Chave pública Apple não encontrada para kid: '.$kid);
        }

        $config = Configuration::forAsymmetricSigner($signer, $key, $key);
        try {
            $token = $config->parser()->parse($identityToken);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Apple identity token inválido: '.$e->getMessage(), previous: $e);
        }

        if (!$token instanceof Plain) {
            throw new \InvalidArgumentException('Apple identity token inválido.');
        }

        $issuer = (string) $token->claims()->get('iss', '');
        if (self::ISSUER !== $issuer) {
            throw new \InvalidArgumentException('Apple identity token: issuer inválido.');
        }

        $constraints = [
            new SignedWith($signer, $key),
            new PermittedFor($this->appleBundleId),
            new StrictValidAt($this->clock),
        ];

        $violations = $config->validator()->validate($token, ...$constraints);
        if ($violations) {
            throw new \InvalidArgumentException('Apple identity token falhou na validação.');
        }

        // Nonce: a Apple armazena sha256(rawNonce) no claim nonce_supported + nonce.
        $nonceSupported = (bool) $token->claims()->get('nonce_supported', false);
        if (!$nonceSupported) {
            throw new \InvalidArgumentException('Apple: nonce não suportado neste token.');
        }

        $tokenNonce = $token->claims()->get('nonce');
        $expectedNonce = hash('sha256', $rawNonce);
        if (!\is_string($tokenNonce) || !hash_equals($expectedNonce, $tokenNonce)) {
            throw new \InvalidArgumentException('Apple identity token: nonce inválido.');
        }

        $subject = (string) $token->claims()->get('sub', '');
        if ('' === $subject) {
            throw new \InvalidArgumentException('Apple identity token sem claim sub.');
        }

        $email = $token->claims()->get('email');
        $emailVerified = $token->claims()->get('email_verified');

        // Apple retorna "true" como string em alguns casos.
        $emailVerifiedBool = ('true' === $emailVerified || true === $emailVerified);

        return new ProviderClaims(
            subject: $subject,
            email: \is_string($email) && '' !== $email ? mb_strtolower(trim($email)) : null,
            emailVerified: $emailVerifiedBool,
            name: null, // Nome não está no identity token; o mobile deve enviar separado na 1ª auth.
        );
    }
}
