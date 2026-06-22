<?php
/**
 * @file    AuditLogPresenter.php
 * @package App\Service
 * @desc    Formate les entrées du journal d'audit pour l'API (UC09).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\Entity\AuditLog;

final class AuditLogPresenter
{
  /**
   * @return array<string, mixed>
   */
  public function present(AuditLog $log): array
  {
    $user = $log->getUser();

    return [
      'id' => $log->getId(),
      'timestamp' => $log->getTimestamp()->format(\DateTimeInterface::ATOM),
      'user' => $user ? [
        'id' => $user->getId(),
        'nom' => $user->getNom(),
        'prenom' => $user->getPrenom(),
        'username' => $user->getUsername(),
      ] : null,
      'action' => $log->getAction(),
      'entiteCible' => $this->shortEntiteCible($log->getEntiteCible()),
      'valeurAvant' => $log->getValeurAvant(),
      'valeurApres' => $log->getValeurApres(),
      'ipAddress' => $log->getIpAddress(),
    ];
  }

  private function shortEntiteCible(?string $entiteCible): ?string
  {
    if ($entiteCible === null || $entiteCible === '') {
      return null;
    }

    if (str_contains($entiteCible, '\\')) {
      $parts = explode('\\', $entiteCible);

      return end($parts) ?: $entiteCible;
    }

    return $entiteCible;
  }
}
