<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\ContresaisieRequest;
use App\DTO\CorrectionRequest;
use App\DTO\SaisieRequest;
use App\Entity\Employeur;
use App\Entity\Saisie;
use App\Entity\Utilisateur;
use App\Exception\CnssNotFoundException;
use App\Exception\DuplicateSaisieException;
use App\Exception\EntiteConsolideeException;
use App\Exception\IneligibleContresaisieException;
use App\Repository\EmployeurRepository;
use App\Repository\SaisieRepository;
use App\Service\AuditService;
use App\Service\AuditSnapshotFactory;
use App\Service\SaisieService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests unitaires du service de saisie IFU.
 */
final class SaisieServiceTest extends TestCase
{
  private SaisieRepository&MockObject $saisieRepository;
  private EmployeurRepository&MockObject $employeurRepository;
  private EntityManagerInterface&MockObject $entityManager;
  private Connection&MockObject $connection;
  private SaisieService $service;

  protected function setUp(): void
  {
    $this->saisieRepository = $this->createMock(SaisieRepository::class);
    $this->employeurRepository = $this->createMock(EmployeurRepository::class);
    $this->entityManager = $this->createMock(EntityManagerInterface::class);
    $this->connection = $this->createMock(Connection::class);

    $this->service = new SaisieService(
      $this->saisieRepository,
      $this->employeurRepository,
      $this->entityManager,
      new AuditService($this->connection),
      new AuditSnapshotFactory(),
      $this->createMock(RequestStack::class),
    );
  }

  public function testCreateSaisieThrowsWhenEmployeurNotFound(): void
  {
    $this->employeurRepository->method('find')->willReturn(null);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'SAISIE_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'CNSS_NOT_FOUND')),
    );

    $this->expectException(CnssNotFoundException::class);

