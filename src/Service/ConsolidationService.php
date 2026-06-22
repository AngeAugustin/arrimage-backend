<?php
/**
 * @file    ConsolidationService.php
 * @package App\Service
 * @desc    Consolidation atomique : export XLSX + marquage flag_consolide (RG-06).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\Entity\Utilisateur;
use App\Exception\ConsolidationException;
use App\Repository\AuditLogRepository;
use App\Repository\SaisieRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

final class ConsolidationService
{
  private const BATCH_TABLE = 'consolidation_batch';

  public function __construct(
    private readonly Connection $connection,
    private readonly SaisieRepository $saisieRepository,
    private readonly AuditLogRepository $auditLogRepository,
    private readonly AuditService $auditService,
    private readonly ExportService $exportService,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * Retourne l'aperçu des enregistrements éligibles à la consolidation.
   *
   * @return array{count: int, duplicateCount: int}
   */
  public function preview(): array
  {
    return [
      'count' => $this->saisieRepository->countRestants(null, null),
      'duplicateCount' => $this->saisieRepository->countEligibleDuplicateCnss(),
    ];
  }

  /**
   * Consolide les IFU concordants : transaction atomique export + marquage (RG-06).
   *
   * @return array{filePath: string, filename: string, count: int}
   *
   * @throws ConsolidationException
   */
  public function consolidate(Utilisateur $admin): array
  {
    $this->prepareLongRunningOperation();
    $this->connection->beginTransaction();
    $this->configureDatabaseSessionForLongTransaction();
    $filePath = null;

    try {
      $expectedCount = $this->lockEligibleBatch();
      if ($expectedCount === 0) {
        throw new ConsolidationException('Aucune donnée concordante en attente de consolidation.');
      }

      $filePath = $this->exportService->createTempXlsxPath();
      $exportedCount = $this->exportService->writeConsolidationXlsxToFile(
        $this->iterateLockedBatchRows(),
        $filePath,
      );

      if ($exportedCount !== $expectedCount) {
        throw new ConsolidationException(sprintf(
          'Consolidation annulée : export incomplet (%d/%d lignes). Aucune donnée n\'a été modifiée.',
          $exportedCount,
          $expectedCount,
        ));
      }

      $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

      $updated = (int) $this->connection->executeStatement(
        'UPDATE saisie s
         SET flag_consolide = true, dt_export = :now, status = :status
         FROM ' . self::BATCH_TABLE . ' b
         WHERE s.id = b.id
           AND s.flag_consolide = false',
        ['now' => $now, 'status' => 'CONSOLIDE'],
      );

      if ($updated !== $expectedCount) {
        throw new ConsolidationException(sprintf(
          'Consolidation annulée : marquage incomplet (%d/%d lignes). Aucune donnée n\'a été modifiée.',
          $updated,
          $expectedCount,
        ));
      }

      $ip = $this->requestStack->getCurrentRequest()?->getClientIp();
      $this->auditService->log(
        user: $admin,
        action: 'CONSOLIDATION',
        entiteCible: 'saisie',
        valeurApres: json_encode(['count' => $expectedCount, 'dt_export' => $now]),
        ipAddress: $ip,
      );

      $this->connection->commit();

      $filename = 'consolidation_ifu_' . date('Ymd_His') . '.xlsx';

      return [
        'filePath' => $filePath,
        'filename' => $filename,
        'count' => $expectedCount,
      ];
    } catch (ConsolidationException $e) {
      $this->abortConsolidation($filePath);
      throw $e;
    } catch (\Throwable $e) {
      $this->abortConsolidation($filePath);
      throw new ConsolidationException('Échec de la consolidation : ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Régénère le fichier XLSX d'un export déjà effectué (re-téléchargement).
   *
   * @return array{filePath: string, filename: string, count: int}
   *
   * @throws ConsolidationException
   */
  public function regenerateExportForAuditLog(int $auditLogId): array
  {
    $auditLog = $this->auditLogRepository->find($auditLogId);
    if ($auditLog === null || $auditLog->getAction() !== 'CONSOLIDATION') {
      throw new ConsolidationException('Export introuvable.');
    }

    $payload = json_decode($auditLog->getValeurApres() ?? '', true);
    if (!is_array($payload) || empty($payload['dt_export'])) {
      throw new ConsolidationException('Impossible de retrouver les données de cet export.');
    }

    $dtExport = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $payload['dt_export']);
    if ($dtExport === false) {
      throw new ConsolidationException('Date d\'export invalide.');
    }

    $expectedCount = (int) ($payload['count'] ?? 0);
    if ($expectedCount <= 0) {
      throw new ConsolidationException('Aucune donnée consolidée pour cet export.');
    }

    $filePath = $this->exportService->createTempXlsxPath();
    $exportedCount = $this->exportService->writeConsolidationXlsxToFile(
      $this->saisieRepository->iterateConsolidatedRowsByDtExport($dtExport),
      $filePath,
    );

    if ($exportedCount !== $expectedCount) {
      $this->removeTempFile($filePath);
      throw new ConsolidationException(sprintf(
        'Export incomplet : %d ligne(s) retrouvée(s) sur %d attendue(s).',
        $exportedCount,
        $expectedCount,
      ));
    }

    $filename = 'consolidation_ifu_' . $dtExport->format('Ymd_His') . '.xlsx';

    return [
      'filePath' => $filePath,
      'filename' => $filename,
      'count' => $expectedCount,
    ];
  }

  /**
   * Verrouille le lot éligible et retourne le nombre de lignes concernées.
   */
  private function lockEligibleBatch(): int
  {
    $this->connection->executeStatement(
      'CREATE TEMP TABLE ' . self::BATCH_TABLE . ' (id BIGINT PRIMARY KEY) ON COMMIT DROP',
    );

    return (int) $this->connection->executeStatement(
      'INSERT INTO ' . self::BATCH_TABLE . ' (id)
       SELECT s.id
       FROM saisie s
       WHERE s.flag_consolide = false
         AND s.ifu_agent2 IS NOT NULL
         AND s.ifu_agent1 = s.ifu_agent2
       FOR UPDATE',
    );
  }

  /**
   * @return iterable<array{numCnss: string, ifu: string, raisonSociale: string}>
   */
  private function iterateLockedBatchRows(): iterable
  {
    $result = $this->connection->executeQuery(
      'SELECT e.num_cnss, s.ifu_agent1 AS ifu, e.raison_sociale
       FROM saisie s
       INNER JOIN employeur e ON e.num_cnss = s.num_cnss
       INNER JOIN ' . self::BATCH_TABLE . ' b ON b.id = s.id
       ORDER BY e.num_cnss ASC',
    );

    foreach ($result->iterateAssociative() as $row) {
      yield [
        'numCnss' => (string) $row['num_cnss'],
        'ifu' => (string) $row['ifu'],
        'raisonSociale' => (string) $row['raison_sociale'],
      ];
    }
  }

  private function abortConsolidation(?string $filePath): void
  {
    if ($this->connection->isTransactionActive()) {
      $this->connection->rollBack();
    }

    $this->removeTempFile($filePath);
  }

  private function removeTempFile(?string $filePath): void
  {
    if ($filePath !== null && is_file($filePath)) {
      unlink($filePath);
    }
  }

  private function prepareLongRunningOperation(): void
  {
    @ini_set('memory_limit', '1024M');
    @set_time_limit(0);
  }

  private function configureDatabaseSessionForLongTransaction(): void
  {
    if (!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
      return;
    }

    $this->connection->executeStatement('SET LOCAL statement_timeout = 0');
    $this->connection->executeStatement('SET LOCAL idle_in_transaction_session_timeout = 0');
  }
}
