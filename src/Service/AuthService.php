<?php
/**
 * @file    AuthService.php
 * @package App\Service
 * @desc    Service d'authentification : login, refresh, logout, changement de mot de passe.
 *
 * Règles métier couvertes :
 *   - RG-09 : JWT access 1h + refresh 24h
 *   - RG-14 : forçage changement mdp première connexion
 *   - RG-15 : bcrypt coût ≥ 12
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\DTO\ChangePasswordRequest;
use App\DTO\LoginRequest;
use App\Entity\Utilisateur;
use App\Exception\AccountDisabledException;
use App\Exception\AccountLockedException;
use App\Exception\InvalidCredentialsException;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthService
{
  private const MAX_LOGIN_ATTEMPTS = 5;
  private const LOCK_DURATION_MINUTES = 5;

  public function __construct(
    private readonly UtilisateurRepository $utilisateurRepository,
    private readonly UserPasswordHasherInterface $passwordHasher,
    private readonly JWTTokenManagerInterface $jwtManager,
    private readonly EntityManagerInterface $entityManager,
    private readonly AuditService $auditService,
    private readonly AuditSnapshotFactory $auditSnapshotFactory,
    private readonly RequestStack $requestStack,
    private readonly int $accessTtl,
    private readonly int $refreshTtl,
  ) {
  }

  /**
   * Authentifie un utilisateur et génère les tokens JWT.
   *
   * @return array{user: Utilisateur, accessToken: string, refreshToken: string, expiresAt: string}
   *
   * @throws InvalidCredentialsException
   * @throws AccountLockedException
   * @throws AccountDisabledException
   */
  public function login(LoginRequest $dto): array
  {
    $user = $this->utilisateurRepository->findOneByUsername($dto->username);

    if ($user === null) {
      $this->logRefusedLogin('UNKNOWN_USER', $dto->username);
      throw new InvalidCredentialsException();
    }

    if (!$user->isActive()) {
      $this->logRefusedLogin('ACCOUNT_DISABLED', $dto->username, $user);
      throw new AccountDisabledException();
    }

    if ($this->isAccountLocked($user)) {
      $this->logRefusedLogin('ACCOUNT_LOCKED', $dto->username, $user, $user->getNbreTentativesConnexion());
      throw new AccountLockedException();
    }

    if (!$this->passwordHasher->isPasswordValid($user, $dto->password)) {
      $this->registerFailedAttempt($user);
      $attempts = $user->getNbreTentativesConnexion();
      $this->logRefusedLogin('INVALID_PASSWORD', $dto->username, $user, $attempts);

      if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
        $this->logRefusedLogin(
          'ACCOUNT_LOCKOUT',
          $dto->username,
          $user,
          $attempts,
          self::LOCK_DURATION_MINUTES,
          $user->getDureeVerrouillage(),
        );
      }

      throw new InvalidCredentialsException();
    }

    $this->resetLoginAttempts($user);
    $user->setDtLastLogin(new \DateTimeImmutable());
    $this->entityManager->flush();

    $this->auditService->log(
      user: $user,
      action: 'LOGIN',
      entiteCible: Utilisateur::class,
      valeurApres: json_encode($this->auditSnapshotFactory->login($user)),
      ipAddress: $this->requestStack->getCurrentRequest()?->getClientIp(),
    );

    $accessToken = $this->jwtManager->create($user);
    $refreshToken = $this->createRefreshToken($user);

    return [
      'user' => $user,
      'accessToken' => $accessToken,
      'refreshToken' => $refreshToken,
      'expiresAt' => (new \DateTimeImmutable('+' . $this->accessTtl . ' seconds'))->format(\DateTimeInterface::ATOM),
    ];
  }

  /**
   * Renouvelle l'access token à partir du refresh token.
   *
   * @return array{accessToken: string, refreshToken: string, expiresAt: string}
   */
  public function refresh(string $refreshToken): array
  {
    $payload = $this->jwtManager->parse($refreshToken);

    if (($payload['type'] ?? '') !== 'refresh') {
      throw new InvalidCredentialsException();
    }

    $user = $this->utilisateurRepository->findOneByUsername($payload['username'] ?? '');

    if ($user === null || !$user->isActive()) {
      throw new InvalidCredentialsException();
    }

    $accessToken = $this->jwtManager->create($user);
    $newRefreshToken = $this->createRefreshToken($user);

    return [
      'accessToken' => $accessToken,
      'refreshToken' => $newRefreshToken,
      'expiresAt' => (new \DateTimeImmutable('+' . $this->accessTtl . ' seconds'))->format(\DateTimeInterface::ATOM),
    ];
  }

  /**
   * Change le mot de passe de l'utilisateur connecté.
   */
  public function changePassword(Utilisateur $user, ChangePasswordRequest $dto): void
  {
    if (!$this->passwordHasher->isPasswordValid($user, $dto->currentPassword)) {
      $this->logPasswordChangeRefused($user, 'INVALID_CURRENT_PASSWORD');
      throw new InvalidCredentialsException();
    }

    $valeurAvant = json_encode($this->auditSnapshotFactory->utilisateur($user));

    $user->setPassword($this->passwordHasher->hashPassword($user, $dto->newPassword));
    $user->setIsFirstConnexion(false);
    $user->setDtModification(new \DateTimeImmutable());
    $this->entityManager->flush();

    $this->auditService->log(
      user: $user,
      action: 'CHANGE_PASSWORD',
      entiteCible: Utilisateur::class,
      valeurAvant: $valeurAvant,
      valeurApres: json_encode($this->auditSnapshotFactory->utilisateur($user)),
      ipAddress: $this->requestStack->getCurrentRequest()?->getClientIp(),
    );
  }

  /**
   * Extrait le refresh token depuis la requête HTTP.
   */
  public function extractRefreshToken(Request $request): ?string
  {
    return $request->cookies->get('refresh_token');
  }

  private function createRefreshToken(Utilisateur $user): string
  {
    return $this->jwtManager->createFromPayload($user, [
      'type' => 'refresh',
      'exp' => time() + $this->refreshTtl,
    ]);
  }

  private function isAccountLocked(Utilisateur $user): bool
  {
    $lockUntil = $user->getDureeVerrouillage();

    if ($lockUntil === null) {
      return false;
    }

    if ($lockUntil <= new \DateTimeImmutable()) {
      $this->resetLoginAttempts($user);

      return false;
    }

    return true;
  }

  private function registerFailedAttempt(Utilisateur $user): void
  {
    $attempts = $user->getNbreTentativesConnexion() + 1;
    $user->setNbreTentativesConnexion($attempts);

    if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
      $user->setDureeVerrouillage(
        new \DateTimeImmutable('+' . self::LOCK_DURATION_MINUTES . ' minutes')
      );
    }

    $this->entityManager->flush();
  }

  private function resetLoginAttempts(Utilisateur $user): void
  {
    $user->setNbreTentativesConnexion(0);
    $user->setDureeVerrouillage(null);
    $this->entityManager->flush();
  }

  private function logRefusedLogin(
    string $reason,
    string $username,
    ?Utilisateur $user = null,
    ?int $attempts = null,
    ?int $lockDurationMinutes = null,
    ?\DateTimeImmutable $lockedUntil = null,
  ): void {
    $this->auditService->log(
      user: $user,
      action: 'LOGIN_REFUSEE',
      entiteCible: Utilisateur::class,
      valeurApres: json_encode($this->auditSnapshotFactory->refusedLogin(
        $reason,
        $username,
        $user,
        $attempts,
        $lockDurationMinutes,
        $lockedUntil,
      )),
      ipAddress: $this->requestStack->getCurrentRequest()?->getClientIp(),
    );
  }

  private function logPasswordChangeRefused(Utilisateur $user, string $reason): void
  {
    $this->auditService->log(
      user: $user,
      action: 'CHANGE_PASSWORD_REFUSEE',
      entiteCible: Utilisateur::class,
      valeurApres: json_encode($this->auditSnapshotFactory->refusedLogin(
        $reason,
        $user->getUsername(),
        $user,
      )),
      ipAddress: $this->requestStack->getCurrentRequest()?->getClientIp(),
    );
  }
}
