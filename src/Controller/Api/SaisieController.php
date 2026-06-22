<?php
/**
 * @file    SaisieController.php
 * @package App\Controller\Api
 * @desc    Endpoints de saisie, contre-saisie et correction IFU.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use App\DTO\ContresaisieRequest;
use App\DTO\CorrectionRequest;
use App\DTO\SaisieRequest;
use App\Entity\Utilisateur;
use App\Exception\CnssNotFoundException;
use App\Exception\DuplicateSaisieException;
use App\Exception\EntiteConsolideeException;
use App\Exception\IneligibleContresaisieException;
use App\Service\SaisiePresenter;
use App\Service\SaisieService;
use App\Util\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/saisie')]
final class SaisieController extends AbstractApiController
{
  public function __construct(
    private readonly SaisieService $saisieService,
    private readonly SaisiePresenter $presenter,
    private readonly ValidatorInterface $validator,
  ) {
  }

  /**
   * Première saisie IFU par Agent 1 (UC02).
   */
  #[Route('', name: 'api_saisie_create', methods: ['POST'])]
  #[IsGranted('ROLE_AGENT1')]
  public function create(Request $request): JsonResponse
  {
    $dto = $this->deserializeSaisieRequest($request);
    $errors = $this->validator->validate($dto);
    if (count($errors) > 0) {
      return $this->errorResponse('VALIDATION_ERROR', (string) $errors->get(0)->getMessage(), 422);
    }

    /** @var Utilisateur $agent */
    $agent = $this->getUser();

    try {
      $saisie = $this->saisieService->createSaisie($dto, $agent);
    } catch (CnssNotFoundException $e) {
      return $this->errorResponse('CNSS_NOT_FOUND', $e->getMessage(), 404);
    } catch (DuplicateSaisieException $e) {
      return $this->errorResponse('DUPLICATE_CNSS', $e->getMessage(), 409);
    } catch (EntiteConsolideeException $e) {
      return $this->errorResponse('ENTITY_CONSOLIDATED', $e->getMessage(), 423);
    }

    return $this->successResponse($this->presenter->presentFull($saisie), 201, 'Saisie enregistrée avec succès.');
  }

  /**
   * Liste paginée des saisies de l'agent connecté.
   */
  #[Route('/mes-saisies', name: 'api_saisie_mes_saisies', methods: ['GET'])]
  public function mesSaisies(Request $request): JsonResponse
  {
    /** @var Utilisateur $agent */
    $agent = $this->getUser();
    [$page, $limit] = Pagination::parse($request, 20);
    $search = $request->query->get('search');
    $status = $request->query->get('status');
    $period = $request->query->get('period');

    $result = $this->saisieService->getMesSaisiesPaginated(
      $agent,
      $page,
      $limit,
      is_string($search) && $search !== '' ? $search : null,
      is_string($status) && $status !== '' ? $status : null,
      is_string($period) && $period !== '' ? $period : null,
    );

    $present = fn ($s) => $agent->getRole() === 'agent2'
      ? $this->presenter->presentForAgent2($s)
      : $this->presenter->presentFull($s);

    return $this->successResponse([
      'items' => array_map($present, $result['items']),
      ...Pagination::meta($result['total'], $page, $limit),
      'stats' => $result['stats'],
    ]);
  }

  /**
   * Vérifie l'éligibilité à la contre-saisie (UC03, RG-11).
   */
  #[Route('/attente/{numCNSS}', name: 'api_saisie_attente', methods: ['GET'])]
  #[IsGranted('ROLE_AGENT2')]
  public function attente(string $numCNSS): JsonResponse
  {
    /** @var Utilisateur $agent */
    $agent = $this->getUser();

    try {
      $data = $this->saisieService->getAttenteContresaisie($numCNSS, $agent);
    } catch (IneligibleContresaisieException $e) {
      return $this->errorResponse('NOT_ELIGIBLE', $e->getMessage(), 422);
    }

    return $this->successResponse($data);
  }

  /**
   * Contre-saisie IFU par Agent 2 (UC03).
   */
  #[Route('/{numCNSS}/contresaisie', name: 'api_saisie_contresaisie', methods: ['PATCH'])]
  #[IsGranted('ROLE_AGENT2')]
  public function contresaisie(string $numCNSS, Request $request): JsonResponse
  {
    $dto = $this->deserializeContresaisieRequest($request);
    $errors = $this->validator->validate($dto);
    if (count($errors) > 0) {
      return $this->errorResponse('VALIDATION_ERROR', (string) $errors->get(0)->getMessage(), 422);
    }

    /** @var Utilisateur $agent */
    $agent = $this->getUser();

    try {
      $saisie = $this->saisieService->contresaisie($numCNSS, $dto, $agent);
    } catch (IneligibleContresaisieException $e) {
      return $this->errorResponse('NOT_ELIGIBLE', $e->getMessage(), 422);
    } catch (EntiteConsolideeException $e) {
      return $this->errorResponse('ENTITY_CONSOLIDATED', $e->getMessage(), 423);
    }

    return $this->successResponse(
      $this->presenter->presentForAgent2($saisie),
      200,
      'Contre-saisie enregistrée avec succès.'
    );
  }

  /**
   * Contexte de correction pour l'agent connecté (UC05).
   */
  #[Route('/{numCNSS}/correction', name: 'api_saisie_correction_context', methods: ['GET'])]
  public function correctionContext(string $numCNSS): JsonResponse
  {
    /** @var Utilisateur $agent */
    $agent = $this->getUser();

    try {
      $data = $this->saisieService->getCorrectionContext($numCNSS, $agent);
    } catch (CnssNotFoundException $e) {
      return $this->errorResponse('CNSS_NOT_FOUND', $e->getMessage(), 404);
    } catch (IneligibleContresaisieException $e) {
      return $this->errorResponse('NOT_ELIGIBLE', $e->getMessage(), 422);
    }

    return $this->successResponse($data);
  }

  /**
   * Correction IFU après discordance (UC05).
   */
  #[Route('/{numCNSS}/correction', name: 'api_saisie_correction', methods: ['PATCH'])]
  public function correction(string $numCNSS, Request $request): JsonResponse
  {
    $dto = $this->deserializeCorrectionRequest($request);
    $errors = $this->validator->validate($dto);
    if (count($errors) > 0) {
      return $this->errorResponse('VALIDATION_ERROR', (string) $errors->get(0)->getMessage(), 422);
    }

    /** @var Utilisateur $agent */
    $agent = $this->getUser();

    try {
      $saisie = $this->saisieService->correction($numCNSS, $dto, $agent);
    } catch (CnssNotFoundException $e) {
      return $this->errorResponse('CNSS_NOT_FOUND', $e->getMessage(), 404);
    } catch (EntiteConsolideeException $e) {
      return $this->errorResponse('ENTITY_CONSOLIDATED', $e->getMessage(), 423);
    } catch (IneligibleContresaisieException $e) {
      return $this->errorResponse('NOT_ELIGIBLE', $e->getMessage(), 422);
    }

    $presented = $agent->getRole() === 'agent2'
      ? $this->presenter->presentForAgent2($saisie)
      : $this->presenter->presentFull($saisie);

    return $this->successResponse($presented, 200, 'Correction enregistrée avec succès.');
  }

  private function deserializeSaisieRequest(Request $request): SaisieRequest
  {
    /** @var array<string, mixed> $body */
    $body = json_decode($request->getContent(), true) ?? [];

    return new SaisieRequest(
      numCnss: (string) ($body['numCnss'] ?? ''),
      ifu: (string) ($body['ifu'] ?? ''),
      ifuConfirmation: (string) ($body['ifuConfirmation'] ?? ''),
    );
  }

  private function deserializeContresaisieRequest(Request $request): ContresaisieRequest
  {
    /** @var array<string, mixed> $body */
    $body = json_decode($request->getContent(), true) ?? [];

    return new ContresaisieRequest(
      ifu: (string) ($body['ifu'] ?? ''),
      ifuConfirmation: (string) ($body['ifuConfirmation'] ?? ''),
    );
  }

  private function deserializeCorrectionRequest(Request $request): CorrectionRequest
  {
    /** @var array<string, mixed> $body */
    $body = json_decode($request->getContent(), true) ?? [];

    return new CorrectionRequest(
      ifu: (string) ($body['ifu'] ?? ''),
      ifuConfirmation: (string) ($body['ifuConfirmation'] ?? ''),
    );
  }
}
