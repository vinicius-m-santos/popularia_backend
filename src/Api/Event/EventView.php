<?php

declare(strict_types=1);

namespace App\Api\Event;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model\Operation;
use App\Api\Comuna\ComunaView;
use App\Api\Profile\AuthorView;

/**
 * Atividade de uma comuna. startsAt é um instante; timeZone é o fuso em que o horário foi anunciado.
 * Quem exibe formata nesse fuso, não no fuso do aparelho de quem lê.
 */
#[ApiResource(
    shortName: 'Event',
    operations: [
        new Get(
            uriTemplate: '/events/{id}',
            requirements: ['id' => '[0-9a-fA-F-]{36}'],
            provider: EventProvider::class,
            openapi: new Operation(tags: ['Eventos'], summary: 'Um evento.', description: 'Comuna privada sem participação, ou id inexistente: 404, sem confirmar que existe.'),
        ),
        new Post(
            uriTemplate: '/comunas/{slug}/events',
            uriVariables: ['slug' => new Link(fromClass: ComunaView::class, identifiers: ['slug'])],
            status: 201,
            read: false,
            security: "is_granted('ROLE_USER')",
            input: EventInput::class,
            processor: EventCreateProcessor::class,
            openapi: new Operation(tags: ['Eventos'], summary: 'Cria um evento.', description: 'Só moderação ou proprietário (403 /errors/not-comuna-moderator). Comuna arquivada: 409 /errors/comuna-archived. Não avisa todos os membros.'),
        ),
        new Patch(
            uriTemplate: '/events/{id}',
            requirements: ['id' => '[0-9a-fA-F-]{36}'],
            read: false,
            security: "is_granted('ROLE_USER')",
            input: EventInput::class,
            processor: EventUpdateProcessor::class,
            openapi: new Operation(tags: ['Eventos'], summary: 'Edita o evento.', description: 'O corpo é completo. Mudar o tipo sem local ou link válidos é recusado. O fuso anunciado não muda.'),
        ),
        new Post(
            uriTemplate: '/events/{id}/cancel',
            requirements: ['id' => '[0-9a-fA-F-]{36}'],
            status: 200,
            read: false,
            deserialize: false,
            validate: false,
            security: "is_granted('ROLE_USER')",
            processor: EventCancelProcessor::class,
            openapi: new Operation(tags: ['Eventos'], summary: 'Cancela o evento sem apagá-lo.', description: 'Idempotente. Avisa quem respondeu Vou ou Talvez.'),
        ),
        new Put(
            uriTemplate: '/events/{id}/attendance',
            requirements: ['id' => '[0-9a-fA-F-]{36}'],
            read: false,
            security: "is_granted('ROLE_USER')",
            input: AttendanceInput::class,
            processor: AttendanceProcessor::class,
            openapi: new Operation(tags: ['Eventos'], summary: 'Responde Vou, Talvez ou Não vou.', description: 'Substitui a resposta anterior. Não avisa a moderação. Evento cancelado, passado ou comuna arquivada não aceita resposta nova.'),
        ),
    ],
)]
final readonly class EventView
{
    /**
     * @param array{id: string, slug: string, name: string, visibility: string, status: string} $comuna
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $title,
        public string $description,
        public string $format,
        public string $status,
        public string $timeZone,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public ?string $placeName,
        public ?string $address,
        public ?string $onlineUrl,
        public array $comuna,
        public ?AuthorView $organizer,
        public int $goingCount,
        public int $maybeCount,
        public ?string $viewerAttendance,
        public bool $canManage,
        public bool $canRespond,
        public bool $past,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public ?\DateTimeImmutable $cancelledAt,
    ) {
    }
}
