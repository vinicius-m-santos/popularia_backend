<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** S3 REST/SigV4, somente PUT/GET/DELETE privados. Nunca entrega credenciais ou URLs ao cliente. */
final readonly class S3Storage implements ObjectStorage
{
    public function __construct(
        #[Autowire('%env(LIBRARY_S3_ENDPOINT)%')] private string $endpoint,
        #[Autowire('%env(LIBRARY_S3_BUCKET)%')] private string $bucket,
        #[Autowire('%env(LIBRARY_S3_REGION)%')] private string $region,
        #[Autowire('%env(LIBRARY_S3_ACCESS_KEY)%')] private string $accessKey,
        #[Autowire('%env(LIBRARY_S3_SECRET_KEY)%')] private string $secretKey,
        #[Autowire('%env(bool:LIBRARY_S3_PATH_STYLE)%')] private bool $pathStyle,
        private ClockInterface $clock,
    ) {
    }

    public function put(string $key, string $path, string $checksum): void
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw ApiProblem::serviceUnavailable();
        }
        fclose($this->request('PUT', $key, $checksum, $content));
    }

    public function read(string $key): mixed
    {
        return $this->request('GET', $key, hash('sha256', ''));
    }

    public function delete(string $key): void
    {
        fclose($this->request('DELETE', $key, hash('sha256', '')));
    }

    /** @return resource */
    private function request(string $method, string $key, string $hash, string $body = ''): mixed
    {
        if ('' === $this->bucket || '' === $this->accessKey || '' === $this->secretKey
            || 'https' !== parse_url($this->endpoint, PHP_URL_SCHEME)
            || 1 !== preg_match('/^[a-zA-Z0-9._\/-]+$/', $key)) {
            throw ApiProblem::serviceUnavailable();
        }
        $host = ($this->pathStyle ? '' : $this->bucket.'.').(string) parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        $host .= null === $port ? '' : ':'.$port;
        $path = ($this->pathStyle ? '/'.rawurlencode($this->bucket) : '').'/'.implode('/', array_map(rawurlencode(...), explode('/', $key)));
        $date = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $shortDate = substr($date, 0, 8);
        $headers = "host:$host\nx-amz-content-sha256:$hash\nx-amz-date:$date\n";
        $signed = 'host;x-amz-content-sha256;x-amz-date';
        $canonical = "$method\n$path\n\n$headers\n$signed\n$hash";
        $scope = "$shortDate/{$this->region}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n$date\n$scope\n".hash('sha256', $canonical);
        $signingKey = 'AWS4'.$this->secretKey;
        foreach ([$shortDate, $this->region, 's3', 'aws4_request'] as $part) {
            $signingKey = hash_hmac('sha256', $part, $signingKey, true);
        }
        $signature = hash_hmac('sha256', $toSign, $signingKey);
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signed, Signature=$signature";
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Host: $host\r\nX-Amz-Date: $date\r\nX-Amz-Content-Sha256: $hash\r\nAuthorization: $authorization\r\nContent-Type: application/pdf\r\n",
            'content' => $body,
            'timeout' => 90,
            'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen('https://'.$host.$path, 'rb', false, $context);
        if (false === $stream) {
            throw ApiProblem::serviceUnavailable();
        }

        return $stream;
    }
}
