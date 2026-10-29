<?php

declare(strict_types=1);

namespace App\Api\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Location\LocationCatalog;
use App\Repository\UserFollowRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<MeUpdate, Me> */
final readonly class MeUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private UserRepository $users,
        private LocationCatalog $locations,
        private UserFollowRepository $follows,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Me
    {
        try {
            return $this->em->getConnection()->transactional(function () use ($data): Me {
                $user = $this->currentUser->get();
                if ('BR' === strtoupper((string) ($data->countryCode ?? $user->getCountryCode())) && (null !== $data->stateIbgeCode || null !== $data->municipalityIbgeCode) && (null === $data->stateIbgeCode || null === $data->municipalityIbgeCode || !$this->locations->hasMunicipality($data->stateIbgeCode, $data->municipalityIbgeCode))) {
                    throw ApiProblem::badRequest('/errors/invalid-location', 'Escolha uma cidade válida para o estado selecionado.');
                }
                if (null !== $data->handle && null !== ($other = $this->users->findOneBy(['handle' => strtolower(trim($data->handle))])) && $other->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
                    throw ApiProblem::conflict('/errors/handle-taken', 'Esse nome de usuário já está em uso.');
                }
                $this->em->refresh($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $user->updateProfile($data->displayName, $data->bio, $data->city, $data->interests, $this->clock->now(), $data->handle, $data->state, $data->country, $data->countryCode, $data->stateIbgeCode, $data->municipalityIbgeCode);
                if (null !== $data->isPrivate) {
                    $user->setPrivate($data->isPrivate, $this->clock->now());
                    if (!$data->isPrivate) {
                        $this->em->getConnection()->executeStatement('UPDATE user_follow SET approved = TRUE WHERE followed_id = ?', [$user->getId()->toRfc4122()]);
                    }
                }
                $this->em->flush();

                $counts = $this->follows->socialCounts($user);

                return Me::fromUser($user, $counts['followers'], $counts['following']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::conflict('/errors/handle-taken', 'Esse nome de usuário já está em uso.');
        }
    }
}
