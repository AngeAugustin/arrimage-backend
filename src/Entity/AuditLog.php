<?php
/**
 * @file    AuditLog.php
 * @package App\Entity
 * @desc    Journal d'audit append-only (lecture seule pour tous les utilisateurs).
 *
 * Règles métier couvertes :
 *   - RG-08 : traçabilité complète des actions
 *   - RG-13 : aucune modification autorisée
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['audit:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true)]
    #[Groups(['audit:read'])]
    private ?Utilisateur $user = null;

    #[ORM\Column(length: 50)]
    #[Groups(['audit:read'])]
    private string $action = '';

    #[ORM\Column(name: 'entite_cible', length: 50, nullable: true)]
    #[Groups(['audit:read'])]
    private ?string $entiteCible = null;

    #[ORM\Column(name: 'valeur_avant', type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read'])]
    private ?string $valeurAvant = null;

    #[ORM\Column(name: 'valeur_apres', type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read'])]
    private ?string $valeurApres = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    #[Groups(['audit:read'])]
    private \DateTimeImmutable $timestamp;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    #[Groups(['audit:read'])]
    private ?string $ipAddress = null;

    public function __construct()
    {
        $this->timestamp = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?Utilisateur
    {
        return $this->user;
    }

    public function setUser(?Utilisateur $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getEntiteCible(): ?string
    {
        return $this->entiteCible;
    }

    public function setEntiteCible(?string $entiteCible): static
    {
        $this->entiteCible = $entiteCible;

        return $this;
    }

    public function getValeurAvant(): ?string
    {
        return $this->valeurAvant;
    }

    public function setValeurAvant(?string $valeurAvant): static
    {
        $this->valeurAvant = $valeurAvant;

        return $this;
    }

    public function getValeurApres(): ?string
    {
        return $this->valeurApres;
    }

    public function setValeurApres(?string $valeurApres): static
    {
        $this->valeurApres = $valeurApres;

        return $this;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }
}
