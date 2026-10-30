<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaEvents;
use App\Content\ContentPolicy;
use App\Entity\ComunaEvent;
use App\Enum\EventFormat;
use App\Repository\ComunaEventRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<EventInput, EventView> */
final readonly class EventUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaEventRepository $events,
        private ComunaEvents $service,
        private ContentPolicy $content,
        private EventViews $views,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EventView
    {
        return $this->em->getConnection()->transactional(function () use ($data, $uriVariables): EventView {
            $event = $this->find((string) ($uriVariables['id'] ?? ''));
            $actor = $this->currentUser->get();
            if (!$this->content->canReadComuna($event->getComuna(), $actor)) {
                throw ApiProblem::notFound('Evento não encontrado.');
            }
            $event = $this->service->update(
                $event,
                $actor,
                EventFormat::from($data->format),
                $data->title,
                $data->description,
                $data->placeName,
                $data->address,
                $data->onlineUrl,
                $data->timeZone,
                $data->startsAt,
                $data->endsAt,
            );
            $this->em->flush();

            return $this->views->one($event, $actor);
        });
    }

    private function find(string $id): ComunaEvent
    {
        $event = Uuid::isValid($id) ? $this->events->find(Uuid::fromString($id)) : null;
        if (!$event instanceof ComunaEvent) {
            throw ApiProblem::notFound('Evento não encontrado.');
        }

        return $event;
    }
}
