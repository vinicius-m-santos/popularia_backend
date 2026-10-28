<?php

declare(strict_types=1);

namespace App\Location;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/locations')]
final readonly class LocationController
{
    public function __construct(private LocationCatalog $catalog)
    {
    }

    #[Route('/br/states', methods: ['GET'])]
    public function states(): JsonResponse
    {
        return new JsonResponse(['items' => $this->catalog->states()]);
    }

    #[Route('/br/states/{stateId}/municipalities', requirements: ['stateId' => '\\d{1,3}'], methods: ['GET'])]
    public function municipalities(string $stateId): JsonResponse
    {
        return new JsonResponse(['items' => $this->catalog->municipalities($stateId)]);
    }

    #[Route('/countries', methods: ['GET'])]
    public function countries(): JsonResponse
    {
        $items = [];
        foreach (Countries::getNames('pt_BR') as $code => $name) {
            $items[] = ['code' => $code, 'name' => $name];
        }

        return new JsonResponse(['items' => $items]);
    }
}
