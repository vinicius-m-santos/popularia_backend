<?php

declare(strict_types=1);

namespace App\Api\Block;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\UserBlockRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProcessorInterface<mixed, null> */
final readonly class UnblockProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private UserBlockRepository $blocks,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $target = $this->users->findOneByHandle((string) ($uriVariables['handle'] ?? ''));
        $block = null === $target ? null : $this->blocks->findBlock($this->currentUser->get(), $target);
        if (null !== $block) {
            $this->em->remove($block);
            $this->em->flush();
        }

        return null;
    }
}
