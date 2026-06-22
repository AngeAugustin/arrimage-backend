<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\CreateUtilisateurRequest;
use App\DTO\UpdateUtilisateurRequest;
use App\Entity\Utilisateur;
use App\Exception\LastAdminException;
use App\Repository\UtilisateurRepository;
use App\Service\AuditService;
use App\Service\AuditSnapshotFactory;
use App\Service\UtilisateurService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @file    UtilisateurServiceTest.php
 * @package App\Tests\Unit\Service
 * @desc    Tests unitaires de la gestion utilisateurs (RG-12).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */
final class UtilisateurServiceTest extends TestCase
{
  private UtilisateurRepository&MockObject $repository;
  private EntityManagerInterface&MockObject $entityManager;
  private UserPasswordHasherInterface&MockObject $hasher;
  private Connection&MockObject $connection;
  private Security&MockObject $security;
  private UtilisateurService $service;

  protected function setUp(): void
  {
    $this->repository = $this->createMock(UtilisateurRepository::class);
    $this->entityManager = $this->createMock(EntityManagerInterface::class);
    $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
    $this->connection = $this->createMock(Connection::class);
    $this->security = $this->createMock(Security::class);

    $this->service = new UtilisateurService(
      $this->repository,
      $this->entityManager,
      $this->hasher,
      new AuditService($this->connection),
      new AuditSnapshotFactory(),
      $this->security,
      $this->createMock(RequestStack::class),
    );
  }

  public function testToggleActiveThrowsForLastAdmin(): void
  {
    $admin = (new Utilisateur())
      ->setUsername('admin')
      ->setNom('Admin')
      ->setPrenom('Sys')
      ->setRole('admin')
      ->setIsActive(true);

    $this->repository->method('countActiveAdmins')->willReturn(1);

    $this->expectException(LastAdminException::class);

    $this->service->toggleActive($admin);
  }

