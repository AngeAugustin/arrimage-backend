<?php
/**
 * @file    AuditLogSubscriber.php
 * @package App\EventListener
 * @desc    Audit automatique : SAISIE, CREATE_USER, UPDATE_USER (RG-08).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\EventListener;

use App\Entity\AuditLog;
use App\Entity\Saisie;
use App\Entity\Utilisateur;
use App\Service\AuditService;
use App\Service\AuditSnapshotFactory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class AuditLogSubscriber
{
  /** @var array<class-string, string> */
  private const CREATE_ACTIONS = [
    Saisie::class => 'SAISIE',
    Utilisateur::class => 'CREATE_USER',
  ];

  /** @var list<string> */
  private const LOGIN_ONLY_FIELDS = [
    'dtLastLogin',
    'nbreTentativesConnexion',
    'dureeVerrouillage',
  ];

  /** @var list<string> */
  private const ACCOUNT_STATUS_ONLY_FIELDS = [
    'isActive',
    'dtModification',
  ];

  /** @var list<string> */
  private const PASSWORD_RESET_ONLY_FIELDS = [
    'password',
    'isFirstConnexion',
    'nbreTentativesConnexion',
    'dureeVerrouillage',
    'dtModification',
  ];

  public function __construct(
    private readonly Security $security,
    private readonly RequestStack $requestStack,
    private readonly AuditService $auditService,
    private readonly AuditSnapshotFactory $auditSnapshotFactory,
  ) {
  }

  public function postPersist(PostPersistEventArgs $args): void
  {
    $entity = $args->getObject();

    if ($entity instanceof AuditLog) {
      return;
    }

    $entityClass = $entity::class;
    if (!isset(self::CREATE_ACTIONS[$entityClass])) {
      return;
    }

    $currentUser = $this->security->getUser();
    if (!$currentUser instanceof Utilisateur) {
      return;
    }

    $snapshot = match (true) {
      $entity instanceof Saisie => $this->auditSnapshotFactory->saisie($entity),
      $entity instanceof Utilisateur => $this->auditSnapshotFactory->utilisateur($entity),
      default => [],
    };

    $this->writeLog(
      $currentUser,
      self::CREATE_ACTIONS[$entityClass],
      $entityClass,
      valeurApres: json_encode($snapshot),
    );
  }

  public function postUpdate(PostUpdateEventArgs $args): void
  {
    $entity = $args->getObject();

    if (!$entity instanceof Utilisateur || $entity instanceof AuditLog) {
      return;
    }

    $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
    $changedFields = array_keys($changeSet);
    if ($this->isLoginOnlyUpdate($changedFields)
      || $this->isAccountStatusOnlyUpdate($changedFields)
      || $this->isPasswordResetOnlyUpdate($changedFields)) {
      return;
    }

    $currentUser = $this->security->getUser();
    if (!$currentUser instanceof Utilisateur) {
      return;
    }

    $this->writeLog(
      $currentUser,
      'UPDATE_USER',
      $entity::class,
      valeurApres: json_encode($this->auditSnapshotFactory->utilisateur($entity)),
    );
  }

  /**
   * @param list<string> $changedFields
   */
  private function isLoginOnlyUpdate(array $changedFields): bool
  {
    if ($changedFields === []) {
      return true;
    }

    return array_diff($changedFields, self::LOGIN_ONLY_FIELDS) === [];
  }

  /**
   * @param list<string> $changedFields
   */
  private function isAccountStatusOnlyUpdate(array $changedFields): bool
  {
    if ($changedFields === []) {
      return false;
    }

    return array_diff($changedFields, self::ACCOUNT_STATUS_ONLY_FIELDS) === [];
  }

  /**
   * @param list<string> $changedFields
   */
  private function isPasswordResetOnlyUpdate(array $changedFields): bool
  {
    if ($changedFields === []) {
      return false;
    }

    return array_diff($changedFields, self::PASSWORD_RESET_ONLY_FIELDS) === [];
  }

  private function writeLog(
    Utilisateur $user,
    string $action,
    string $entiteCible,
    ?string $valeurAvant = null,
    ?string $valeurApres = null,
  ): void {
    $this->auditService->log(
      user: $user,
      action: $action,
      entiteCible: $entiteCible,
      valeurAvant: $valeurAvant,
      valeurApres: $valeurApres,
      ipAddress: $this->requestStack->getCurrentRequest()?->getClientIp(),
    );
  }
}
