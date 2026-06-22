<?php
/**
 * @file    AuditSnapshotFactory.php
 * @package App\Service
 * @desc    Snapshots JSON pour le journal d'audit (RG-08).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\Entity\Saisie;
use App\Entity\Utilisateur;

final class AuditSnapshotFactory
{
  /**
   * @return array<string, mixed>
   */
  public function saisie(Saisie $saisie): array
  {
    return [
      'num_cnss' => $saisie->getNumCnss(),
      'ifu_agent1' => $saisie->getIfuAgent1(),
      'ifu_agent2' => $saisie->getIfuAgent2(),
      'flag_consolide' => $saisie->isFlagConsolide(),
      'status' => $saisie->getStatus(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function utilisateur(Utilisateur $user): array
  {
    return [
      'id' => $user->getId(),
      'username' => $user->getUsername(),
      'role' => $user->getRole(),
      'is_active' => $user->isActive(),
      'is_first_connexion' => $user->isFirstConnexion(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function login(Utilisateur $user): array
  {
    return [
      'username' => $user->getUsername(),
      'role' => $user->getRole(),
      'dt_last_login' => $user->getDtLastLogin()?->format(\DateTimeInterface::ATOM),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function refusedAttempt(
    string $reason,
    ?string $numCnss = null,
    ?string $ifuAttempted = null,
    ?Saisie $existing = null,
  ): array {
    $payload = ['reason' => $reason];

    if ($numCnss !== null) {
      $payload['num_cnss'] = $numCnss;
    }

    if ($ifuAttempted !== null) {
      $payload['ifu_attempted'] = $ifuAttempted;
    }

    if ($existing !== null) {
      $payload['existing'] = $this->saisie($existing);
    }

    return $payload;
  }

  /**
   * @return array<string, mixed>
   */
  public function refusedLogin(
    string $reason,
    string $username,
    ?Utilisateur $user = null,
    ?int $attempts = null,
    ?int $lockDurationMinutes = null,
    ?\DateTimeImmutable $lockedUntil = null,
  ): array {
    $payload = [
      'reason' => $reason,
      'username' => $username,
    ];

    if ($user !== null) {
      $payload['user'] = [
        'id' => $user->getId(),
        'username' => $user->getUsername(),
        'role' => $user->getRole(),
      ];
    }

    if ($attempts !== null) {
      $payload['attempts'] = $attempts;
    }

    if ($lockDurationMinutes !== null) {
      $payload['lock_duration_minutes'] = $lockDurationMinutes;
    }

    if ($lockedUntil !== null) {
      $payload['locked_until'] = $lockedUntil->format(\DateTimeInterface::ATOM);
    }

    return $payload;
  }
}
