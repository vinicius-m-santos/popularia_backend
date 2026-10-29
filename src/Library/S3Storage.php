<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** S3 REST/SigV4, PUT/GET/DELETE privados + URLs assinadas. Nunca entrega credenciais ao cliente. */
final readonly class S3Storage implements ObjectStorage
{
    private const int MAX_SIGNED_URL_TTL = 86400;

    public function __construct(
        #[Autowire('%env(LIBRARY_S3_ENDPOINT)%')] private string $endpoint,
        #[Autowire('%env(LIBRARY_S3_BUCKET)%')] private string $bucket,
        #[Autowire('%env(LIBRARY_S3_REGION)%')] private string $region,
        #[Autowire('%env(LIBRARY_S3_ACCESS_KEY)%')] private string $accessKey,
        #[Autowire('%env(LIBRARY_S3_SECRET_KEY)%')] private string $secretKey,
        #[Autowire('%env(bool:LIBRARY_S3_PATH_STYLE)%')] private bool $pathStyle,
        #[Autowire('%env(string:LIBRARY_S3_ENVIRONMENT)%')] private string $s3Environment,
        #[Autowire('%kernel.environment%')] private string $appEnv,
        private ClockInterface $clock,
        private HttpClientInterface $http,
    ) {
    }

    public function put(string $key, string $path, string $checksum, string $contentType = 'application/pdf'): void
    {
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw ApiProblem::serviceUnavailable();
        }
        try {
            [$url, $headers] = $this->signedRequest('PUT', $key, $checksum);
            $response = $this->http->request('PUT', $url, ['headers' => $headers + ['Content-Type' => $contentType, 'Content-Length' => (string) filesize($path)], 'body' => $stream, 'timeout' => 180]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw ApiProblem::serviceUnavailable();
            }
        } finally {
            fclose($stream);
        }
    }

    public function read(string $key): mixed
    {
        return $this->request('GET', $key, hash('sha256', ''));
    }

    public function signedUrl(string $key, int $ttl, string $contentType = 'application/pdf', string $disposition = 'attachment; filename="documento.pdf"'): string
    {
        $this->assertEnvironmentConsistency();
        if ('' === $this->bucket || '' === $this->accessKey || '' === $this->secretKey
            || 'https' !== parse_url($this->endpoint, PHP_URL_SCHEME)
            || 1 !== preg_match('/^[a-zA-Z0-9._\/-]+$/', $key)
            || $ttl < 1 || $ttl > self::MAX_SIGNED_URL_TTL) {
            throw ApiProblem::serviceUnavailable();
        }
        $host = ($this->pathStyle ? '' : $this->bucket.'.').(string) parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $host .= null === $port ? '' : ':'.$port;
        $path = ($this->pathStyle ? '/'.rawurlencode($this->bucket) : '').'/'.implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $date = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        $scope = "$shortDate/{$this->region}/s3/aws4_request";
        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->accessKey}/$scope",
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $ttl,
            'X-Amz-SignedHeaders' => 'host',
            'response-content-disposition' => $disposition,
            'response-content-type' => $contentType,
        ];
        ksort($params, SORT_STRING);
        $queryString = implode('&', array_map(
            fn (string $k, string $v): string => rawurlencode($k).'='.rawurlencode($v),
            array_keys($params), array_values($params),
        ));
        $canonical = "GET\n$path\n$queryString\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
        $toSign = "AWS4-HMAC-SHA256\n$date\n$scope\n".hash('sha256', $canonical);
        $signingKey = 'AWS4'.$this->secretKey;
        foreach ([$shortDate, $this->region, 's3', 'aws4_request'] as $part) {
            $signingKey = hash_hmac('sha256', $part, $signingKey, true);
        }

        return 'https://'.$host.$path.'?'.$queryString.'&X-Amz-Signature='.rawurlencode(hash_hmac('sha256', $toSign, $signingKey));
    }

    public function delete(string $key): void
    {
        fclose($this->request('DELETE', $key, hash('sha256', '')));
    }

    private function assertEnvironmentConsistency(): void
    {
        if ('prod' === $this->appEnv && '' !== $this->s3Environment && 'production' !== $this->s3Environment) {
            throw new \RuntimeException("Configuração de storage incompatível: APP_ENV=prod mas LIBRARY_S3_ENVIRONMENT={$this->s3Environment}.");
        }
        if ('prod' !== $this->appEnv && 'production' === $this->s3Environment) {
            throw new \RuntimeException('LIBRARY_S3_ENVIRONMENT=production não é permitido fora de APP_ENV=prod.');
        }
    }

    /** @return resource */
    private function request(string $method, string $key, string $hash, string $body = ''): mixed
    {
        [$url, $signedHeaders] = $this->signedRequest($method, $key, $hash);
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", array_map(static fn (string $name, string $value): string => "$name: $value", array_keys($signedHeaders), array_values($signedHeaders)))."\r\n",
            'content' => $body,
            'timeout' => 90,
            'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen($url, 'rb', false, $context);
        if (false === $stream) {
            throw ApiProblem::serviceUnavailable();
        }

        return $stream;
    }

    /** @return array{string, array<string, string>} */
    private function signedRequest(string $method, string $key, string $hash): array
    {
        $this->assertEnvironmentConsistency();
        if ('' === $this->bucket || '' === $this->accessKey || '' === $this->secretKey || 'https' !== parse_url($this->endpoint, PHP_URL_SCHEME) || 1 !== preg_match('/^[a-zA-Z0-9._\/-]+$/', $key)) {
            throw ApiProblem::serviceUnavailable();
        }
        $host = ($this->pathStyle ? '' : $this->bucket.'.').(string) parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $host .= null === $port ? '' : ':'.$port;
        $path = ($this->pathStyle ? '/'.rawurlencode($this->bucket) : '').'/'.implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $date = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        $canonicalHeaders = "host:$host\nx-amz-content-sha256:$hash\nx-amz-date:$date\n";
        $signed = 'host;x-amz-content-sha256;x-amz-date';
        $scope = "$shortDate/{$this->region}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n$date\n$scope\n".hash('sha256', "$method\n$path\n\n$canonicalHeaders\n$signed\n$hash");
        $signingKey = 'AWS4'.$this->secretKey;
        foreach ([$shortDate, $this->region, 's3', 'aws4_request'] as $part) {
            $signingKey = hash_hmac('sha256', $part, $signingKey, true);
        }
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signed, Signature=".hash_hmac('sha256', $toSign, $signingKey);

        return ['https://'.$host.$path, ['Host' => $host, 'X-Amz-Date' => $date, 'X-Amz-Content-Sha256' => $hash, 'Authorization' => $authorization]];
    }
}
