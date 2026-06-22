<?php
/**
 * @file    Employeur.php
 * @package App\Entity
 * @desc    Référentiel employeur CNSS (lecture seule pour l'application).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Entity;

use App\Repository\EmployeurRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: EmployeurRepository::class)]
#[ORM\Table(name: 'employeur')]
class Employeur
{
    #[ORM\Id]
    #[ORM\Column(name: 'num_cnss', length: 20)]
    #[Groups(['employeur:read', 'saisie:read'])]
    private string $numCnss = '';

    #[ORM\Column(name: 'raison_sociale', length: 255)]
    #[Groups(['employeur:read', 'saisie:read', 'saisie:agent2'])]
    private string $raisonSociale = '';

    public function getNumCnss(): string
    {
        return $this->numCnss;
    }

    public function setNumCnss(string $numCnss): static
    {
        $this->numCnss = $numCnss;

        return $this;
    }

    public function getRaisonSociale(): string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(string $raisonSociale): static
    {
        $this->raisonSociale = $raisonSociale;

        return $this;
    }
}
