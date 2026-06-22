<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\ChangePasswordRequest;
use App\DTO\LoginRequest;
use App\Entity\Utilisateur;
use App\Exception\AccountDisabledException;
use App\Exception\AccountLockedException;
use App\Exception\InvalidCredentialsException;
use App\Repository\UtilisateurRepository;
use App\Service\AuditService;
use App\Service\AuditSnapshotFactory;
use App\Service\AuthService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthServiceTest extends TestCase
{
  private UtilisateurRepository&MockObject $utilisateurRepository;
  private UserPasswordHasherInterface&MockObject $passwordHasher;
  private JWTTokenManagerInterface&MockObject $jwtManager;
  private EntityManagerInterface&MockObject $entityManager;
  private Connection&MockObject $connection;
  private AuthService $service;

  protected function setUp(): void
  {
    $this->utilisateurRepository = $this->createMock(UtilisateurRepository::class);
    $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
    $this->jwtManager = $this->createMock(JWTTokenManagerInterface::class);
    $this->entityManager = $this->createMock(EntityManagerInterface::class);
    $this->connection = $this->createMock(Connection::class);

    $this->service = new AuthService(
      $this->utilisateurRepository,
      $this->passwordHasher,
      $this->jwtManager,
      $this->entityManager,
      new AuditService($this->connection),
      new AuditSnapshotFactory(),
      $this->createMock(RequestStack::class),
      3600,
      86400,
    );
  }

  public function testLoginLogsRefusedWhenUserUnknown(): void
  {
    $this->utilisateurRepository->method('findOneByUsername')->willReturn(null);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'LOGIN_REFUSEE'
        && $row['user_id'] === null
        && str_contains((string) $row['valeur_apres'], 'UNKNOWN_USER')),
    );

    $this->expectException(InvalidCredentialsException::class);

    $this->service->login(new LoginRequest(username: 'unknown', password: 'secret'));
  }

  public function testLoginLogsRefusedWhenPasswordInvalid(): void
  {
    $user = $this->createUser();

    $this->utilisateurRepository->method('findOneByUsername')->willReturn($user);
    $this->passwordHasher->method('isPasswordValid')->willReturn(false);
    $this->entityManager->expects($this->once())->method('flush');
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'LOGIN_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'INVALID_PASSWORD')),
    );

    $this->expectException(InvalidCredentialsException::class);

    $this->service->login(new LoginRequest(username: 'agent1', password: 'wrong'));
  }

  public function testLoginLogsRefusedWhenAccountDisabled(): void
  {
    $user = $this->createUser()->setIsActive(false);

    $this->utilisateurRepository->method('findOneByUsername')->willReturn($user);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'LOGIN_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'ACCOUNT_DISABLED')),
    );

    $this->expectException(AccountDisabledException::class);

    $this->service->login(new LoginRequest(username: 'agent1', password: 'secret'));
  }

  public function testLoginLogsRefusedWhenAccountLocked(): void
  {
    $user = $this->createUser()
      ->setNbreTentativesConnexion(5)
      ->setDureeVerrouillage(new \DateTimeImmutable('+5 minutes'));

    $this->utilisateurRepository->method('findOneByUsername')->willReturn($user);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'LOGIN_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'ACCOUNT_LOCKED')),
    );

    $this->expectException(AccountLockedException::class);

    $this->service->login(new LoginRequest(username: 'agent1', password: 'secret'));
  }

  public function testLoginLogsAccountLockoutOnFifthFailedAttempt(): void
  {
    $user = $this->createUser()->setNbreTentativesConnexion(4);

    $this->utilisateurRepository->method('findOneByUsername')->willReturn($user);
    $this->passwordHasher->method('isPasswordValid')->willReturn(false);
    $this->entityManager->expects($this->once())->method('flush');
    $this->connection->expects($this->exactly(2))->method('insert')->with(
      'audit_log',
      $this->callback(static function (array $row): bool {
        if ($row['action'] !== 'LOGIN_REFUSEE') {
          return false;
        }

        $payload = (string) $row['valeur_apres'];

        return str_contains($payload, 'INVALID_PASSWORD') || str_contains($payload, 'ACCOUNT_LOCKOUT');
      }),
    );

    $this->expectException(InvalidCredentialsException::class);

    $this->service->login(new LoginRequest(username: 'agent1', password: 'wrong'));
  }

  public function testChangePasswordLogsSuccess(): void
  {
    $user = $this->createUser()->setIsFirstConnexion(true);

    $this->passwordHasher->method('isPasswordValid')->willReturn(true);
    $this->passwordHasher->method('hashPassword')->willReturn('hashed');
    $this->entityManager->expects($this->once())->method('flush');
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'CHANGE_PASSWORD'
        && str_contains((string) ($row['valeur_avant'] ?? ''), '"is_first_connexion":true')
        && str_contains((string) ($row['valeur_apres'] ?? ''), '"is_first_connexion":false')),
    );

    $this->service->changePassword(
      $user,
      new ChangePasswordRequest(
        currentPassword: 'old',
        newPassword: 'NewPassword1',
        confirmPassword: 'NewPassword1',
      ),
    );
  }

  public function testLoginSuccessReturnsTokensAndResetsAttempts(): void
  {
    $user = $this->createUser();

    $this->utilisateurRepository->method('findOneByUsername')->willReturn($user);
    $this->passwordHasher->method('isPasswordValid')->willReturn(true);
    $this->entityManager->expects($this->exactly(2))->method('flush');
    $this->jwtManager->method('create')->with($user)->willReturn('access-token');
    $this->jwtManager->method('createFromPayload')->willReturn('refresh-token');
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'LOGIN'),
    );

    $result = $this->service->login(new LoginRequest(username: 'agent1', password: 'secret'));

    $this->assertSame($user, $result['user']);
    $this->assertSame('access-token', $result['accessToken']);
    $this->assertSame('refresh-token', $result['refreshToken']);
    $this->assertNotEmpty($result['expiresAt']);
    $this->assertSame(0, $user->getNbreTentativesConnexion());
    $this->assertNull($user->getDureeVerrouillage());
    $this->assertInstanceOf(\DateTimeImmutable::class, $user->getDtLastLogin());
  }

  public function testRefreshRenewsTokensForValidRefreshPayload(): void
  {
    $user = $this->createUser();

    $this->jwtManager->method('parse')->willReturn([
      'type' => 'refresh',
      'username' => 'agent1',
    ]);
    $this->utilisateurRepository->method('findOneByUsername')->with('agent1')->willReturn($user);
    $this->jwtManager->method('create')->with($user)->willReturn('new-access');
    $this->jwtManager->method('createFromPayload')->willReturn('new-refresh');

    $result = $this->service->refresh('old-refresh-token');

    $this->assertSame('new-access', $result['accessToken']);
    $this->assertSame('new-refresh', $result['refreshToken']);
    $this->assertNotEmpty($result['expiresAt']);
  }

  public function testRefreshThrowsWhenTokenIsNotRefreshType(): void
  {
    $this->jwtManager->method('parse')->willReturn(['type' => 'access', 'username' => 'agent1']);

    $this->expectException(InvalidCredentialsException::class);

    $this->service->refresh('invalid-token');
  }

  public function testRefreshThrowsWhenUserIsInactive(): void
  {
    $user = $this->createUser()->setIsActive(false);

    $this->jwtManager->method('parse')->willReturn(['type' => 'refresh', 'username' => 'agent1']);
    $this->utilisateurRepository->method('findOneByUsername')->willReturn($user);

    $this->expectException(InvalidCredentialsException::class);

    $this->service->refresh('refresh-token');
  }

  public function testExtractRefreshTokenReadsCookie(): void
  {
    $request = Request::create('/api/auth/refresh', 'POST', cookies: ['refresh_token' => 'abc123']);

    $this->assertSame('abc123', $this->service->extractRefreshToken($request));
  }

  public function testExtractRefreshTokenReturnsNullWhenCookieMissing(): void
  {
    $request = Request::create('/api/auth/refresh', 'POST');

    $this->assertNull($this->service->extractRefreshToken($request));
  }

  public function testChangePasswordLogsRefusedWhenCurrentPasswordInvalid(): void
  {
    $user = $this->createUser();

    $this->passwordHasher->method('isPasswordValid')->willReturn(false);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'CHANGE_PASSWORD_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'INVALID_CURRENT_PASSWORD')),
    );

    $this->expectException(InvalidCredentialsException::class);

    $this->service->changePassword(
      $user,
      new ChangePasswordRequest(
        currentPassword: 'wrong',
        newPassword: 'NewPassword1',
        confirmPassword: 'NewPassword1',
      ),
    );
  }

  private function createUser(): Utilisateur
  {
    return (new Utilisateur())
      ->setUsername('agent1')
      ->setNom('Test')
      ->setPrenom('Agent')
      ->setRole('agent1')
      ->setIsActive(true);
  }
}
