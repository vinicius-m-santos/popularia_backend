<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'notification_preference')]
class NotificationPreference
{
    public const array CATEGORIES = ['interactions', 'follows', 'comunas', 'followed_content'];

    /** @param list<string> $pushCategories */
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $user,
        #[ORM\Column(type: Types::JSON)]
        public array $pushCategories = ['interactions', 'follows', 'comunas'],
    ) {
    }
}
