<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Content\ComunaEvents;
use App\Enum\EventFormat;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProcessorInterface<EventInput, EventView> */
final readonly class ProfileEventCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaEvents $events,
        private EventViews $views,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EventView
    {
        return $this->em->getConnection()->transactional(function () use ($data): EventView {
            $actor = $this->currentUser->get();
            $event = $this->events->createForProfile(
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
                $data->capacityMode,
                $data->capacity,
            );
            $this->em->flush();

            return $this->views->one($event, $actor);
        });
    }
}
