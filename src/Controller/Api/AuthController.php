<?php
/**
 * @file    AuthController.php
 * @package App\Controller\Api
 * @desc    Endpoints d'authentification JWT HttpOnly (login, refresh, logout, me, change-password).
 *
 * Règles métier couvertes :
 *   - RG-09 : JWT en cookies HttpOnly
 *   - RG-14 : is_first_connexion dans la réponse
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use App\DTO\ChangePasswordRequest;
use App\DTO\LoginRequest;
use App\Entity\Utilisateur;
use App\Exception\AccountDisabledException;
use App\Exception\AccountLockedException;
use App\Exception\InvalidCredentialsException;
use App\Service\AuthService;
use App\Service\JwtCookieService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/auth')]
final class AuthController extends AbstractApiController
{
  public function __construct(
    private readonly AuthService $authService,
    private readonly JwtCookieService $jwtCookieService,
    private readonly ValidatorInterface $validator,
  ) {
  }

  /**
   * Authentifie un utilisateur et pose les cookies JWT HttpOnly.
   */
  #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
  public function login(Request $request): JsonResponse
  {
    /** @var array<string, mixed> $body */
    $body = json_decode($request->getContent(), true) ?? [];
    $dto = new LoginRequest(
      username: (string) ($body['username'] ?? ''),
      password: (string) ($body['password'] ?? ''),
    );

    $errors = $this->validator->validate($dto);
    if (count($errors) > 0) {
      return $this->errorResponse('VALIDATION_ERROR', (string) $errors->get(0)->getMessage(), 422);
    }

    try {
      $result = $this->authService->login($dto);
    } catch (InvalidCredentialsException $e) {
      return $this->errorResponse('INVALID_CREDENTIALS', $e->getMessage(), 401);
    } catch (AccountLockedException $e) {
      return $this->errorResponse('ACCOUNT_LOCKED', $e->getMessage(), 423);
    } catch (AccountDisabledException $e) {
      return $this->errorResponse('ACCOUNT_DISABLED', $e->getMessage(), 403);
    }

    /** @var Utilisateur $user */
    $user = $result['user'];

    $response = $this->successResponse([
      'user' => $this->serializeUser($user),
      'isFirstConnexion' => $user->isFirstConnexion(),
      'expiresAt' => $result['expiresAt'],
    ]);

    $this->jwtCookieService->attachTokens($response, $result['accessToken'], $result['refreshToken']);

    return $response;
  }

  /**
   * Renouvelle l'access token à partir du cookie refresh_token.
   */
  #[Route('/refresh', name: 'api_auth_refresh', methods: ['POST'])]
  public function refresh(Request $request): JsonResponse
  {
    $refreshToken = $this->authService->extractRefreshToken($request);

    if ($refreshToken === null) {
      return $this->errorResponse('UNAUTHORIZED', 'Session expirée ou non authentifiée.', 401);
    }

    try {
      $result = $this->authService->refresh($refreshToken);
    } catch (InvalidCredentialsException) {
      return $this->errorResponse('UNAUTHORIZED', 'Session expirée ou non authentifiée.', 401);
    }

    $response = $this->successResponse(['expiresAt' => $result['expiresAt']]);
    $this->jwtCookieService->attachTokens($response, $result['accessToken'], $result['refreshToken']);

    return $response;
  }

  /**
   * Déconnecte l'utilisateur en invalidant les cookies JWT.
   */
  #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
  #[IsGranted('IS_AUTHENTICATED_FULLY')]
  public function logout(): JsonResponse
  {
    $response = $this->successResponse(null, 200, 'Déconnexion réussie.');
    $this->jwtCookieService->clearTokens($response);

    return $response;
  }

  /**
   * Retourne l'utilisateur courant (vérification de session au démarrage frontend).
   */
  #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
  #[IsGranted('IS_AUTHENTICATED_FULLY')]
  public function me(): JsonResponse
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    return $this->successResponse([
      'user' => $this->serializeUser($user),
      'isFirstConnexion' => $user->isFirstConnexion(),
    ]);
  }

  /**
   * Change le mot de passe de l'utilisateur connecté (UC10).
   */
  #[Route('/change-password', name: 'api_auth_change_password', methods: ['POST'])]
  #[IsGranted('IS_AUTHENTICATED_FULLY')]
  public function changePassword(Request $request): JsonResponse
  {
    /** @var array<string, mixed> $body */
    $body = json_decode($request->getContent(), true) ?? [];
    $dto = new ChangePasswordRequest(
      currentPassword: (string) ($body['currentPassword'] ?? ''),
      newPassword: (string) ($body['newPassword'] ?? ''),
      confirmPassword: (string) ($body['confirmPassword'] ?? ''),
    );

    $errors = $this->validator->validate($dto);
    if (count($errors) > 0) {
      return $this->errorResponse('VALIDATION_ERROR', (string) $errors->get(0)->getMessage(), 422);
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();

    try {
      $this->authService->changePassword($user, $dto);
    } catch (InvalidCredentialsException $e) {
      return $this->errorResponse('INVALID_CURRENT_PASSWORD', $e->getMessage(), 422);
    }

    return $this->successResponse(null, 200, 'Mot de passe modifié avec succès.');
  }

  /**
   * @return array<string, mixed>
   */
  private function serializeUser(Utilisateur $user): array
  {
    return [
      'id' => $user->getId(),
      'username' => $user->getUsername(),
      'role' => $user->getRole(),
      'nom' => $user->getNom(),
      'prenom' => $user->getPrenom(),
      'isActive' => $user->isActive(),
      'dtLastLogin' => $user->getDtLastLogin()?->format(\DateTimeInterface::ATOM),
    ];
  }
}
