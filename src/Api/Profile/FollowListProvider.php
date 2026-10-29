<?php

declare(strict_types=1);

namespace App\Api\Profile;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Entity\User;
use App\Entity\UserFollow;
use App\Repository\UserBlockRepository;
use App\Repository\UserFollowRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;

/** @implements ProviderInterface<FollowListPage> */
final readonly class FollowListProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private UserFollowRepository $follows,
        private UserBlockRepository $blocks,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FollowListPage
    {
        $viewer = $this->currentUser->get();
        $subject = $this->users->findByHandleOrId((string) ($uriVariables['handle'] ?? ''));
        if (null === $subject || !$subject->isActive() || $this->blocks->isBlockedEitherWay($viewer, $subject)) {
            throw ApiProblem::notFound('Perfil não encontrado.');
        }
        $this->assertCanRead($viewer, $subject);

        $followers = 'user_followers' === $operation->getName();
        $page = Pagination::fromContext($context);
        /** @var list<UserFollow> $rows */
        $rows = $this->follows->findRelationPage($subject, $viewer, $followers, $page->cursor, $page->limit);
        [$rows, $next] = $page->slice($rows, static fn (UserFollow $follow) => $follow->getId());
        $people = array_map(static fn (UserFollow $follow) => $followers ? $follow->getFollower() : $follow->getFollowed(), $rows);
        $relations = $this->follows->relationsTo($viewer, $people);

        return new FollowListPage(array_map(static function (User $person) use ($relations): FollowListPerson {
            $state = $relations[$person->getId()->toRfc4122()] ?? null;

            return FollowListPerson::fromUser($person, 'following' === $state, 'pending' === $state);
        }, $people), $next);
    }

    /**
     * Listas de perfil público são de quem está autenticado e não tem bloqueio.
     * Listas de perfil privado ficam com a própria pessoa e com seguidores aprovados.
     * Pedido pendente não abre a lista.
     */
    private function assertCanRead(User $viewer, User $subject): void
    {
        if ($viewer->getId()->equals($subject->getId()) || !$subject->isPrivate()) {
            return;
        }
        if ($this->follows->findFollow($viewer, $subject)?->isApproved() ?? false) {
            return;
        }

        throw ApiProblem::forbidden('/errors/follow-list-private', 'Esta lista é privada.', 'Só a própria pessoa e seguidores aprovados veem seguidores e seguindo deste perfil.');
    }
}
