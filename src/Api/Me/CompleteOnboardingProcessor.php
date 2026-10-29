<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\ApiProblem;
use App\Location\LocationCatalog;
use App\Repository\UserFollowRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/** @implements ProcessorInterface<CompleteOnboarding, Me> */
final readonly class CompleteOnboardingProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LocationCatalog $locations,
        private UserFollowRepository $follows,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        $user = $this->currentUser->get();

        if (!$user->isPendingOnboarding()) {
            throw ApiProblem::forbidden('/errors/onboarding-already-complete', 'O onboarding já foi concluído.');
        }
        if ('BR' === strtoupper((string) ($data->countryCode ?? 'BR')) && (null !== $data->stateIbgeCode || null !== $data->municipalityIbgeCode) && (null === $data->stateIbgeCode || null === $data->municipalityIbgeCode || !$this->locations->hasMunicipality($data->stateIbgeCode, $data->municipalityIbgeCode))) {
            throw ApiProblem::badRequest('/errors/invalid-location', 'Escolha uma cidade válida para o estado selecionado.');
        }

        if ($this->users->handleExists($data->handle) && $user->getHandle() !== \App\Entity\User::normalizeHandle($data->handle)) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('Esse nome já está em uso.', null, [], $data, 'handle', $data->handle)]));
        }

        $now = $this->clock->now();
        $user->completeOnboarding($data->handle, $data->displayName, $now);
        $user->updateProfile(null, $data->bio, $data->city, $data->interests, $now, null, $data->state, $data->country, $data->countryCode, $data->stateIbgeCode, $data->municipalityIbgeCode);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'uniq_app_user_handle')) {
                throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('Esse nome já está em uso.', null, [], $data, 'handle', $data->handle)]));
            }
            throw $e;
        }

        $counts = $this->follows->socialCounts($user);

        return Me::fromUser($user, $counts['followers'], $counts['following']);
    }
}
