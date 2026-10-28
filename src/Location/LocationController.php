<?php
declare(strict_types=1);
namespace App\Location;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/locations/br')]
final readonly class LocationController
{
    public function __construct(private LocationCatalog $catalog) {}
    #[Route('/states', methods: ['GET'])]
    public function states(): JsonResponse { return new JsonResponse(['items' => $this->catalog->states()]); }
    #[Route('/states/{stateId}/municipalities', requirements: ['stateId' => '\\d{1,3}'], methods: ['GET'])]
    public function municipalities(string $stateId): JsonResponse { return new JsonResponse(['items' => $this->catalog->municipalities($stateId)]); }
}
