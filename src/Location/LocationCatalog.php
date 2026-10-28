<?php
declare(strict_types=1);
namespace App\Location;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Catálogo oficial do IBGE, cacheado para não transformar o cadastro em dependência de cada toque. */
final readonly class LocationCatalog
{
    private const string BASE_URL = 'https://servicodados.ibge.gov.br/api/v1/localidades';
    private const int TTL = 604800;
    public function __construct(private HttpClientInterface $http, private CacheInterface $cache) {}

    /** @return list<array{id: string, name: string, abbreviation: string}> */
    public function states(): array
    {
        return $this->cache->get('locations.br.states.v1', function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);
            $rows = $this->http->request('GET', self::BASE_URL.'/estados?orderBy=nome', ['timeout' => 10])->toArray();
            return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'name' => (string) $row['nome'], 'abbreviation' => (string) ($row['sigla'] ?? '')], $rows);
        });
    }

    /** @return list<array{id: string, name: string}> */
    public function municipalities(string $stateId): array
    {
        if (!preg_match('/^\d{1,3}$/', $stateId)) throw new \InvalidArgumentException('UF inválida.');
        return $this->cache->get('locations.br.state.'.$stateId.'.municipalities.v1', function (ItemInterface $item) use ($stateId): array {
            $item->expiresAfter(self::TTL);
            $rows = $this->http->request('GET', self::BASE_URL.'/estados/'.rawurlencode($stateId).'/municipios?orderBy=nome', ['timeout' => 10])->toArray();
            return array_map(static fn (array $row): array => ['id' => (string) $row['id'], 'name' => (string) $row['nome']], $rows);
        });
    }

    public function hasMunicipality(string $stateId, string $municipalityId): bool
    {
        foreach ($this->municipalities($stateId) as $municipality) if ($municipality['id'] === $municipalityId) return true;
        return false;
    }
}
