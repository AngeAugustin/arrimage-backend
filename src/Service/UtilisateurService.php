<?php
/**
 * @file    UtilisateurService.php
 * @package App\Service
 * @desc    Gestion des utilisateurs : création, modification, activation (UC08).
 *
 * Règles métier couvertes :
 *   - RG-12 : dernier admin protégé
 *   - RG-14 : mot de passe temporaire + is_first_connexion
 *   - RG-15 : bcrypt coût ≥ 12
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\DTO\CreateUtilisateurRequest;
use App\DTO\UpdateUtilisateurRequest;
use App\Entity\Utilisateur;
use App\Exception\LastAdminException;
use App\Repository\UtilisateurRepository;
use App\Service\AuditService;
use App\Service\AuditSnapshotFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UtilisateurService
{
  public function __construct(
    private readonly UtilisateurRepository $utilisateurRepository,
    private readonly EntityManagerInterface $entityManager,
    private readonly UserPasswordHasherInterface $passwordHasher,
    private readonly AuditService $auditService,
    private readonly AuditSnapshotFactory $auditSnapshotFactory,
    private readonly Security $security,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * @return array{items: list<Utilisateur>, total: int}
   */
  public function findPaginated(
    int $page,
    int $limit,
    ?string $search = null,
    ?string $role = null,
    ?bool $isActive = null,
  ): array {
    return $this->utilisateurRepository->findPaginated($page, $limit, $search, $role, $isActive);
  }

  public function countActive(): int
  {
    return $this->utilisateurRepository->countActive();
  }

  /**
   * @return list<Utilisateur>
   */
  public function findAll(): array
  {
    return $this->utilisateurRepository->findBy([], ['nom' => 'ASC']);
  }

  /**
   * Crée un utilisateur avec mot de passe temporaire (RG-14).
   *
   * @return array{user: Utilisateur, temporaryPassword: string}
   */
  public function create(CreateUtilisateurRequest $dto): array
  {
    if ($this->utilisateurRepository->findOneByUsername($dto->username) !== null) {
      throw new \RuntimeException('Cet identifiant est déjà utilisé.');
    }

    $tempPassword = $this->generateTemporaryPassword();

    $user = new Utilisateur();
    $user->setUsername($dto->username)
      ->setNom($dto->nom)
      ->setPrenom($dto->prenom)
      ->setRole($dto->role)
      ->setIsFirstConnexion(true)
      ->setIsActive(true)
      ->setPassword($this->passwordHasher->hashPassword($user, $tempPassword));

    $this->entityManager->persist($user);
    $this->entityManager->flush();

    return ['user' => $user, 'temporaryPassword' => $tempPassword];
  }

  /**
   * Réinitialise le mot de passe d'un utilisateur (RG-14).
   *
   * @return array{user: Utilisateur, temporaryPassword: string}
   */
  public function resetPassword(Utilisateur $user): array
  {
    $valeurAvant = json_encode($this->auditSnapshotFactory->utilisateur($user));
    $tempPassword = $this->generateTemporaryPassword();

    $user->setIsFirstConnexion(true)
      ->setPassword($this->passwordHasher->hashPassword($user, $tempPassword))
      ->setNbreTentativesConnexion(0)
      ->setDureeVerrouillage(null)
      ->setDtModification(new \DateTimeImmutable());

    $this->entityManager->flush();

    $this->logTargetUserAction('RESET_PASSWORD', $user, $valeurAvant);

    return ['user' => $user, 'temporaryPassword' => $tempPassword];
  }

  public function update(Utilisateur $user, UpdateUtilisateurRequest $dto): Utilisateur
  {
    $user->setNom($dto->nom)
      ->setPrenom($dto->prenom)
      ->setRole($dto->role)
      ->setDtModification(new \DateTimeImmutable());

    $this->entityManager->flush();

    return $user;
  }

  /**
   * Active ou désactive un utilisateur (RG-12).
   *
   * @throws LastAdminException
   */
  public function toggleActive(Utilisateur $user): Utilisateur
  {
    if ($user->isActive() && $user->getRole() === 'admin') {
      if ($this->utilisateurRepository->countActiveAdmins() <= 1) {
        throw new LastAdminException();
      }
    }

    $valeurAvant = json_encode($this->auditSnapshotFactory->utilisateur($user));
    $user->setIsActive(!$user->isActive());
    $user->setDtModification(new \DateTimeImmutable());
    $this->entityManager->flush();

    $action = $user->isActive() ? 'ACCOUNT_ENABLED' : 'ACCOUNT_DISABLED';
    $this->logTargetUserAction($action, $user, $valeurAvant);

    return $user;
  }

  /**
   * @return array<string, mixed>
   */
  public function present(Utilisateur $user): array
  {
    return [
      'id' => $user->getId(),
      'username' => $user->getUsername(),
      'nom' => $user->getNom(),
      'prenom' => $user->getPrenom(),
      'role' => $user->getRole(),
      'isActive' => $user->isActive(),
      'isFirstConnexion' => $user->isFirstConnexion(),
      'dtCreation' => $user->getDtCreation()->format(\DateTimeInterface::ATOM),
      'dtLastLogin' => $user->getDtLastLogin()?->format(\DateTimeInterface::ATOM),
    ];
  }

  private function generateTemporaryPassword(): string
  {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $digits = '23456789';
    $all = $upper . $lower . $digits;

    $password = $upper[random_int(0, strlen($upper) - 1)]
      . $digits[random_int(0, strlen($digits) - 1)];

    for ($i = 0; $i < 8; ++$i) {
      $password .= $all[random_int(0, strlen($all) - 1)];
    }

    return str_shuffle($password);
  }

  private function logTargetUserAction(string $action, Utilisateur $target, string $valeurAvant): void
  {
    $actor = $this->security->getUser();
    if (!$actor instanceof Utilisateur) {
      return;
    }

    $this->auditService->log(
      user: $actor,
      action: $action,
      entiteCible: Utilisateur::class,
      valeurAvant: $valeurAvant,
      valeurApres: json_encode($this->auditSnapshotFactory->utilisateur($target)),
      ipAddress: $this->requestStack->getCurrentRequest()?->getClientIp(),
    );
  }
}
