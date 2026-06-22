<?php
/**
 * @file    ChangePasswordRequest.php
 * @package App\DTO
 * @desc    DTO de validation pour le changement de mot de passe (UC10).
 *
 * Règles métier couvertes :
 *   - RG-14 : is_first_connexion → false après changement
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ChangePasswordRequest
{
  public function __construct(
    #[Assert\NotBlank(message: 'Le mot de passe actuel est obligatoire.')]
    public readonly string $currentPassword = '',

    #[Assert\NotBlank(message: 'Le nouveau mot de passe est obligatoire.')]
    #[Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins 8 caractères.')]
    public readonly string $newPassword = '',

    #[Assert\NotBlank(message: 'La confirmation est obligatoire.')]
    public readonly string $confirmPassword = '',
  ) {
  }

  #[Assert\Callback]
  public function validatePasswordStrength(ExecutionContextInterface $context): void
  {
    if ($this->newPassword !== '' && !preg_match('/[A-Z]/', $this->newPassword)) {
      $context->buildViolation('Le mot de passe doit contenir au moins une majuscule.')
        ->atPath('newPassword')
        ->addViolation();
    }

    if ($this->newPassword !== '' && !preg_match('/\d/', $this->newPassword)) {
      $context->buildViolation('Le mot de passe doit contenir au moins un chiffre.')
        ->atPath('newPassword')
        ->addViolation();
    }
  }

  #[Assert\Callback]
  public function validateConfirmation(ExecutionContextInterface $context): void
  {
    if ($this->newPassword !== $this->confirmPassword) {
      $context->buildViolation('Les mots de passe ne correspondent pas.')
        ->atPath('confirmPassword')
        ->addViolation();
    }
  }
}
