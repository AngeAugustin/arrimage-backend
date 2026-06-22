<?php
/**
 * @file    EmployeurController.php
 * @package App\Controller\Api
 * @desc    Recherche employeur par numéro CNSS (UC02).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use App\Exception\CnssNotFoundException;
use App\Repository\EmployeurRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/employeur')]
final class EmployeurController extends AbstractApiController
{
  public function __construct(
    private readonly EmployeurRepository $employeurRepository,
  ) {
  }

  /**
   * Retourne la raison sociale d'un employeur à partir de son numéro CNSS.
   */
  #[Route('/{numCNSS}', name: 'api_employeur_show', methods: ['GET'])]
  #[IsGranted('ROLE_AGENT1')]
  public function show(string $numCNSS): JsonResponse
  {
    $employeur = $this->employeurRepository->find($numCNSS);

    if ($employeur === null) {
      return $this->errorResponse('CNSS_NOT_FOUND', (new CnssNotFoundException())->getMessage(), 404);
    }

    return $this->successResponse([
      'numCnss' => $employeur->getNumCnss(),
      'raisonSociale' => $employeur->getRaisonSociale(),
    ]);
  }
}
