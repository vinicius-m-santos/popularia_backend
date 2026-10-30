<?php

declare(strict_types=1);

namespace App\Sharing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AssociationController
{
    public function __construct(
        #[Autowire('%env(LINKS_APPLE_TEAM_ID)%')] private string $teamId,
        #[Autowire('%env(LINKS_ANDROID_SHA256)%')] private string $fingerprints,
    ) {
    }

    #[Route('/.well-known/apple-app-site-association', name: 'links_aasa', methods: ['GET', 'HEAD'])]
    public function apple(): JsonResponse
    {
        $details = [];
        if (preg_match('/^[A-Z0-9]{10}$/D', $this->teamId)) {
            $details[] = ['appIDs' => [$this->teamId.'.com.popularia.mobile'], 'components' => array_map(static fn (string $path): array => ['/' => $path], ['/p/*', '/u/*', '/c/*', '/d/*', '/e/*', '/t/*'])];
        }

        return $this->json(['applinks' => ['apps' => [], 'details' => $details]]);
    }

    #[Route('/.well-known/assetlinks.json', name: 'links_android', methods: ['GET', 'HEAD'])]
    public function android(): JsonResponse
    {
        $fingerprints = array_values(array_filter(array_map('trim', explode(',', strtoupper($this->fingerprints))), static fn (string $fingerprint): bool => 1 === preg_match('/^(?:[0-9A-F]{2}:){31}[0-9A-F]{2}$/D', $fingerprint)));

        return $this->json([] === $fingerprints ? [] : [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => 'com.popularia.mobile', 'sha256_cert_fingerprints' => $fingerprints],
        ]]);
    }

    /** @param array<mixed> $data */
    private function json(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'public, max-age=300');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
