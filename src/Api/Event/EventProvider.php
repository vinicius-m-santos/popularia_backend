<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Content\ContentPolicy;
use App\Entity\ComunaEvent;
use App\Repository\ComunaEventRepository;
use App\Security\CurrentUser;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<EventView> */
final readonly class EventProvider implements ProviderInterface
{
    public function __construct(
        private ComunaEventRepository $events,
        private ContentPolicy $content,
        private EventViews $views,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): EventView
    {
        $id = (string) ($uriVariables['id'] ?? '');
        $event = Uuid::isValid($id) ? $this->events->find(Uuid::fromString($id)) : null;
        $viewer = $this->currentUser->find();
        if (!$event instanceof ComunaEvent || !$this->content->canReadComuna($event->getComuna(), $viewer)) {
            throw ApiProblem::notFound('Evento não encontrado.');
        }

        return $this->views->one($event, $viewer);
    }
}
