<?php

declare(strict_types=1);

namespace App\Auth;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Enum\AccountTokenPurpose;
use App\Repository\AccountTokenRepository;
use App\Security\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Códigos de 6 dígitos de uso único (verificação de e-mail e recuperação de senha).
 * Emitir um código fecha os anteriores da mesma finalidade; errar 5 vezes fecha o código.
 */
final class AccountCodes
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountTokenRepository $repository,
        private readonly TokenGenerator $tokens,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Retorna o código em texto para enviar por e-mail. Ele não é guardado. */
    public function issue(User $user, AccountTokenPurpose $purpose): string
    {
        $now = $this->clock->now();
        $this->repository->closeAllOpen($user, $purpose, $now);
        $code = $this->tokens->newCode();
        $this->em->persist(new AccountToken($user, $purpose, $this->tokens->hashCode($user, $purpose, $code), $now));
        $this->em->flush();

        return $code;
    }

    /** Confere e consome o código. Retorna false se não houver código válido ou se ele não conferir. */
    public function consume(User $user, AccountTokenPurpose $purpose, #[\SensitiveParameter] string $code): bool
    {
        $now = $this->clock->now();
        $token = $this->repository->findLatestOpen($user, $purpose);
        if (null === $token || !$token->isUsable($now)) {
            return false;
        }

        if (!hash_equals($token->getCodeHash(), $this->tokens->hashCode($user, $purpose, $code))) {
            $token->registerFailedAttempt($now);
            $this->em->flush();

            return false;
        }

        $token->close($now);
        $this->em->flush();

        return true;
    }
}
