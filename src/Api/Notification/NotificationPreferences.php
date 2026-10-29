<?php

declare(strict_types=1);

namespace App\Api\Notification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Entity\NotificationPreference;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(operations: [
    new Get(uriTemplate: '/me/notification-preferences', security: "is_granted('ROLE_USER')", provider: NotificationPreferencesHandler::class),
    new Put(uriTemplate: '/me/notification-preferences', status: 200, read: false, security: "is_granted('ROLE_USER')", processor: NotificationPreferencesHandler::class),
])]
final class NotificationPreferences
{
    /** @param list<string> $pushCategories */
    public function __construct(
        #[Assert\Count(max: 4)]
        #[Assert\Choice(choices: NotificationPreference::CATEGORIES, multiple: true)]
        public array $pushCategories = ['interactions', 'follows', 'comunas'],
    ) {
    }
}
