<?php

declare(strict_types=1);

namespace App\Notification;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** HTTP errors are classified without retaining response bodies or tokens in exceptions/logs. */
final readonly class ExpoPushProvider
{
    public function __construct(private HttpClientInterface $http, #[Autowire('%env(default:app.empty_string:EXPO_PUSH_ACCESS_TOKEN)%')] private string $accessToken)
    {
    }

    /** @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public function send(array $messages): array
    {
        $data = $this->request('send', $messages);
        if (!array_is_list($data) || count($data) !== count($messages)) {
            throw new \RuntimeException('InvalidProviderResponse');
        }

        foreach ($data as $ticket) {
            if (!is_array($ticket) || !in_array($ticket['status'] ?? null, ['ok', 'error'], true)) {
                throw new \RuntimeException('InvalidProviderResponse');
            }
        }

        return $data;
    }

    /** @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function receipts(array $ids): array
    {
        $data = $this->request('getReceipts', ['ids' => $ids]);
        foreach ($data as $receipt) {
            if (!is_array($receipt) || !in_array($receipt['status'] ?? null, ['ok', 'error'], true)) {
                throw new \RuntimeException('InvalidProviderResponse');
            }
        }

        return $data;
    }

    /** @param array<mixed> $payload
     * @return array<mixed>
     */
    private function request(string $endpoint, array $payload): array
    {
        try {
            $response = $this->http->request('POST', 'https://exp.host/--/api/v2/push/'.$endpoint, [
                'headers' => '' === $this->accessToken ? [] : ['Authorization' => 'Bearer '.$this->accessToken],
                'json' => $payload, 'timeout' => 10, 'max_duration' => 15,
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw new \RuntimeException(in_array($status, [400, 401, 403], true) ? 'ProviderConfiguration' : 'ProviderUnavailable');
            }
            $body = $response->toArray(false);
            if (!isset($body['data']) || !is_array($body['data'])) {
                throw new \RuntimeException('InvalidProviderResponse');
            }

            return $body['data'];
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
            throw new \RuntimeException('ProviderUnavailable');
        }
    }
}
