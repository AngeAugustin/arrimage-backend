<?php
/**
 * @file    ConsolidateCliCommand.php
 * @package App\Command
 * @desc    Lance une consolidation depuis la CLI (tests de performance hors HTTP).
 */

namespace App\Command;

use App\Entity\Utilisateur;
use App\Exception\ConsolidationException;
use App\Service\ConsolidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
  name: 'app:consolidate-cli',
  description: 'Lance une consolidation atomique depuis la ligne de commande',
)]
final class ConsolidateCliCommand extends Command
{
  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly ConsolidationService $consolidationService,
  ) {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Compte admin déclencheur', 'admin');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $username = (string) $input->getOption('username');

    $admin = $this->entityManager->getRepository(Utilisateur::class)->findOneBy(['username' => $username]);
    if ($admin === null || $admin->getRole() !== 'admin') {
      $io->error(sprintf('Utilisateur admin "%s" introuvable.', $username));

      return Command::FAILURE;
    }

    $startedAt = microtime(true);

    try {
      $result = $this->consolidationService->consolidate($admin);
    } catch (ConsolidationException $e) {
      $io->error($e->getMessage());

      return Command::FAILURE;
    }

    $duration = microtime(true) - $startedAt;

    $io->success(sprintf(
      '%s lignes consolidées en %.1f s — fichier : %s',
      number_format($result['count'], 0, ',', ' '),
      $duration,
      $result['filePath'],
    ));

    return Command::SUCCESS;
  }
}
