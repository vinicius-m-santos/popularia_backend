<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ComunaRole;
use App\Repository\ComunaMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Participação de uma conta numa comuna, com o papel dentro dela ("founder" espelha
 * Comuna::owner; o banco aceita um só por comuna). Moderar uma comuna
 * (role moderator/founder) não dá acesso a e-mail, IP ou sessões de ninguém: as respostas
 * de moderação usam só dados públicos de perfil (docs/moderacao.md).
 */
#[ORM\Entity(repositoryClass: ComunaMembershipRepository::class)]
#[ORM\Table(name: 'comuna_membership')]
#[ORM\UniqueConstraint(name: 'uniq_comuna_membership', columns: ['comuna_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_comuna_membership_founder', columns: ['comuna_id'], options: ['where' => "role = 'founder'"])]
#[ORM\Index(name: 'idx_comuna_membership_user', columns: ['user_id'])]
class ComunaMembership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Comuna $comuna,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 20, enumType: ComunaRole::class)]
        private ComunaRole $role,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->joinedAt = $now;
    }

    public function getComuna(): Comuna
    {
        return $this->comuna;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): ComunaRole
    {
        return $this->role;
    }

    /** Papel muda só pela ComunaGovernance (moderação e transferência), nunca por dado do cliente. */
    public function changeRole(ComunaRole $role): void
    {
        $this->role = $role;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
