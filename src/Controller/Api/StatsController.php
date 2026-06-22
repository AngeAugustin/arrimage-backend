<?php
/**
 * @file    StatsController.php
 * @package App\Controller\Api
 * @desc    Statistiques tableau de bord administrateur (UC07).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use App\Service\StatsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/stats')]
#[IsGranted('ROLE_ADMIN')]
final class StatsController extends AbstractApiController
{
  public function __construct(
    private readonly StatsService $statsService,
  ) {
  }

  /**
   * Retourne les statistiques avec filtres optionnels.
   */
  #[Route('', name: 'api_stats', methods: ['GET'])]
  public function index(Request $request): JsonResponse
  {
    $filters = array_filter([
      'dateFrom' => $request->query->get('dateFrom'),
      'dateTo' => $request->query->get('dateTo'),
      'agentId' => $request->query->get('agentId'),
    ], fn ($v) => $v !== null && $v !== '');

    return $this->successResponse($this->statsService->getStats($filters));
  }
}
