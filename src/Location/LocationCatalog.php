<?php

declare(strict_types=1);

namespace App\Location;

/**
 * Catálogo oficial do IBGE embarcado no backend.
 *
 * A API do IBGE não é chamada em produção: o Railway não alcança
 * servicodados.ibge.gov.br de forma confiável e o cadastro não pode depender
 * desse tempo de rede. O arquivo resources/locations/br.json é gerado a partir
 * dos endpoints públicos /estados e /estados/{id}/municipios (dados públicos).
 */
final class LocationCatalog
{
    private ?array $data = null;

    /** @return list<array{id: string, name: string, abbreviation: string}> */
    public function states(): array
    {
        return $this->data()['states'];
    }

    /** @return list<array{id: string, name: string}> */
    public function municipalities(string $stateId): array
    {
        if (!preg_match('/^\d{1,3}$/', $stateId)) {
            throw new \InvalidArgumentException('UF inválida.');
        }

        return $this->data()['municipalities'][$stateId] ?? [];
    }

    public function hasMunicipality(string $stateId, string $municipalityId): bool
    {
        foreach ($this->municipalities($stateId) as $municipality) {
            if ($municipality['id'] === $municipalityId) {
                return true;
            }
        }

        return false;
    }

    /** @return array{states: list<array{id: string, name: string, abbreviation: string}>, municipalities: array<string, list<array{id: string, name: string}>>} */
    private function data(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }

        $path = \dirname(__DIR__, 2).'/resources/locations/br.json';
        $raw = file_get_contents($path);
        if (false === $raw) {
            throw new \RuntimeException('Catálogo de localidades indisponível.');
        }
        /** @var array{states: list<array{id: string, name: string, abbreviation: string}>, municipalities: array<string, list<array{id: string, name: string}>>} $data */
        $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $this->data = $data;

        return $this->data;
    }
}
