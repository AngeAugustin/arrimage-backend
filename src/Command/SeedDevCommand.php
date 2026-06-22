<?php
/**
 * @file    SeedDevCommand.php
 * @package App\Command
 * @desc    Initialise les données de développement (utilisateurs de test + employeurs).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Command;

use App\Entity\Employeur;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
  name: 'app:seed-dev',
  description: 'Charge les utilisateurs et employeurs de test pour le développement',
)]
final class SeedDevCommand extends Command
{
  public function __construct(
    private readonly EntityManagerInterface $entityManager,
    private readonly UserPasswordHasherInterface $passwordHasher,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);

    $users = [
      ['admin', 'admin', 'Administrateur', 'Système', 'admin', false],
      ['agent1', 'Agent1@2026', 'Koffi', 'Jean', 'agent1', false],
      ['agent2', 'Agent2@2026', 'Dossou', 'Marie', 'agent2', false],
      ['controleur', 'Ctrl@2026', 'Agbessi', 'Paul', 'controleur', false],
    ];

    foreach ($users as [$username, $password, $prenom, $nom, $role, $firstConnexion]) {
      $existing = $this->entityManager->getRepository(Utilisateur::class)->findOneBy(['username' => $username]);
      if ($existing !== null) {
        continue;
      }

      $user = new Utilisateur();
      $user->setUsername($username)
        ->setNom($nom)
        ->setPrenom($prenom)
        ->setRole($role)
        ->setIsFirstConnexion($firstConnexion)
        ->setPassword($this->passwordHasher->hashPassword($user, $password));

      $this->entityManager->persist($user);
    }

    $employeurs = [
      ['CNSS001234567', 'ENTREPRISE BENINOISE SA'],
      ['CNSS009876543', 'SOCIETE GENERALE DU BENIN'],
      ['CNSS005555555', 'COMMERCE LOCAL SARL'],
    ];

    foreach ($employeurs as [$numCnss, $raisonSociale]) {
      $existing = $this->entityManager->getRepository(Employeur::class)->find($numCnss);
      if ($existing !== null) {
        continue;
      }

      $employeur = new Employeur();
      $employeur->setNumCnss($numCnss)->setRaisonSociale($raisonSociale);
      $this->entityManager->persist($employeur);
    }

    $this->entityManager->flush();

    $io->success('Données de développement chargées.');
    $io->table(['Utilisateur', 'Mot de passe', 'Rôle'], [
      ['admin', 'admin', 'admin'],
      ['agent1', 'Agent1@2026', 'agent1'],
      ['agent2', 'Agent2@2026', 'agent2'],
      ['controleur', 'Ctrl@2026', 'controleur'],
    ]);

    return Command::SUCCESS;
  }
}
