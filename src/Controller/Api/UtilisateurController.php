<?php
/**
 * @file    UtilisateurController.php
 * @package App\Controller\Api
 * @desc    CRUD utilisateurs pour l'administrateur (UC08).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use App\DTO\CreateUtilisateurRequest;
use App\DTO\UpdateUtilisateurRequest;
use App\Entity\Utilisateur;
use App\Exception\LastAdminException;
use App\Repository\UtilisateurRepository;
use App\Service\UtilisateurService;
use App\Util\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/utilisateurs')]
#[IsGranted('ROLE_ADMIN')]
final class UtilisateurController extends AbstractApiController
{
  public function __construct(
    private readonly UtilisateurService $utilisateurService,
    private readonly UtilisateurRepository $utilisateurRepository,
    private readonly ValidatorInterface $validator,
  ) {
  }

  /**
   * Liste paginée des utilisateurs.
   */
  #[Route('', name: 'api_utilisateurs_list', methods: ['GET'])]
  public function list(Request $request): JsonResponse
  {
    [$page, $limit] = Pagination::parse($request, 20);
    [$search, $role, $isActive] = $this->parseListFilters($request);
    $result = $this->utilisateurService->findPaginated($page, $limit, $search, $role, $isActive);

    return $this->successResponse([
      'items' => array_map(fn (Utilisateur $u) => $this->utilisateurService->present($u), $result['items']),
      ...Pagination::meta($result['total'], $page, $limit),
      'activeCount' => $this->utilisateurService->countActive(),
    ]);
  }

  /**
   * Liste complète légère pour les filtres et sélecteurs admin.
   */
  #[Route('/options', name: 'api_utilisateurs_options', methods: ['GET'])]
  public function options(): JsonResponse
  {
    $users = $this->utilisateurService->findAll();

    return $this->successResponse(
      array_map(fn (Utilisateur $u) => [
        'id' => $u->getId(),
        'username' => $u->getUsername(),
        'nom' => $u->getNom(),
        'prenom' => $u->getPrenom(),
        'role' => $u->getRole(),
        'isActive' => $u->isActive(),
        'dtLastLogin' => $u->getDtLastLogin()?->format(\DateTimeInterface::ATOM),
      ], $users)
    );
  }

  /**
   * Crée un utilisateur avec mot de passe temporaire.
   */
  #[Route('', name: 'api_utilisateurs_create', methods: ['POST'])]
  public function create(Request $request): JsonResponse
  {
    /** @var array<string, mixed> $body */
    $body = json_decode($request->getContent(), true) ?? [];
    $dto = new CreateUtilisateurRequest(
      username: (string) ($body['username'] ?? ''),
      nom: (string) ($body['nom'] ?? ''),
      prenom: (string) ($body['prenom'] ?? ''),
      role: (string) ($body['role'] ?? 'agent1'),
    );

    $errors = $this->validator->validate($dto);
    if (count($errors) > 0) {
      return $this->errorResponse('VALIDATION_ERROR', (string) $errors->get(0)->getMessage(), 422);
    }

    try {
      $result = $this->utilisateurService->create($dto);
    } catch (\RuntimeException $e) {
      return $this->errorResponse('DUPLICATE_USERNAME', $e->getMessage(), 409);
    }

    return $this->successResponse([
      'user' => $this->utilisateurService->present($result['user']),
      'temporaryPassword' => $result['temporaryPassword'],
    ], 201, 'Utilisateur créé. Mot de passe temporaire généré.');
  }

  /**
   * Modifie un utilisateur existant.
   */
  #[Route('/{id}', name: 'api_utilisateurs_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  public function update(int $id, Request $request): JsonResponse
  {
    $user = $this->utilisateurRepository->find($id);
    if ($user === null) {
      return $this->errorResponse('NOT_FOUND', 'Utilisateur introuvable.', 404);
    }

    /** @var array<string, mixed> $body */
    $body = json_decode($request->getContent(), true) ?? [];
    $dto = new UpdateUtilisateurRequest(
      nom: (string) ($body['nom'] ?? ''),
      prenom: (string) ($body['prenom'] ?? ''),
      role: (string) ($body['role'] ?? 'agent1'),
    );

    $errors = $this->validator->validate($dto);
    if (count($errors) > 0) {
      return $this->errorResponse('VALIDATION_ERROR', (string) $errors->get(0)->getMessage(), 422);
    }

    $updated = $this->utilisateurService->update($user, $dto);

    return $this->successResponse($this->utilisateurService->present($updated), 200, 'Utilisateur modifié.');
  }

  /**
   * Réinitialise le mot de passe d'un utilisateur avec mot de passe temporaire.
   */
  #[Route('/{id}/reset-password', name: 'api_utilisateurs_reset_password', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function resetPassword(int $id): JsonResponse
  {
    $user = $this->utilisateurRepository->find($id);
    if ($user === null) {
      return $this->errorResponse('NOT_FOUND', 'Utilisateur introuvable.', 404);
    }

    $result = $this->utilisateurService->resetPassword($user);

    return $this->successResponse([
      'user' => $this->utilisateurService->present($result['user']),
      'temporaryPassword' => $result['temporaryPassword'],
    ], 200, 'Mot de passe réinitialisé. Mot de passe temporaire généré.');
  }

  /**
   * Active ou désactive un utilisateur (RG-12).
   */
  #[Route('/{id}/toggle-active', name: 'api_utilisateurs_toggle', methods: ['PATCH'], requirements: ['id' => '\d+'])]
  public function toggleActive(int $id): JsonResponse
  {
    $user = $this->utilisateurRepository->find($id);
    if ($user === null) {
      return $this->errorResponse('NOT_FOUND', 'Utilisateur introuvable.', 404);
    }

    try {
      $updated = $this->utilisateurService->toggleActive($user);
    } catch (LastAdminException $e) {
      return $this->errorResponse('LAST_ADMIN', $e->getMessage(), 409);
    }

    $message = $updated->isActive() ? 'Utilisateur activé.' : 'Utilisateur désactivé.';

    return $this->successResponse($this->utilisateurService->present($updated), 200, $message);
  }

  /**
   * @return array{0: ?string, 1: ?string, 2: ?bool}
   */
  private function parseListFilters(Request $request): array
  {
    $search = trim((string) $request->query->get('search', ''));
    $search = $search !== '' ? $search : null;

    $role = $request->query->get('role');
    $allowedRoles = ['agent1', 'agent2', 'controleur', 'admin'];
    if (!\is_string($role) || !\in_array($role, $allowedRoles, true)) {
      $role = null;
    }

    $status = $request->query->get('status');
    $isActive = match ($status) {
      'active' => true,
      'inactive' => false,
      default => null,
    };

    return [$search, $role, $isActive];
  }
}
