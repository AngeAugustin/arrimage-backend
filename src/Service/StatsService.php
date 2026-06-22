<?php
/**
 * @file    StatsService.php
 * @package App\Service
 * @desc    Statistiques tableau de bord administrateur (UC07).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\Repository\SaisieRepository;

final class StatsService
{
  public function __construct(
    private readonly SaisieRepository $saisieRepository,
  ) {
  }

  /**
   * Retourne les statistiques globales et par agent.
   *
   * @param array{dateFrom?: string, dateTo?: string, agentId?: int} $filters
   *
   * @return array<string, mixed>
   */
  public function getStats(array $filters = []): array
  {
    $dateFrom = isset($filters['dateFrom']) ? new \DateTimeImmutable($filters['dateFrom']) : null;
    $dateTo = isset($filters['dateTo']) ? new \DateTimeImmutable($filters['dateTo'] . ' 23:59:59') : null;
    $agentId = isset($filters['agentId']) ? (int) $filters['agentId'] : null;

    return [
      'summary' => [
        'concordants' => $this->saisieRepository->countConcordant($dateFrom, $dateTo),
        'discordants' => $this->saisieRepository->countDiscordant($dateFrom, $dateTo),
        'consolides' => $this->saisieRepository->countConsolide($dateFrom, $dateTo),
        'restants' => $this->saisieRepository->countRestants($dateFrom, $dateTo),
        'totalSaisies' => $this->saisieRepository->countTotal($dateFrom, $dateTo),
      ],
      'parAgent' => $this->saisieRepository->countSaisiesByAgent($dateFrom, $dateTo, $agentId),
      'parDate' => $this->saisieRepository->countSaisiesByDate($dateFrom, $dateTo, $agentId),
    ];
  }
}
