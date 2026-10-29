<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Entity\NotificationPreference;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProviderInterface<NotificationPreferences>
 * @implements ProcessorInterface<NotificationPreferences, NotificationPreferences>
 */
final readonly class NotificationPreferencesHandler implements ProviderInterface, ProcessorInterface
{
    public function __construct(private CurrentUser $user, private EntityManagerInterface $em)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationPreferences
    {
        $preference = $this->em->find(NotificationPreference::class, $this->user->get()->getId());

        return new NotificationPreferences($preference->pushCategories ?? ['interactions', 'follows', 'comunas']);
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NotificationPreferences
    {
        $user = $this->user->get();
        $preference = $this->em->find(NotificationPreference::class, $user->getId()) ?? new NotificationPreference($user);
        $preference->pushCategories = array_values(array_unique($data->pushCategories));
        $this->em->persist($preference);
        $this->em->flush();

        return new NotificationPreferences($preference->pushCategories);
    }
}
