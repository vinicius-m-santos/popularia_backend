<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaEvents;
use App\Enum\EventFormat;
use App\Repository\ComunaRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProcessorInterface<EventInput, EventView> */
final readonly class EventCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ComunaRepository $comunas,
        private ComunaEvents $events,
        private EventViews $views,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EventView
    {
        return $this->em->getConnection()->transactional(function () use ($data, $uriVariables): EventView {
            $comuna = $this->comunas->findOneBySlug((string) ($uriVariables['slug'] ?? '')) ?? throw ApiProblem::notFound('Comuna não encontrada.');
            $actor = $this->currentUser->get();
            $event = $this->events->create(
                $comuna,
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
}
