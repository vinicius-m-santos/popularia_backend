<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\ApiProblem;
use App\Content\ComunaEvents;
use App\Content\ContentPolicy;
use App\Entity\ComunaEvent;
use App\Enum\AttendanceStatus;
use App\Repository\ComunaEventRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<AttendanceInput, EventView> */
final readonly class AttendanceProcessor implements ProcessorInterface
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
            $id = (string) ($uriVariables['id'] ?? '');
            $event = Uuid::isValid($id) ? $this->events->find(Uuid::fromString($id)) : null;
            $actor = $this->currentUser->get();
            if (!$event instanceof ComunaEvent || !$this->content->canReadComuna($event->getComuna(), $actor)) {
                throw ApiProblem::notFound('Evento não encontrado.');
            }
            $this->service->respond($event, $actor, AttendanceStatus::from($data->status));
            $this->em->flush();

            return $this->views->one($event, $actor);
        });
    }
}
