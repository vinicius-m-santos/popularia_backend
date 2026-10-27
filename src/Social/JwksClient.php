<?php

declare(strict_types=1);

namespace App\Social;

use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Busca e armazena em cache o JWKS (JSON Web Key Set) de um provider OIDC.
 * As chaves são cacheadas por 1 hora, tempo razoável para rotação sem impacto em produção.
 */
final class JwksClient
{
    private const int CACHE_TTL = 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Retorna a chave pública para o `kid` dado, ou null se não encontrada.
     * Tenta rebuscar o JWKS uma vez se a chave não estiver no cache (rotação do provider).
     */
    public function getKey(string $jwksUrl, string $kid): ?InMemory
    {
        $keys = $this->fetchKeys($jwksUrl);
        if (isset($keys[$kid])) {
            return $keys[$kid];
        }

        // Kid não encontrado: invalida o cache e tenta uma vez mais.
        $this->cache->delete($this->cacheKey($jwksUrl));
        $keys = $this->fetchKeys($jwksUrl);

        return $keys[$kid] ?? null;
    }

    /** @return array<string, InMemory> keyed by kid */
    private function fetchKeys(string $jwksUrl): array
    {
        return $this->cache->get($this->cacheKey($jwksUrl), function (ItemInterface $item) use ($jwksUrl): array {
            $item->expiresAfter(self::CACHE_TTL);
            $response = $this->httpClient->request('GET', $jwksUrl, ['timeout' => 10]);
            $data = $response->toArray();

            $keys = [];
            foreach ($data['keys'] ?? [] as $jwk) {
                if (($jwk['use'] ?? 'sig') !== 'sig' || !isset($jwk['kid'], $jwk['kty'])) {
                    continue;
                }
                $key = $this->buildKey($jwk);
                if (null !== $key) {
                    $keys[$jwk['kid']] = $key;
                }
            }

            return $keys;
        });
    }

    /** @param array<string, mixed> $jwk */
    private function buildKey(array $jwk): ?InMemory
    {
        try {
            if ('RSA' === $jwk['kty']) {
                $pem = $this->rsaJwkToPem($jwk);

                return '' === $pem ? null : InMemory::plainText($pem);
            }
            if ('EC' === $jwk['kty']) {
                $pem = $this->ecJwkToPem($jwk);

                return '' === $pem ? null : InMemory::plainText($pem);
            }
        } catch (\Throwable) {
            // JWK malformado: ignorar esta chave.
        }

        return null;
    }

    /** @param array<string, mixed> $jwk */
    private function rsaJwkToPem(array $jwk): string
    {
        $n = $this->base64UrlDecode((string) $jwk['n']);
        $e = $this->base64UrlDecode((string) $jwk['e']);

        $key = openssl_pkey_get_public($this->rsaToPem($n, $e));

        if (false === $key) {
            throw new \RuntimeException('Falha ao construir chave RSA do JWK.');
        }

        $details = openssl_pkey_get_details($key);

        return (string) ($details['key'] ?? '');
    }

    private function rsaToPem(string $n, string $e): string
    {
        $der = $this->rsaToDer($n, $e);
        $b64 = base64_encode($der);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($b64, 64, "\n")."-----END PUBLIC KEY-----\n";

        return $pem;
    }

    private function rsaToDer(string $n, string $e): string
    {
        // DER encoding de RSAPublicKey e SubjectPublicKeyInfo.
        $modulus = "\x00".$n;
        $exponent = ltrim($e, "\x00");

        $rsaKey = self::derSeq(self::derInt($modulus).self::derInt($exponent));

        // SubjectPublicKeyInfo: AlgorithmIdentifier + BIT STRING
        $algoId = self::derSeq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $bitString = "\x03".self::derLen(\strlen($rsaKey) + 1)."\x00".$rsaKey;

        return self::derSeq($algoId.$bitString);
    }

    private static function derLen(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = '';
        $tmp = $len;
        while ($tmp > 0) {
            $bytes = chr($tmp & 0xFF).$bytes;
            $tmp >>= 8;
        }

        return chr(0x80 | \strlen($bytes)).$bytes;
    }

    private static function derSeq(string $content): string
    {
        return "\x30".self::derLen(\strlen($content)).$content;
    }

    private static function derInt(string $content): string
    {
        return "\x02".self::derLen(\strlen($content)).$content;
    }

    /** @param array<string, mixed> $jwk */
    private function ecJwkToPem(array $jwk): string
    {
        // EC keys para Apple (ES256, P-256).
        $x = $this->base64UrlDecode((string) $jwk['x']);
        $y = $this->base64UrlDecode((string) $jwk['y']);

        // P-256 OID: 1.2.840.10045.3.1.7
        $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        // ecPublicKey OID: 1.2.840.10045.2.1
        $algoOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        $algoId = self::derSeq($algoOid.$oid);
        $point = "\x04".str_pad($x, 32, "\x00", \STR_PAD_LEFT).str_pad($y, 32, "\x00", \STR_PAD_LEFT);
        $bitString = "\x03".self::derLen(\strlen($point) + 1)."\x00".$point;

        $der = self::derSeq($algoId.$bitString);
        $b64 = base64_encode($der);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split($b64, 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function base64UrlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int) (ceil(\strlen($data) / 4) * 4), '=');

        return (string) base64_decode($padded, true);
    }

    private function cacheKey(string $url): string
    {
        return 'jwks.'.hash('sha256', $url);
    }
}
