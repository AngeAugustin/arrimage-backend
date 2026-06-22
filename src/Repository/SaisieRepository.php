<?php
/**
 * @file    SaisieRepository.php
 * @package App\Repository
 * @desc    Repository Doctrine pour les saisies IFU.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Repository;

use App\Entity\Saisie;
use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Saisie>
 */
class SaisieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Saisie::class);
    }

    public function findByNumCnss(string $numCnss): ?Saisie
    {
        return $this->createQueryBuilder('s')
            ->join('s.employeur', 'e')
            ->where('e.numCnss = :numCnss')
            ->setParameter('numCnss', $numCnss)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Saisie>
     */
    public function findByAgent(Utilisateur $agent): array
    {
        return $this->createAgentQueryBuilder($agent)->getQuery()->getResult();
    }

    /**
     * @return array{items: list<Saisie>, total: int}
     */
    public function findByAgentPaginated(
        Utilisateur $agent,
        int $page,
        int $limit,
        ?string $search = null,
        ?string $status = null,
        ?string $period = null,
    ): array {
        $qb = $this->createAgentQueryBuilder($agent);
        $this->applyAgentListFilters($qb, $agent, $search, $status, $period);

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(s.id)')
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
     * @return array{today: int, yesterday: int, todayDelta: int, monthTotal: int, pending: int}
     */
    public function getAgentDashboardStats(Utilisateur $agent): array
    {
        $today = new \DateTimeImmutable('today');
        $yesterday = $today->modify('-1 day');
        $startOfMonth = $today->modify('first day of this month')->setTime(0, 0);

        $dateField = $agent->getRole() === 'agent1' ? 's.dtSaisie1' : 's.dtSaisie2';
        $agentField = $agent->getRole() === 'agent1' ? 's.agent1' : 's.agent2';

        $baseQb = fn () => $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where($agentField . ' = :agent')
            ->setParameter('agent', $agent);

        $todayCount = (int) $baseQb()
            ->andWhere($dateField . ' >= :todayStart')
            ->andWhere($dateField . ' < :tomorrowStart')
            ->setParameter('todayStart', $today)
            ->setParameter('tomorrowStart', $today->modify('+1 day'))
            ->getQuery()
            ->getSingleScalarResult();

        $yesterdayCount = (int) $baseQb()
            ->andWhere($dateField . ' >= :yesterdayStart')
            ->andWhere($dateField . ' < :todayStart')
            ->setParameter('yesterdayStart', $yesterday)
            ->setParameter('todayStart', $today)
            ->getQuery()
            ->getSingleScalarResult();

        $monthTotal = (int) $baseQb()
            ->andWhere($dateField . ' >= :startOfMonth')
            ->setParameter('startOfMonth', $startOfMonth)
            ->getQuery()
            ->getSingleScalarResult();

        if ($agent->getRole() === 'agent1') {
            $pending = (int) $baseQb()
                ->andWhere('s.ifuAgent2 IS NULL')
                ->andWhere('s.flagConsolide = false')
                ->getQuery()
                ->getSingleScalarResult();
        } else {
            $pending = (int) $baseQb()
                ->andWhere('s.ifuAgent2 IS NOT NULL')
                ->andWhere('s.ifuAgent1 != s.ifuAgent2')
                ->andWhere('s.flagConsolide = false')
                ->getQuery()
                ->getSingleScalarResult();
        }

        return [
            'today' => $todayCount,
            'yesterday' => $yesterdayCount,
            'todayDelta' => $todayCount - $yesterdayCount,
            'monthTotal' => $monthTotal,
            'pending' => $pending,
        ];
    }

    /**
     * @return list<Saisie>
     */
    public function findDiscordances(): array
    {
        return $this->createDiscordancesQueryBuilder()->getQuery()->getResult();
    }

    /**
     * @return array{items: list<Saisie>, total: int}
     */
    public function findDiscordancesPaginated(int $page, int $limit): array
    {
        $qb = $this->createDiscordancesQueryBuilder();

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(s.id)')
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

    public function countRecentDiscordances(int $hours = 1): int
    {
        $threshold = new \DateTimeImmutable(sprintf('-%d hours', $hours));

        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.ifuAgent2 IS NOT NULL')
            ->andWhere('s.ifuAgent1 != s.ifuAgent2')
            ->andWhere('s.dtSaisie2 >= :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function averageDiscordanceDelayMinutes(): ?int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = 'SELECT AVG(EXTRACT(EPOCH FROM (s.dt_saisie2 - s.dt_saisie1)) / 60) AS avg_delay
                FROM saisie s
                WHERE s.ifu_agent2 IS NOT NULL
                AND s.ifu_agent1 != s.ifu_agent2
                AND s.dt_saisie1 IS NOT NULL
                AND s.dt_saisie2 IS NOT NULL';

        $avg = $conn->executeQuery($sql)->fetchOne();

        if ($avg === false || $avg === null) {
            return null;
        }

        return max(1, (int) round((float) $avg));
    }

    private function createAgentQueryBuilder(Utilisateur $agent): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.employeur', 'e')
            ->addSelect('e');

        if ($agent->getRole() === 'agent1') {
            $qb->where('s.agent1 = :agent')
                ->orderBy('s.dtSaisie1', 'DESC');
        } else {
            $qb->where('s.agent2 = :agent')
                ->orderBy('s.dtSaisie2', 'DESC');
        }

        return $qb->setParameter('agent', $agent);
    }

    private function createDiscordancesQueryBuilder(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->join('s.employeur', 'e')
            ->addSelect('e')
            ->join('s.agent1', 'a1')
            ->addSelect('a1')
            ->join('s.agent2', 'a2')
            ->addSelect('a2')
            ->where('s.ifuAgent2 IS NOT NULL')
            ->andWhere('s.ifuAgent1 != s.ifuAgent2')
            ->orderBy('s.dtSaisie2', 'DESC');
    }

    private function applyAgentListFilters(
        \Doctrine\ORM\QueryBuilder $qb,
        Utilisateur $agent,
        ?string $search,
        ?string $status,
        ?string $period,
    ): void {
        if ($search !== null && $search !== '') {
            $qb->andWhere('LOWER(e.numCnss) LIKE :search OR LOWER(e.raisonSociale) LIKE :search')
                ->setParameter('search', '%' . strtolower($search) . '%');
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            match ($status) {
                'consolide' => $qb->andWhere('s.status = :consolideStatus')
                    ->setParameter('consolideStatus', 'CONSOLIDE'),
                'attente' => $qb->andWhere('s.ifuAgent2 IS NULL')
                    ->andWhere('s.flagConsolide = false'),
                'discordant' => $qb->andWhere('s.ifuAgent2 IS NOT NULL')
                    ->andWhere('s.ifuAgent1 != s.ifuAgent2')
                    ->andWhere('s.flagConsolide = false'),
                'concordant' => $qb->andWhere('s.ifuAgent2 IS NOT NULL')
                    ->andWhere('s.ifuAgent1 = s.ifuAgent2')
                    ->andWhere('s.flagConsolide = false'),
                default => null,
            };
        }

        if ($period !== null && $period !== '') {
            $periodStart = new \DateTimeImmutable($period . ' 00:00:00');
            $periodEnd = $periodStart->modify('+1 day');
            $dateField = $agent->getRole() === 'agent1' ? 's.dtSaisie1' : 's.dtSaisie2';

            $qb->andWhere($dateField . ' >= :periodStart')
                ->andWhere($dateField . ' < :periodEnd')
                ->setParameter('periodStart', $periodStart)
                ->setParameter('periodEnd', $periodEnd);
        }
    }

    /**
     * Compte les lignes en double (même N° CNSS) parmi les dossiers éligibles à la consolidation.
     */
    public function countEligibleDuplicateCnss(): int
    {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COUNT(*) - COUNT(DISTINCT s.num_cnss)
             FROM saisie s
             WHERE s.flag_consolide = false
               AND s.ifu_agent2 IS NOT NULL
               AND s.ifu_agent1 = s.ifu_agent2',
        )->fetchOne();

        return (int) $result;
    }

    /**
     * @return list<Saisie>
     */
    public function findEligibleForConsolidation(): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.employeur', 'e')
            ->addSelect('e')
            ->where('s.flagConsolide = false')
            ->andWhere('s.ifuAgent2 IS NOT NULL')
            ->andWhere('s.ifuAgent1 = s.ifuAgent2')
            ->getQuery()
            ->getResult();
    }

    /**
     * Parcourt les lignes éligibles à la consolidation sans hydrater les entités Doctrine.
     *
     * @return iterable<array{numCnss: string, ifu: string, raisonSociale: string}>
     */
    public function iterateEligibleForConsolidationRows(): iterable
    {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT e.num_cnss, s.ifu_agent1 AS ifu, e.raison_sociale
             FROM saisie s
             INNER JOIN employeur e ON e.num_cnss = s.num_cnss
             WHERE s.flag_consolide = false
               AND s.ifu_agent2 IS NOT NULL
               AND s.ifu_agent1 = s.ifu_agent2
             ORDER BY e.num_cnss ASC'
        );

        foreach ($result->iterateAssociative() as $row) {
            yield [
                'numCnss' => (string) $row['num_cnss'],
                'ifu' => (string) $row['ifu'],
                'raisonSociale' => (string) $row['raison_sociale'],
            ];
        }
    }

    /**
     * Parcourt les lignes consolidées pour un export donné.
     *
     * @return iterable<array{numCnss: string, ifu: string, raisonSociale: string}>
     */
    public function iterateConsolidatedRowsByDtExport(\DateTimeImmutable $dtExport): iterable
    {
        $result = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT e.num_cnss, s.ifu_agent1 AS ifu, e.raison_sociale
             FROM saisie s
             INNER JOIN employeur e ON e.num_cnss = s.num_cnss
             WHERE s.flag_consolide = true
               AND s.dt_export = :dtExport
             ORDER BY e.num_cnss ASC',
            ['dtExport' => $dtExport->format('Y-m-d H:i:s')],
        );

        foreach ($result->iterateAssociative() as $row) {
            yield [
                'numCnss' => (string) $row['num_cnss'],
                'ifu' => (string) $row['ifu'],
                'raisonSociale' => (string) $row['raison_sociale'],
            ];
        }
    }

    /**
     * @return list<Saisie>
     */
    public function findConsolidatedByDtExport(\DateTimeImmutable $dtExport): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.employeur', 'e')
            ->addSelect('e')
            ->where('s.flagConsolide = true')
            ->andWhere('s.dtExport = :dtExport')
            ->setParameter('dtExport', $dtExport)
            ->orderBy('e.numCnss', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countConcordant(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        return $this->countWithFilters($from, $to, null, 'concordant');
    }

    public function countDiscordant(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        return $this->countWithFilters($from, $to, null, 'discordant');
    }

    public function countConsolide(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        return $this->countWithFilters($from, $to, null, 'consolide');
    }

    public function countRestants(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        return $this->countWithFilters($from, $to, null, 'restant');
    }

    public function countTotal(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        return $this->countWithFilters($from, $to, null, 'total');
    }

    public function countEnAttenteContresaisie(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.ifuAgent2 IS NULL')
            ->andWhere('s.flagConsolide = false')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{agentId: int, nom: string, prenom: string, role: string, count: int}>
     */
    public function countSaisiesByAgent(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?int $agentId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = 'SELECT u.id AS agent_id, u.nom, u.prenom, u.role,
                COUNT(s.id) AS cnt
                FROM saisie s
                JOIN utilisateur u ON u.id = s.agent1_id
                WHERE 1=1';
        $params = [];

        if ($from !== null) {
            $sql .= ' AND s.dt_saisie1 >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s');
        }
        if ($to !== null) {
            $sql .= ' AND s.dt_saisie1 <= :to';
            $params['to'] = $to->format('Y-m-d H:i:s');
        }
        if ($agentId !== null) {
            $sql .= ' AND u.id = :agentId';
            $params['agentId'] = $agentId;
        }

        $sql .= ' GROUP BY u.id, u.nom, u.prenom, u.role ORDER BY cnt DESC';

        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn (array $r) => [
            'agentId' => (int) $r['agent_id'],
            'nom' => $r['nom'],
            'prenom' => $r['prenom'],
            'role' => $r['role'],
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    public function countSaisiesByDate(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?int $agentId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = 'SELECT DATE(s.dt_saisie1) AS saisie_date, COUNT(s.id) AS cnt
                FROM saisie s
                WHERE 1=1';
        $params = [];

        if ($from !== null) {
            $sql .= ' AND s.dt_saisie1 >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s');
        }
        if ($to !== null) {
            $sql .= ' AND s.dt_saisie1 <= :to';
            $params['to'] = $to->format('Y-m-d H:i:s');
        }
        if ($agentId !== null) {
            $sql .= ' AND s.agent1_id = :agentId';
            $params['agentId'] = $agentId;
        }

        $sql .= ' GROUP BY DATE(s.dt_saisie1) ORDER BY saisie_date DESC';

        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn (array $r) => [
            'date' => $r['saisie_date'],
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    private function countWithFilters(
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?int $agentId,
        string $type,
    ): int {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');

        if ($from !== null) {
            $qb->andWhere('s.dtSaisie1 >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('s.dtSaisie1 <= :to')->setParameter('to', $to);
        }
        if ($agentId !== null) {
            $qb->andWhere('s.agent1 = :agentId')->setParameter('agentId', $agentId);
        }

        match ($type) {
            'concordant' => $qb->andWhere('s.ifuAgent2 IS NOT NULL')
                ->andWhere('s.ifuAgent1 = s.ifuAgent2'),
            'discordant' => $qb->andWhere('s.ifuAgent2 IS NOT NULL')
                ->andWhere('s.ifuAgent1 != s.ifuAgent2'),
            'consolide' => $qb->andWhere('s.flagConsolide = true'),
            'restant' => $qb->andWhere('s.flagConsolide = false')
                ->andWhere('s.ifuAgent2 IS NOT NULL')
                ->andWhere('s.ifuAgent1 = s.ifuAgent2'),
            default => null,
        };

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