  public function testToggleActiveLogsAccountDisabled(): void
  {
    $admin = (new Utilisateur())
      ->setUsername('admin')
      ->setNom('Admin')
      ->setPrenom('Sys')
      ->setRole('admin');

    $agent = (new Utilisateur())
      ->setUsername('agent1')
      ->setNom('Agent')
      ->setPrenom('Test')
      ->setRole('agent1')
      ->setIsActive(true);

    $this->security->method('getUser')->willReturn($admin);
    $this->entityManager->expects($this->once())->method('flush');
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'ACCOUNT_DISABLED'
        && str_contains((string) ($row['valeur_avant'] ?? ''), '"is_active":true')
        && str_contains((string) ($row['valeur_apres'] ?? ''), '"is_active":false')),
    );

    $updated = $this->service->toggleActive($agent);

    $this->assertFalse($updated->isActive());
  }

  public function testCreateUserGeneratesTemporaryPassword(): void
  {
    $this->repository->method('findOneByUsername')->willReturn(null);
    $this->hasher->method('hashPassword')->willReturn('hashed');
    $this->entityManager->expects($this->once())->method('persist');
    $this->entityManager->expects($this->once())->method('flush');

    $dto = new CreateUtilisateurRequest(
      username: 'newagent',
      nom: 'Doe',
      prenom: 'John',
      role: 'agent1',
    );

    $result = $this->service->create($dto);

    $this->assertNotEmpty($result['temporaryPassword']);
    $this->assertGreaterThanOrEqual(8, strlen($result['temporaryPassword']));
    $this->assertTrue($result['user']->isFirstConnexion());
  }

  public function testCreateThrowsWhenUsernameAlreadyExists(): void
  {
    $existing = (new Utilisateur())->setUsername('agent1');

    $this->repository->method('findOneByUsername')->willReturn($existing);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Cet identifiant est déjà utilisé.');

    $this->service->create(new CreateUtilisateurRequest(
      username: 'agent1',
      nom: 'Doe',
      prenom: 'John',
      role: 'agent1',
    ));
  }

  public function testUpdatePersistsUserFields(): void
  {
    $user = (new Utilisateur())
      ->setUsername('agent1')
      ->setNom('Old')
      ->setPrenom('Name')
      ->setRole('agent1');

    $this->entityManager->expects($this->once())->method('flush');

    $updated = $this->service->update($user, new UpdateUtilisateurRequest(
      nom: 'New',
      prenom: 'Agent',
      role: 'agent2',
    ));

    $this->assertSame('New', $updated->getNom());
    $this->assertSame('Agent', $updated->getPrenom());
    $this->assertSame('agent2', $updated->getRole());
    $this->assertInstanceOf(\DateTimeImmutable::class, $updated->getDtModification());
  }

  public function testToggleActiveReenablesAccountAndLogs(): void
  {
    $admin = (new Utilisateur())
      ->setUsername('admin')
      ->setNom('Admin')
      ->setPrenom('Sys')
      ->setRole('admin');

    $agent = (new Utilisateur())
      ->setUsername('agent1')
      ->setNom('Agent')
      ->setPrenom('Test')
      ->setRole('agent1')
      ->setIsActive(false);

    $this->security->method('getUser')->willReturn($admin);
    $this->entityManager->expects($this->once())->method('flush');
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'ACCOUNT_ENABLED'
        && str_contains((string) ($row['valeur_apres'] ?? ''), '"is_active":true')),
    );

    $updated = $this->service->toggleActive($agent);

    $this->assertTrue($updated->isActive());
  }

  public function testPresentFormatsUserForApi(): void
  {
    $user = (new Utilisateur())
      ->setUsername('agent1')
      ->setNom('Doe')
      ->setPrenom('John')
      ->setRole('agent1')
      ->setIsActive(true)
      ->setIsFirstConnexion(false)
      ->setDtLastLogin(new \DateTimeImmutable('2026-06-01T10:00:00+00:00'));

    $presented = $this->service->present($user);

    $this->assertSame('agent1', $presented['username']);
    $this->assertSame('Doe', $presented['nom']);
    $this->assertSame('agent1', $presented['role']);
    $this->assertTrue($presented['isActive']);
    $this->assertFalse($presented['isFirstConnexion']);
    $this->assertSame('2026-06-01T10:00:00+00:00', $presented['dtLastLogin']);
  }

  public function testFindPaginatedDelegatesToRepository(): void
  {
    $user = (new Utilisateur())->setUsername('agent1');

    $this->repository->method('findPaginated')->willReturn([
      'items' => [$user],
      'total' => 1,
    ]);

    $result = $this->service->findPaginated(1, 20, 'agent', 'agent1', true);

    $this->assertCount(1, $result['items']);
    $this->assertSame(1, $result['total']);
  }

  public function testResetPasswordGeneratesTemporaryPassword(): void
  {
    $admin = (new Utilisateur())
      ->setUsername('admin')
      ->setNom('Admin')
      ->setPrenom('Sys')
      ->setRole('admin');

    $user = (new Utilisateur())
      ->setUsername('agent1')
      ->setNom('Doe')
      ->setPrenom('John')
      ->setRole('agent1')
      ->setIsFirstConnexion(false)
      ->setIsActive(true);

    $this->security->method('getUser')->willReturn($admin);
    $this->hasher->method('hashPassword')->willReturn('hashed');
    $this->entityManager->expects($this->once())->method('flush');
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'RESET_PASSWORD'
        && str_contains((string) ($row['valeur_avant'] ?? ''), '"is_first_connexion":false')
        && str_contains((string) ($row['valeur_apres'] ?? ''), '"is_first_connexion":true')),
    );

    $result = $this->service->resetPassword($user);

    $this->assertNotEmpty($result['temporaryPassword']);
    $this->assertGreaterThanOrEqual(8, strlen($result['temporaryPassword']));
    $this->assertTrue($result['user']->isFirstConnexion());
    $this->assertSame(0, $result['user']->getNbreTentativesConnexion());
    $this->assertNull($result['user']->getDureeVerrouillage());
  }
}
