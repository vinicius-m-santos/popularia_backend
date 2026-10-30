<?php

declare(strict_types=1);

namespace App\Api\Post;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Content\ContentPolicy;
use App\Gamification\GamificationAction;
use App\Gamification\GamificationService;
use App\Repository\SavedPostRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Salvar não cria notificação, contador público nem cópia do conteúdo.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class SavedPostProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ContentPolicy $policy,
        private SavedPostRepository $saves,
        private ClockInterface $clock,
        private EntityManagerInterface $em,
        private GamificationService $gamification,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $actor = $this->currentUser->get();
        $id = (string) ($uriVariables['id'] ?? '');
        if ($operation instanceof Delete) {
            $this->saves->unsave($actor, $id);

            return null;
        }
        $post = $this->policy->findVisiblePost($id, $actor);
        $now = $this->clock->now();
        $this->em->getConnection()->transactional(function () use ($actor, $post, $now): void {
            $this->gamification->prepare($actor, $now);
            if ($this->saves->save($actor, $post, $now)) {
                $this->gamification->record($actor, GamificationAction::PostSaved, $post->getId(), $now);
            }
        });

        return null;
    }
}
