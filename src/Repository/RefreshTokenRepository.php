<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RefreshToken> */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findOneByHash(string $hash): ?RefreshToken
    {
        /** @var RefreshToken|null */
        return $this->createQueryBuilder('t')
            ->addSelect('s', 'u')
            ->join('t.session', 's')
            ->join('s.user', 'u')
            ->where('t.tokenHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Marca o token como girado só se ainda não estava. Retorna false se outra requisição
     * já o usou: duas renovações com o mesmo token contam como reuso.
     */
    public function markRotated(RefreshToken $token, \DateTimeImmutable $now): bool
    {
        $affected = $this->createQueryBuilder('t')
            ->update()
            ->set('t.rotatedAt', ':now')
            ->where('t.id = :id')
            ->andWhere('t.rotatedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('id', $token->getId(), 'uuid')
            ->getQuery()
            ->execute();

        return 1 === $affected;
    }
}
