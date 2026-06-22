<?php
/**
 * @file    EmployeurRepository.php
 * @package App\Repository
 * @desc    Repository Doctrine pour le référentiel employeur.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Repository;

use App\Entity\Employeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Employeur>
 */
class EmployeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employeur::class);
    }
}
