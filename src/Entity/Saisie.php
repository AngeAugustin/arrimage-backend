<?php
/**
 * @file    Saisie.php
 * @package App\Entity
 * @desc    Entité centrale de saisie IFU (double saisie Agent 1 / Agent 2).
 *
 * Règles métier couvertes :
 *   - RG-01 : un num_cnss unique par Agent 1
 *   - RG-04 : discordance si IFU Agent 1 ≠ IFU Agent 2
 *   - RG-07 : verrouillage après consolidation
 *   - RG-11 : sérialisation distincte pour Agent 2 (sans ifu_agent1)
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Entity;

use App\Repository\SaisieRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: SaisieRepository::class)]
#[ORM\Table(name: 'saisie')]
#[ORM\Index(name: 'idx_saisie_flag', columns: ['flag_consolide'])]
#[ORM\Index(name: 'idx_saisie_num_cnss', columns: ['num_cnss'])]
class Saisie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['saisie:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Employeur::class)]
    #[ORM\JoinColumn(name: 'num_cnss', referencedColumnName: 'num_cnss', nullable: false, unique: true)]
    #[Groups(['saisie:read', 'saisie:agent2'])]
    private ?Employeur $employeur = null;

    #[ORM\Column(name: 'ifu_agent1', length: 13)]
    #[Groups(['saisie:read'])]
    private string $ifuAgent1 = '';

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'agent1_id', nullable: false)]
    #[Groups(['saisie:read'])]
    private ?Utilisateur $agent1 = null;

    #[ORM\Column(name: 'dt_saisie1', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['saisie:read'])]
    private \DateTimeImmutable $dtSaisie1;

    #[ORM\Column(name: 'ifu_agent2', length: 13, nullable: true)]
    #[Groups(['saisie:read'])]
    private ?string $ifuAgent2 = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'agent2_id', nullable: true)]
    #[Groups(['saisie:read'])]
    private ?Utilisateur $agent2 = null;

    #[ORM\Column(name: 'dt_saisie2', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['saisie:read'])]
    private ?\DateTimeImmutable $dtSaisie2 = null;

    #[ORM\Column(name: 'dt_modif', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['saisie:read'])]
    private ?\DateTimeImmutable $dtModif = null;

    #[ORM\Column(name: 'flag_consolide', options: ['default' => false])]
    #[Groups(['saisie:read'])]
    private bool $flagConsolide = false;

    #[ORM\Column(name: 'dt_export', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['saisie:read'])]
    private ?\DateTimeImmutable $dtExport = null;

    #[ORM\Column(length: 20, options: ['default' => 'SAISIE'])]
    #[Groups(['saisie:read', 'saisie:agent2'])]
    private string $status = 'SAISIE';

    public function __construct()
    {
        $this->dtSaisie1 = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumCnss(): string
    {
        return $this->employeur?->getNumCnss() ?? '';
    }

    public function getEmployeur(): ?Employeur
    {
        return $this->employeur;
    }

    public function setEmployeur(?Employeur $employeur): static
    {
        $this->employeur = $employeur;

        return $this;
    }

    public function getIfuAgent1(): string
    {
        return $this->ifuAgent1;
    }

    public function setIfuAgent1(string $ifuAgent1): static
    {
        $this->ifuAgent1 = $ifuAgent1;

        return $this;
    }

    public function getAgent1(): ?Utilisateur
    {
        return $this->agent1;
    }

    public function setAgent1(?Utilisateur $agent1): static
    {
        $this->agent1 = $agent1;

        return $this;
    }

    public function getDtSaisie1(): \DateTimeImmutable
    {
        return $this->dtSaisie1;
    }

    public function getIfuAgent2(): ?string
    {
        return $this->ifuAgent2;
    }

    public function setIfuAgent2(?string $ifuAgent2): static
    {
        $this->ifuAgent2 = $ifuAgent2;

        return $this;
    }

    public function getAgent2(): ?Utilisateur
    {
        return $this->agent2;
    }

    public function setAgent2(?Utilisateur $agent2): static
    {
        $this->agent2 = $agent2;

        return $this;
    }

    public function getDtSaisie2(): ?\DateTimeImmutable
    {
        return $this->dtSaisie2;
    }

    public function setDtSaisie2(?\DateTimeImmutable $dtSaisie2): static
    {
        $this->dtSaisie2 = $dtSaisie2;

        return $this;
    }

    public function getDtModif(): ?\DateTimeImmutable
    {
        return $this->dtModif;
    }

    public function setDtModif(?\DateTimeImmutable $dtModif): static
    {
        $this->dtModif = $dtModif;

        return $this;
    }

    public function isFlagConsolide(): bool
    {
        return $this->flagConsolide;
    }

    public function setFlagConsolide(bool $flagConsolide): static
    {
        $this->flagConsolide = $flagConsolide;

        return $this;
    }

    public function getDtExport(): ?\DateTimeImmutable
    {
        return $this->dtExport;
    }

    public function setDtExport(?\DateTimeImmutable $dtExport): static
    {
        $this->dtExport = $dtExport;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Indique si les deux IFU sont en discordance (RG-04).
     */
    public function isDiscordant(): bool
    {
        return $this->ifuAgent2 !== null && $this->ifuAgent1 !== $this->ifuAgent2;
    }
}
