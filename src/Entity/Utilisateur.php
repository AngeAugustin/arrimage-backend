<?php
/**
 * @file    Utilisateur.php
 * @package App\Entity
 * @desc    Entité utilisateur de l'application Arrimage IFU (agents, contrôleur, admin).
 *
 * Règles métier couvertes :
 *   - RG-12 : protection du dernier admin actif
 *   - RG-14 : forçage changement mot de passe à la première connexion
 *   - RG-15 : mot de passe hashé bcrypt (coût ≥ 12)
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Entity;

use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'user:admin'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Groups(['user:read', 'user:admin'])]
    private string $username = '';

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(length: 100)]
    #[Groups(['user:read', 'user:admin'])]
    private string $nom = '';

    #[ORM\Column(length: 100)]
    #[Groups(['user:read', 'user:admin'])]
    private string $prenom = '';

    #[ORM\Column(length: 20)]
    #[Groups(['user:read', 'user:admin'])]
    private string $role = 'agent1';

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    #[Groups(['user:admin'])]
    private bool $isActive = true;

    #[ORM\Column(name: 'is_first_connexion', options: ['default' => true])]
    #[Groups(['user:read', 'user:admin'])]
    private bool $isFirstConnexion = true;

    #[ORM\Column(name: 'dt_creation', type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['user:admin'])]
    private \DateTimeImmutable $dtCreation;

    #[ORM\Column(name: 'dt_modification', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['user:admin'])]
    private ?\DateTimeImmutable $dtModification = null;

    #[ORM\Column(name: 'dt_last_login', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['user:admin'])]
    private ?\DateTimeImmutable $dtLastLogin = null;

    #[ORM\Column(name: 'nbre_tentatives_connexion', type: Types::SMALLINT, options: ['default' => 0])]
    private int $nbreTentativesConnexion = 0;

    #[ORM\Column(name: 'duree_verrouillage', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dureeVerrouillage = null;

    public function __construct()
    {
        $this->dtCreation = new \DateTimeImmutable();
    }
 
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return match ($this->role) {
            'admin' => ['ROLE_ADMIN'],
            'controleur' => ['ROLE_CONTROLEUR'],
            'agent2' => ['ROLE_AGENT2'],
            default => ['ROLE_AGENT1'],
        };
    }

    public function eraseCredentials(): void
    {
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function isFirstConnexion(): bool
    {
        return $this->isFirstConnexion;
    }

    public function setIsFirstConnexion(bool $isFirstConnexion): static
    {
        $this->isFirstConnexion = $isFirstConnexion;

        return $this;
    }

    public function getDtCreation(): \DateTimeImmutable
    {
        return $this->dtCreation;
    }

    public function getDtModification(): ?\DateTimeImmutable
    {
        return $this->dtModification;
    }

    public function setDtModification(?\DateTimeImmutable $dtModification): static
    {
        $this->dtModification = $dtModification;

        return $this;
    }

    public function getDtLastLogin(): ?\DateTimeImmutable
    {
        return $this->dtLastLogin;
    }

    public function setDtLastLogin(?\DateTimeImmutable $dtLastLogin): static
    {
        $this->dtLastLogin = $dtLastLogin;

        return $this;
    }

    public function getNbreTentativesConnexion(): int
    {
        return $this->nbreTentativesConnexion;
    }

    public function setNbreTentativesConnexion(int $nbre): static
    {
        $this->nbreTentativesConnexion = $nbre;

        return $this;
    }

    public function getDureeVerrouillage(): ?\DateTimeImmutable
    {
        return $this->dureeVerrouillage;
    }

    public function setDureeVerrouillage(?\DateTimeImmutable $duree): static
    {
        $this->dureeVerrouillage = $duree;

        return $this;
    }
}
