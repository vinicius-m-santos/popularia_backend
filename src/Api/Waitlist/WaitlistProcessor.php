<?php

declare(strict_types=1);

namespace App\Api\Waitlist;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\RequestContext;
use App\Entity\WaitlistEntry;
use App\Security\RateLimitGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** @implements ProcessorInterface<WaitlistInput, WaitlistOutput> */
final readonly class WaitlistProcessor implements ProcessorInterface
{
    private const MESSAGE = 'Pronto. Quando o Popularia abrir, a gente te avisa.';

    public function __construct(
        private EntityManagerInterface $em,
        private RateLimitGuard $rateLimit,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WaitlistOutput
    {
        $this->rateLimit->consume('waitlist', RequestContext::clientIp($context));

        $email = self::normalize($data->email);

        $entry = new WaitlistEntry($email, $this->clock->now());

        try {
            $this->em->persist($entry);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Idempotente: email já cadastrado, mesma resposta.
        }

        return new WaitlistOutput(self::MESSAGE);
    }

    private static function normalize(string $email): string
    {
        $email = mb_strtolower(trim($email));

        // Gmail: remove dots and +alias from local part.
        if (str_ends_with($email, '@gmail.com')) {
            [$local, $domain] = explode('@', $email, 2);
            $local = strstr($local, '+', true) ?: $local;
            $local = str_replace('.', '', $local);
            $email = $local.'@'.$domain;
        }

        return $email;
    }
}
