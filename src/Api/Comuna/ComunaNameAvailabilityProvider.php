<?php

declare(strict_types=1);

namespace App\Api\Comuna;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Content\ComunaSlug;
use App\Entity\Comuna;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use App\Security\RateLimitGuard;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProviderInterface<ComunaNameAvailability> */
final readonly class ComunaNameAvailabilityProvider implements ProviderInterface
{
    public function __construct(
        private ComunaRepository $comunas,
        private CurrentUser $currentUser,
        private RateLimitGuard $rateLimit,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ComunaNameAvailability
    {
        $this->rateLimit->consume('comuna_name_check', $this->currentUser->get()->getUserIdentifier());
        $request = $context['request'] ?? null;
        $name = ComunaSlug::normalizeName(mb_substr((string) ($request instanceof Request ? $request->query->get('name', '') : ''), 0, 200));
        $slug = ComunaSlug::fromName($name);

        $message = match (true) {
            mb_strlen($name) < Comuna::NAME_MIN_LENGTH => \sprintf('Use pelo menos %d caracteres.', Comuna::NAME_MIN_LENGTH),
            mb_strlen($name) > Comuna::NAME_MAX_LENGTH => \sprintf('Use até %d caracteres.', Comuna::NAME_MAX_LENGTH),
            null !== ComunaSlug::problem($slug) => ComunaSlug::problem($slug),
            $this->comunas->slugExists($slug) => 'Já existe uma comuna com esse nome.',
            default => null,
        };

        return new ComunaNameAvailability($name, $slug, null === $message, $message);
    }
}
