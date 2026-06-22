<?php
/**
 * @file    AuditService.php
 * @package App\Service
 * @desc    Service d'écriture dans le journal d'audit (RG-08).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\Entity\Utilisateur;
use Doctrine\DBAL\Connection;

final class AuditService
{
  public function __construct(
    private readonly Connection $connection,
  ) {
  }

  /**
   * Insère une entrée dans audit_log via DBAL (évite la récursion Doctrine).
   */
  public function log(
    ?Utilisateur $user,
    string $action,
    ?string $entiteCible = null,
    ?string $valeurAvant = null,
    ?string $valeurApres = null,
    ?string $ipAddress = null,
  ): void {
    $this->connection->insert('audit_log', [
      'user_id' => $user?->getId(),
      'action' => $action,
      'entite_cible' => $entiteCible, 
      'valeur_avant' => $valeurAvant,
      'valeur_apres' => $valeurApres,
      'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:sP'),
      'ip_address' => $ipAddress,
    ]);
  }
}
