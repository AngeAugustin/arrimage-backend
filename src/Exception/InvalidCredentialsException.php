<?php
/**
 * @file    InvalidCredentialsException.php
 * @package App\Exception
 * @desc    Exception levée lors d'identifiants invalides.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Exception;

final class InvalidCredentialsException extends \RuntimeException
{
  public function __construct()
  {
    parent::__construct('Identifiant ou mot de passe incorrect.');
  }
}
