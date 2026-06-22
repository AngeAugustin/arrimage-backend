<?php
/**
 * @file    LoginRequest.php
 * @package App\DTO
 * @desc    DTO de validation pour la connexion (UC01).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class LoginRequest
{
  public function __construct(
    #[Assert\NotBlank(message: "L'identifiant est obligatoire.")]
    #[Assert\Length(max: 50)]
    public readonly string $username = '',

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
    public readonly string $password = '',
  ) {
  }
}
