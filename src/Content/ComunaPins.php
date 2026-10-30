<?php

declare(strict_types=1);

namespace App\Content;

use App\Api\ApiProblem;
use App\Auth\AdminAudit;
use App\Entity\Comuna;
use App\Entity\ComunaPinnedPost;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\ComunaPinnedPostRepository;
use App\Repository\ComunaRepository;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Fixar uma publicação na comuna. A publicação continua sendo um post normal.
 *
 * Ordem: o pin novo entra na posição 1 e os demais descem. A posição é coluna,
 * não a data nem o UUID do post. Quem modera pode mover para outra posição.
 *
 * Concorrência: a comuna é travada (SELECT … FOR UPDATE), como nas outras mudanças
 * de comuna. O gatilho comuna_pin_limit repete a trava e recusa a 4ª linha se duas
 * transações passarem da conferência da aplicação. Não há notificação.
 */
final readonly class ComunaPins
{
    public function __construct(
        private EntityManagerInterface $em,
        private ComunaRepository $comunas,
        private ComunaPinnedPostRepository $pins,
        private ComunaModerationPolicy $policy,
        private AdminAudit $audit,
        private ClockInterface $clock,
    ) {
    }

    public function pin(Comuna $comuna, Post $post, User $actor): void
    {
        try {
            $this->transactional(function () use ($comuna, $post, $actor): void {
                $this->comunas->lock($comuna);
                $this->policy->assertModerator($comuna, $actor);
                $this->policy->assertNotArchived($comuna);
                $this->em->refresh($post);
                $postComuna = $post->getComuna();
                if (null === $postComuna || !$postComuna->getId()->equals($comuna->getId())) {
                    throw ApiProblem::conflict('/errors/post-not-in-comuna', 'Só dá para fixar uma publicação desta comuna.');
                }
                if (!$post->isPublished() || !$post->getAuthor()->isActive()) {
                    throw ApiProblem::notFound('Publicação não encontrada.');
                }
                $this->purgeIneligible($comuna);
                $current = $this->pins->ordered($comuna);
                foreach ($current as $pin) {
                    if ($pin->getPost()->getId()->equals($post->getId())) {
                        return;
                    }
                }
                if (\count($current) >= ComunaPinnedPost::MAX_PER_COMUNA) {
                    throw self::limitReached();
                }
                foreach (array_reverse($current) as $pin) {
                    $pin->moveTo($pin->getPosition() + 1);
                }
                $this->em->persist(new ComunaPinnedPost($comuna, $post, 1, $actor, $this->clock->now()));
                $this->em->flush();
                $this->record($actor, 'comuna.post_pinned', $comuna, $post, ['position' => 1]);
            });
        } catch (UniqueConstraintViolationException) {
            // Outra transação fixou o mesmo post entre a conferência e a gravação.
        } catch (DriverException $e) {
            if (str_contains($e->getMessage(), 'comuna_pin_limit_reached')) {
                throw self::limitReached();
            }
            throw $e;
        }
    }

    /** Idempotente: desafixar o que não está fixado não muda nada. */
    public function unpin(Comuna $comuna, string $postId, User $actor): void
    {
        $this->transactional(function () use ($comuna, $postId, $actor): void {
            $this->comunas->lock($comuna);
            $this->policy->assertModerator($comuna, $actor);
            $this->policy->assertNotArchived($comuna);
            $pin = $this->pins->findInComuna($comuna, $postId);
            if (null === $pin) {
                return;
            }
            $post = $pin->getPost();
            $this->em->remove($pin);
            $this->em->flush();
            $this->compact($comuna);
            $this->record($actor, 'comuna.post_unpinned', $comuna, $post, []);
        });
    }

    /** Move um pin já existente. A posição é limitada à quantidade atual. Repetir a mesma posição não muda nada. */
    public function move(Comuna $comuna, string $postId, User $actor, int $position): void
    {
        $this->transactional(function () use ($comuna, $postId, $actor, $position): void {
            $this->comunas->lock($comuna);
            $this->policy->assertModerator($comuna, $actor);
            $this->policy->assertNotArchived($comuna);
            $this->purgeIneligible($comuna);
            $pins = $this->pins->ordered($comuna);
            $current = null;
            $rest = [];
            foreach ($pins as $pin) {
                if ($pin->getPost()->getId()->toRfc4122() === $postId) {
                    $current = $pin;
                } else {
                    $rest[] = $pin;
                }
            }
            if (!$current instanceof ComunaPinnedPost) {
                throw ApiProblem::conflict('/errors/post-not-pinned', 'Esta publicação não está fixada.');
            }
            $from = $current->getPosition();
            $target = max(1, min($position, \count($pins)));
            array_splice($rest, $target - 1, 0, [$current]);
            foreach ($rest as $index => $pin) {
                $pin->moveTo($index + 1);
            }
            $this->em->flush();
            if ($from !== $target) {
                $this->record($actor, 'comuna.post_pin_moved', $comuna, $current->getPost(), ['from' => $from, 'position' => $target]);
            }
        });
    }

    /** Tira o pin quando a publicação deixa de existir como conteúdo publicado (exclusão ou moderação). */
    public function release(Post $post): void
    {
        $comuna = $post->getComuna();
        if (null === $comuna) {
            return;
        }
        $this->transactional(function () use ($comuna, $post): void {
            $this->comunas->lock($comuna);
            $pin = $this->pins->findInComuna($comuna, $post->getId()->toRfc4122());
            if (null === $pin) {
                return;
            }
            $this->em->remove($pin);
            $this->em->flush();
            $this->compact($comuna);
        });
    }

    /** @return list<Post> */
    public function visiblePosts(Comuna $comuna, ?User $viewer): array
    {
        if ($this->pins->hasIneligible($comuna)) {
            $this->transactional(function () use ($comuna): void {
                $this->comunas->lock($comuna);
                $this->purgeIneligible($comuna);
            });
        }

        return $this->pins->findVisible($comuna, $viewer);
    }

    private function purgeIneligible(Comuna $comuna): void
    {
        $kept = [];
        $removed = false;
        foreach ($this->pins->ordered($comuna) as $pin) {
            $post = $pin->getPost();
            $same = $post->getComuna()?->getId()->equals($comuna->getId()) ?? false;
            if ($same && $post->isPublished() && $post->getAuthor()->isActive()) {
                $kept[] = $pin;
            } else {
                $this->em->remove($pin);
                $removed = true;
            }
        }
        if (!$removed) {
            return;
        }
        foreach ($kept as $index => $pin) {
            $pin->moveTo($index + 1);
        }
        $this->em->flush();
    }

    private function compact(Comuna $comuna): void
    {
        foreach ($this->pins->ordered($comuna) as $index => $pin) {
            $pin->moveTo($index + 1);
        }
        $this->em->flush();
    }

    /** @param array<string, scalar|null> $details */
    private function record(User $actor, string $action, Comuna $comuna, Post $post, array $details): void
    {
        $this->audit->record($actor, $action, $post->getAuthor()->getId(), '', [
            'comuna_id' => $comuna->getId()->toRfc4122(),
            'slug' => $comuna->getSlug(),
            'post_id' => $post->getId()->toRfc4122(),
        ] + $details);
    }

    private static function limitReached(): ApiProblem
    {
        return ApiProblem::conflict(
            '/errors/comuna-pin-limit',
            'Esta comuna já tem 3 publicações fixadas.',
            'Desafixe uma delas antes de fixar outra.',
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        return $this->em->getConnection()->transactional(function () use ($operation): mixed {
            $result = $operation();
            $this->em->flush();

            return $result;
        });
    }
}
