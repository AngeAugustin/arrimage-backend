<?php
/**
 * @file    AuditLogRepository.php
 * @package App\Repository
 * @desc    Repository Doctrine pour le journal d'audit avec filtres (UC09).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public const MAX_EXPORT = 10_000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @return array{items: list<AuditLog>, total: int}
     */
    public function findFiltered(
        int $page,
        int $limit,
        ?int $userId = null,
        ?string $action = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
    ): array {
        $qb = $this->createFilteredQueryBuilder($userId, $action, $dateFrom, $dateTo);

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<AuditLog>
     */
    public function findAllFiltered(
        ?int $userId = null,
        ?string $action = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        int $maxLimit = self::MAX_EXPORT,
    ): array {
        return $this->createFilteredQueryBuilder($userId, $action, $dateFrom, $dateTo)
            ->setMaxResults($maxLimit)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(
        ?int $userId = null,
        ?string $action = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
    ): int {
        return (int) $this->createFilteredQueryBuilder($userId, $action, $dateFrom, $dateTo)
            ->select('COUNT(a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createFilteredQueryBuilder(
        ?int $userId,
        ?string $action,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('a')
            ->join('a.user', 'u')
            ->addSelect('u')
            ->orderBy('a.timestamp', 'DESC');

        if ($userId !== null) {
            $qb->andWhere('u.id = :userId')->setParameter('userId', $userId);
        }
        if ($action !== null && $action !== '') {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if ($dateFrom !== null) {
            $qb->andWhere('a.timestamp >= :dateFrom')->setParameter('dateFrom', $dateFrom);
        }
        if ($dateTo !== null) {
            $qb->andWhere('a.timestamp <= :dateTo')->setParameter('dateTo', $dateTo);
        }

        return $qb;
    }
}
