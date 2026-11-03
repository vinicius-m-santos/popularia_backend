<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Auth\AdminAudit;
use App\Content\ComunaEvents;
use App\Content\ContentPolicy;
use App\Entity\ComunaEvent;
use App\Enum\AdminAuditAction;
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
        private AdminAudit $audit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EventView
    {
        return $this->em->getConnection()->transactional(function () use ($data, $uriVariables): EventView {
            $event = $this->find((string) ($uriVariables['id'] ?? ''));
            $actor = $this->currentUser->get();
            $comuna = $event->getComuna();
            if (null !== $comuna && !$this->content->canReadComuna($comuna, $actor)) {
                throw ApiProblem::notFound('Evento não encontrado.');
            }
            // O formulário de edição ainda não mexe na capacidade: sem os campos no corpo,
            // o que estava gravado continua valendo.
            $keepsCapacity = null === $data->capacityMode && null === $data->capacity;
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
                $keepsCapacity ? $event->getCapacityMode()?->value : $data->capacityMode,
                $keepsCapacity ? $event->getCapacity() : $data->capacity,
            );
            $this->em->flush();
            $this->audit->record($actor, AdminAuditAction::UpdateEventData, $actor->getId(), '', [
                'event_id' => $event->getId()->toRfc4122(),
                'fields' => 'title,description,format,place,address,url,startsAt,endsAt,capacity',
            ]);

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
