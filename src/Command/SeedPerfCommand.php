<?php
/**
 * @file    SeedPerfCommand.php
 * @package App\Command
 * @desc    Charge en masse des saisies concordantes (Agent 1 + Agent 2) pour tests de performance.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
  name: 'app:seed-perf',
  description: 'Charge des employeurs et saisies concordantes en masse pour tester la consolidation',
)]
final class SeedPerfCommand extends Command
{
  private const DEFAULT_COUNT = 1_000_000;
  private const DEFAULT_CHUNK_SIZE = 100_000;
  private const DEFAULT_PREFIX = 'PERF';

  public function __construct(
    private readonly Connection $connection,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Nombre de saisies à charger', (string) self::DEFAULT_COUNT)
      ->addOption('chunk-size', 'b', InputOption::VALUE_REQUIRED, 'Taille des lots SQL (generate_series)', (string) self::DEFAULT_CHUNK_SIZE)
      ->addOption('prefix', 'p', InputOption::VALUE_REQUIRED, 'Préfixe des numéros CNSS générés', self::DEFAULT_PREFIX)
      ->addOption('purge', null, InputOption::VALUE_NONE, 'Supprime les données existantes avec ce préfixe avant chargement')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ne pas demander de confirmation');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);

    if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
      $io->error('Cette commande nécessite PostgreSQL (generate_series).');

      return Command::FAILURE;
    }

    $count = max(1, (int) $input->getOption('count'));
    $chunkSize = max(1_000, (int) $input->getOption('chunk-size'));
    $prefix = strtoupper((string) $input->getOption('prefix'));
    $purge = (bool) $input->getOption('purge');
    $force = (bool) $input->getOption('force');

    if (strlen($prefix) < 2 || strlen($prefix) > 6) {
      $io->error('Le préfixe CNSS doit contenir entre 2 et 6 caractères.');

      return Command::FAILURE;
    }

    $suffixLength = 20 - strlen($prefix);
    if ($suffixLength < 6) {
      $io->error('Préfixe trop long pour générer des numéros CNSS uniques (max 20 caractères).');

      return Command::FAILURE;
    }

    $maxCount = (int) str_repeat('9', $suffixLength);
    if ($count > $maxCount) {
      $io->error(sprintf('Le préfixe "%s" ne permet pas de générer %d CNSS uniques (max %d).', $prefix, $count, $maxCount));

      return Command::FAILURE;
    }

    $agent1Id = $this->connection->fetchOne(
      "SELECT id FROM utilisateur WHERE username = 'agent1' AND role = 'agent1' LIMIT 1"
    );
    $agent2Id = $this->connection->fetchOne(
      "SELECT id FROM utilisateur WHERE username = 'agent2' AND role = 'agent2' LIMIT 1"
    );

    if ($agent1Id === false || $agent2Id === false) {
      $io->error('Les utilisateurs agent1 et agent2 sont requis. Exécutez d\'abord : php bin/console app:seed-dev');

      return Command::FAILURE;
    }

    $agent1Id = (int) $agent1Id;
    $agent2Id = (int) $agent2Id;

    $existing = (int) $this->connection->fetchOne(
      'SELECT COUNT(*) FROM saisie s JOIN employeur e ON e.num_cnss = s.num_cnss WHERE e.num_cnss LIKE :pattern',
      ['pattern' => $prefix . '%']
    );

    if ($existing > 0 && !$purge) {
      $io->warning(sprintf('%d saisies avec le préfixe "%s" existent déjà. Utilisez --purge pour les remplacer.', $existing, $prefix));

      return Command::FAILURE;
    }

    if (!$force && !$io->confirm(sprintf('Charger %s saisies concordantes (préfixe %s) ?', number_format($count, 0, ',', ' '), $prefix), false)) {
      $io->note('Opération annulée.');

      return Command::SUCCESS;
    }

    $startedAt = microtime(true);

    if ($purge && $existing > 0) {
      $io->section('Suppression des données de performance existantes');
      $this->purgePerfData($prefix);
      $io->writeln(sprintf('  %d saisies supprimées.', $existing));
    }

    $partialEmployeurs = (int) $this->connection->fetchOne(
      'SELECT COUNT(*) FROM employeur WHERE num_cnss LIKE :pattern',
      ['pattern' => $prefix . '%']
    );
    if ($partialEmployeurs > 0) {
      $io->warning(sprintf('%d employeurs résiduels détectés — purge automatique.', $partialEmployeurs));
      $this->connection->executeStatement(
        'DELETE FROM employeur WHERE num_cnss LIKE :pattern',
        ['pattern' => $prefix . '%']
      );
    }

    $io->section('Insertion des employeurs (SQL generate_series)');
    $employeurInserted = $this->insertEmployeursSql($prefix, $suffixLength, $count, $chunkSize, $io);

    $io->section('Insertion des saisies concordantes (SQL generate_series)');
    $saisieInserted = $this->insertSaisiesSql($prefix, $suffixLength, $count, $chunkSize, $agent1Id, $agent2Id, $io);

    $io->section('Finalisation');
    $this->connection->executeStatement("SELECT setval('saisie_id_seq', COALESCE((SELECT MAX(id) FROM saisie), 1))");
    $this->connection->executeStatement('ANALYZE employeur');
    $this->connection->executeStatement('ANALYZE saisie');

    $eligible = (int) $this->connection->fetchOne(
      'SELECT COUNT(*) FROM saisie WHERE flag_consolide = false AND ifu_agent2 IS NOT NULL AND ifu_agent1 = ifu_agent2'
    );

    $duration = microtime(true) - $startedAt;

    $io->success(sprintf(
      'Chargement terminé en %.1f s — %s employeurs, %s saisies concordantes (%s éligibles à la consolidation).',
      $duration,
      number_format($employeurInserted, 0, ',', ' '),
      number_format($saisieInserted, 0, ',', ' '),
      number_format($eligible, 0, ',', ' ')
    ));

    $io->table(['Paramètre', 'Valeur'], [
      ['Agent 1 (agent1_id)', (string) $agent1Id],
      ['Agent 2 (agent2_id)', (string) $agent2Id],
      ['Préfixe CNSS', $prefix],
      ['Statut saisies', 'CONTRE_SAISIE'],
      ['Concordance IFU', 'ifu_agent1 = ifu_agent2'],
    ]);

    return Command::SUCCESS;
  }

  private function purgePerfData(string $prefix): void
  {
    $this->connection->executeStatement(
      'DELETE FROM saisie WHERE num_cnss IN (SELECT num_cnss FROM employeur WHERE num_cnss LIKE :pattern)',
      ['pattern' => $prefix . '%']
    );
    $this->connection->executeStatement(
      'DELETE FROM employeur WHERE num_cnss LIKE :pattern',
      ['pattern' => $prefix . '%']
    );
  }

  private function insertEmployeursSql(string $prefix, int $suffixLength, int $count, int $chunkSize, SymfonyStyle $io): int
  {
    $inserted = 0;
    $io->progressStart($count);

    for ($from = 1; $from <= $count; $from += $chunkSize) {
      $to = min($from + $chunkSize - 1, $count);

      $this->connection->executeStatement(
        'INSERT INTO employeur (num_cnss, raison_sociale)
         SELECT :prefix || LPAD(i::text, CAST(:suffixLength AS int), \'0\'), \'EMPLOYEUR PERF \' || i::text
         FROM generate_series(CAST(:from AS int), CAST(:to AS int)) AS i',
        [
          'prefix' => $prefix,
          'suffixLength' => $suffixLength,
          'from' => $from,
          'to' => $to,
        ],
      );

      $batchCount = $to - $from + 1;
      $inserted += $batchCount;
      $io->progressAdvance($batchCount);
    }

    $io->progressFinish();

    return $inserted;
  }

  private function insertSaisiesSql(
    string $prefix,
    int $suffixLength,
    int $count,
    int $chunkSize,
    int $agent1Id,
    int $agent2Id,
    SymfonyStyle $io,
  ): int {
    $inserted = 0;
    $io->progressStart($count);

    for ($from = 1; $from <= $count; $from += $chunkSize) {
      $to = min($from + $chunkSize - 1, $count);

      $this->connection->executeStatement(
        'INSERT INTO saisie (
           num_cnss, ifu_agent1, agent1_id, dt_saisie1,
           ifu_agent2, agent2_id, dt_saisie2,
           flag_consolide, status
         )
         SELECT
           :prefix || LPAD(i::text, CAST(:suffixLength AS int), \'0\'),
           \'1\' || LPAD(i::text, 12, \'0\'),
           :agent1Id,
           NOW(),
           \'1\' || LPAD(i::text, 12, \'0\'),
           :agent2Id,
           NOW(),
           false,
           \'CONTRE_SAISIE\'
         FROM generate_series(CAST(:from AS int), CAST(:to AS int)) AS i',
        [
          'prefix' => $prefix,
          'suffixLength' => $suffixLength,
          'agent1Id' => $agent1Id,
          'agent2Id' => $agent2Id,
          'from' => $from,
          'to' => $to,
        ],
      );

      $batchCount = $to - $from + 1;
      $inserted += $batchCount;
      $io->progressAdvance($batchCount);
    }

    $io->progressFinish();

    return $inserted;
  }
}