    $dto = new SaisieRequest(numCnss: 'CNSS001', ifu: '1234567890123', ifuConfirmation: '1234567890123');
    $this->service->createSaisie($dto, $this->createAgent());
  }

  public function testCreateSaisieThrowsWhenDuplicateCnss(): void
  {
    $employeur = (new Employeur())->setNumCnss('CNSS001')->setRaisonSociale('Test SA');
    $existing = new Saisie();

    $this->employeurRepository->method('find')->willReturn($employeur);
    $this->saisieRepository->method('findByNumCnss')->willReturn($existing);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'SAISIE_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'DUPLICATE_CNSS')),
    );

    $this->expectException(DuplicateSaisieException::class);

    $dto = new SaisieRequest(numCnss: 'CNSS001', ifu: '1234567890123', ifuConfirmation: '1234567890123');
    $this->service->createSaisie($dto, $this->createAgent());
  }

  public function testCreateSaisieSuccess(): void
  {
    $employeur = (new Employeur())->setNumCnss('CNSS001')->setRaisonSociale('Test SA');

    $this->employeurRepository->method('find')->willReturn($employeur);
    $this->saisieRepository->method('findByNumCnss')->willReturn(null);
    $this->entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(Saisie::class));
    $this->entityManager->expects($this->once())->method('flush');

    $dto = new SaisieRequest(numCnss: 'CNSS001', ifu: '1234567890123', ifuConfirmation: '1234567890123');
    $saisie = $this->service->createSaisie($dto, $this->createAgent());

    $this->assertSame('1234567890123', $saisie->getIfuAgent1());
    $this->assertSame('SAISIE', $saisie->getStatus());
  }

  public function testContresaisieLogsRefusedWhenAlreadyCountered(): void
  {
    $existing = (new Saisie())
      ->setIfuAgent1('1234567890123')
      ->setIfuAgent2('9876543210987');

    $this->saisieRepository->method('findByNumCnss')->willReturn($existing);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'CONTRESAISIE_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'ALREADY_COUNTERED')),
    );

    $this->expectException(IneligibleContresaisieException::class);

    $dto = new ContresaisieRequest(ifu: '1111111111111', ifuConfirmation: '1111111111111');
    $this->service->contresaisie('CNSS001', $dto, $this->createAgent('agent2'));
  }

  public function testGetAttenteContresaisieLogsRefusedWhenNotSaisied(): void
  {
    $this->saisieRepository->method('findByNumCnss')->willReturn(null);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'CONTRESAISIE_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'NOT_ELIGIBLE')),
    );

    $this->expectException(IneligibleContresaisieException::class);

    $this->service->getAttenteContresaisie('CNSS001', $this->createAgent('agent2'));
  }

  public function testCreateSaisieThrowsWhenEntityConsolidated(): void
  {
    $employeur = (new Employeur())->setNumCnss('CNSS001')->setRaisonSociale('Test SA');
    $existing = (new Saisie())
      ->setEmployeur($employeur)
      ->setFlagConsolide(true)
      ->setDtExport(new \DateTimeImmutable('2026-06-01 10:00:00'));

    $this->employeurRepository->method('find')->willReturn($employeur);
    $this->saisieRepository->method('findByNumCnss')->willReturn($existing);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'SAISIE_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'ENTITY_CONSOLIDATED')),
    );

    $this->expectException(EntiteConsolideeException::class);

    $dto = new SaisieRequest(numCnss: 'CNSS001', ifu: '1234567890123', ifuConfirmation: '1234567890123');
    $this->service->createSaisie($dto, $this->createAgent());
  }

  public function testGetAttenteContresaisieReturnsEligibleContext(): void
  {
    $saisie = $this->createPendingSaisie();

    $this->saisieRepository->method('findByNumCnss')->willReturn($saisie);

    $result = $this->service->getAttenteContresaisie('CNSS001', $this->createAgent('agent2'));

    $this->assertTrue($result['eligible']);
    $this->assertSame('CNSS001', $result['numCnss']);
    $this->assertSame('Test SA', $result['raisonSociale']);
  }

  public function testGetAttenteContresaisieThrowsWhenConsolidated(): void
  {
    $saisie = $this->createPendingSaisie()->setFlagConsolide(true);

    $this->saisieRepository->method('findByNumCnss')->willReturn($saisie);
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'CONTRESAISIE_REFUSEE'
        && str_contains((string) $row['valeur_apres'], 'ENTITY_CONSOLIDATED')),
    );

    $this->expectException(IneligibleContresaisieException::class);

    $this->service->getAttenteContresaisie('CNSS001', $this->createAgent('agent2'));
  }

  public function testContresaisieSuccessUpdatesSaisie(): void
  {
    $saisie = $this->createPendingSaisie();

    $this->saisieRepository->method('findByNumCnss')->willReturn($saisie);
    $this->entityManager->expects($this->once())->method('flush');
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'CONTRESAISIE'),
    );

    $dto = new ContresaisieRequest(ifu: '9876543210987', ifuConfirmation: '9876543210987');
    $result = $this->service->contresaisie('CNSS001', $dto, $this->createAgent('agent2'));

    $this->assertSame('9876543210987', $result->getIfuAgent2());
    $this->assertSame('CONTRE_SAISIE', $result->getStatus());
    $this->assertNotNull($result->getDtSaisie2());
  }

  public function testContresaisieThrowsWhenConsolidated(): void
  {
    $saisie = $this->createPendingSaisie()->setFlagConsolide(true);

    $this->saisieRepository->method('findByNumCnss')->willReturn($saisie);
    $this->connection->expects($this->once())->method('insert');

    $this->expectException(EntiteConsolideeException::class);

    $dto = new ContresaisieRequest(ifu: '9876543210987', ifuConfirmation: '9876543210987');
    $this->service->contresaisie('CNSS001', $dto, $this->createAgent('agent2'));
  }

  public function testCorrectionUpdatesIfuForAgent1(): void
  {
    $saisie = $this->createPendingSaisie()->setIfuAgent2('1111111111111');

    $this->saisieRepository->method('findByNumCnss')->willReturn($saisie);
    $this->entityManager->expects($this->once())->method('flush');
    $this->connection->expects($this->once())->method('insert')->with(
      'audit_log',
      $this->callback(static fn (array $row): bool => $row['action'] === 'CORRECTION'),
    );

    $dto = new CorrectionRequest(ifu: '2222222222222', ifuConfirmation: '2222222222222');
    $result = $this->service->correction('CNSS001', $dto, $this->createAgent('agent1'));

    $this->assertSame('2222222222222', $result->getIfuAgent1());
    $this->assertNotNull($result->getDtModif());
  }

  public function testCorrectionThrowsWhenCnssNotFound(): void
  {
    $this->saisieRepository->method('findByNumCnss')->willReturn(null);

    $this->expectException(CnssNotFoundException::class);

    $dto = new CorrectionRequest(ifu: '2222222222222', ifuConfirmation: '2222222222222');
    $this->service->correction('CNSS999', $dto, $this->createAgent('agent1'));
  }

  public function testCorrectionThrowsWhenConsolidated(): void
  {
    $saisie = $this->createPendingSaisie()->setFlagConsolide(true);

    $this->saisieRepository->method('findByNumCnss')->willReturn($saisie);

    $this->expectException(EntiteConsolideeException::class);

    $dto = new CorrectionRequest(ifu: '2222222222222', ifuConfirmation: '2222222222222');
    $this->service->correction('CNSS001', $dto, $this->createAgent('agent1'));
  }

  public function testGetCorrectionContextReturnsAgentIfu(): void
  {
    $saisie = $this->createPendingSaisie()->setIfuAgent2('9876543210987');

    $this->saisieRepository->method('findByNumCnss')->willReturn($saisie);

    $context = $this->service->getCorrectionContext('CNSS001', $this->createAgent('agent1'));

    $this->assertSame('CNSS001', $context['numCnss']);
    $this->assertSame('1234567890123', $context['ifuActuel']);
    $this->assertFalse($context['flagConsolide']);
  }

  public function testGetMesSaisiesPaginatedEnrichesRepositoryResult(): void
  {
    $agent = $this->createAgent();
    $saisie = $this->createPendingSaisie();

    $this->saisieRepository->method('findByAgentPaginated')->willReturn([
      'items' => [$saisie],
      'total' => 1,
    ]);
    $this->saisieRepository->method('getAgentDashboardStats')->willReturn([
      'today' => 1,
      'yesterday' => 0,
      'todayDelta' => 1,
      'monthTotal' => 1,
      'pending' => 0,
    ]);

    $result = $this->service->getMesSaisiesPaginated($agent, 1, 20);

    $this->assertCount(1, $result['items']);
    $this->assertSame(1, $result['total']);
    $this->assertSame(1, $result['stats']['today']);
  }

  public function testGetDiscordancesPaginatedEnrichesRepositoryResult(): void
  {
    $saisie = $this->createPendingSaisie()->setIfuAgent2('9999999999999');

    $this->saisieRepository->method('findDiscordancesPaginated')->willReturn([
      'items' => [$saisie],
      'total' => 1,
    ]);
    $this->saisieRepository->method('countRecentDiscordances')->willReturn(1);
    $this->saisieRepository->method('averageDiscordanceDelayMinutes')->willReturn(42);

    $result = $this->service->getDiscordancesPaginated(1, 20);

    $this->assertSame(1, $result['total']);
    $this->assertSame(1, $result['recentDelta']);
    $this->assertSame(42, $result['averageDelayMinutes']);
  }

  private function createPendingSaisie(): Saisie
  {
    $employeur = (new Employeur())->setNumCnss('CNSS001')->setRaisonSociale('Test SA');

    return (new Saisie())
      ->setEmployeur($employeur)
      ->setIfuAgent1('1234567890123')
      ->setAgent1($this->createAgent());
  }

  private function createAgent(string $role = 'agent1'): Utilisateur
  {
    return (new Utilisateur())
      ->setUsername($role)
      ->setNom('Test')
      ->setPrenom('Agent')
      ->setRole($role);
  }
}
