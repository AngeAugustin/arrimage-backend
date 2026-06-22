<?php
/**
 * @file    UtilisateurRepository.php
 * @package App\Repository
 * @desc    Repository Doctrine pour l'entité Utilisateur.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    /**
     * Compte les administrateurs actifs (RG-12).
     */
    public function countActiveAdmins(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->andWhere('u.isActive = true')
            ->setParameter('role', 'admin')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByUsername(string $username): ?Utilisateur
    {
        return $this->findOneBy(['username' => $username]);
    }

    /**
     * @return array{items: list<Utilisateur>, total: int}
     */
    public function findPaginated(
        int $page,
        int $limit,
        ?string $search = null,
        ?string $role = null,
        ?bool $isActive = null,
    ): array {
        $qb = $this->createFilteredQueryBuilder($search, $role, $isActive)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(u.id)')
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

    private function createFilteredQueryBuilder(
        ?string $search,
        ?string $role,
        ?bool $isActive,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('u');

        if ($search !== null && $search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(u.nom) LIKE :search',
                    'LOWER(u.prenom) LIKE :search',
                    'LOWER(u.username) LIKE :search',
                ),
            )->setParameter('search', $term);
        }

        if ($role !== null && $role !== '') {
            $qb->andWhere('u.role = :role')->setParameter('role', $role);
        }

        if ($isActive !== null) {
            $qb->andWhere('u.isActive = :isActive')->setParameter('isActive', $isActive);
        }

        return $qb;
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.isActive = true')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
