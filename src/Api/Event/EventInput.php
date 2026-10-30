<?php

declare(strict_types=1);

namespace App\Api\Event;

use App\Api\Constraint\TextRules;
use App\Entity\ComunaEvent;
use App\Enum\EventFormat;
use Symfony\Component\Validator\Constraints as Assert;

/** Corpo completo. Trocar o tipo sem o campo que ele exige é recusado no servidor. */
final class EventInput
{
    #[Assert\NotBlank(message: 'Dê um título ao evento.', normalizer: 'trim')]
    #[Assert\Length(max: ComunaEvent::TITLE_MAX, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public string $title = '';

    #[Assert\Length(max: ComunaEvent::DESCRIPTION_MAX, maxMessage: 'Use até {{ limit }} caracteres.')]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public string $description = '';

    #[Assert\NotBlank(message: 'Escolha presencial, online ou híbrido.')]
    #[Assert\Choice(callback: [EventFormat::class, 'values'], message: 'Tipo de evento desconhecido.')]
    public string $format = '';

    #[Assert\Length(max: ComunaEvent::PLACE_MAX)]
    #[Assert\Regex(pattern: TextRules::SINGLE_LINE, message: TextRules::MESSAGE)]
    public ?string $placeName = null;

    #[Assert\Length(max: ComunaEvent::ADDRESS_MAX)]
    #[Assert\Regex(pattern: TextRules::MULTI_LINE, message: TextRules::MESSAGE)]
    public ?string $address = null;

    #[Assert\Length(max: ComunaEvent::URL_MAX)]
    public ?string $onlineUrl = null;

    #[Assert\NotBlank(message: 'Informe o fuso em que o horário foi marcado.')]
    #[Assert\Length(max: 64)]
    public string $timeZone = '';

    #[Assert\NotBlank(message: 'Informe quando começa.')]
    public string $startsAt = '';

    public ?string $endsAt = null;
}
