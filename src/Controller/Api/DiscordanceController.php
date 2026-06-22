<?php
/**
 * @file    DiscordanceController.php
 * @package App\Controller\Api
 * @desc    Liste des discordances IFU en temps réel (UC04).
 *
 * Règles métier couvertes :
 *   - RG-04 : discordance = IFU Agent 1 ≠ IFU Agent 2
 *   - RG-10 : détection < 2s
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use App\Repository\SaisieRepository;
use App\Service\SaisiePresenter;
use App\Service\SaisieService;
use App\Util\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/discordances')]
final class DiscordanceController extends AbstractApiController
{
  public function __construct(
    private readonly SaisieService $saisieService,
    private readonly SaisiePresenter $presenter,
    private readonly SaisieRepository $saisieRepository,
  ) {
  }

  /**
   * Retourne les discordances actives, paginées.
   */
  #[Route('', name: 'api_discordances_list', methods: ['GET'])]
  public function list(Request $request): JsonResponse
  {
    [$page, $limit] = Pagination::parse($request, 20);
    $result = $this->saisieService->getDiscordancesPaginated($page, $limit);

    $data = array_map(
      fn ($s) => $this->presenter->presentDiscordance($s),
      $result['items']
    );

    $concordants = $this->saisieRepository->countConcordant(null, null);
    $discordants = $result['total'];
    $denominator = $concordants + $discordants;

    return $this->successResponse([
      'items' => $data,
      'count' => $discordants,
      ...Pagination::meta($discordants, $page, $limit),
      'summary' => [
        'discordances' => $discordants,
        'concordants' => $concordants,
        'enAttenteContresaisie' => $this->saisieRepository->countEnAttenteContresaisie(),
        'validationRate' => $denominator > 0 ? (int) round($concordants / $denominator * 100) : 0,
        'recentDelta' => $result['recentDelta'],
        'averageDelayMinutes' => $result['averageDelayMinutes'],
      ],
      'fetchedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
    ]);
  }
}
