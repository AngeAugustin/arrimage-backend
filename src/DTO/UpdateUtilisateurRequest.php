<?php
/**
 * @file    UpdateUtilisateurRequest.php
 * @package App\DTO
 * @desc    DTO de validation pour la modification d'un utilisateur (UC08).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class UpdateUtilisateurRequest
{
  public function __construct(
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 100)]
    public readonly string $nom = '',

    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 100)]
    public readonly string $prenom = '',

    #[Assert\NotBlank(message: 'Le rôle est obligatoire.')]
    #[Assert\Choice(choices: ['agent1', 'agent2', 'controleur', 'admin'], message: 'Rôle invalide.')]
    public readonly string $role = 'agent1',
  ) {
  }
}
