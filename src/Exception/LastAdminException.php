<?php
/**
 * @file    LastAdminException.php
 * @package App\Exception
 * @desc    Exception levée lors de la tentative de désactivation du dernier admin (RG-12).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Exception;

final class LastAdminException extends \RuntimeException
{
  public function __construct()
  {
    parent::__construct('Impossible de désactiver le dernier administrateur actif du système.');
  }
}
