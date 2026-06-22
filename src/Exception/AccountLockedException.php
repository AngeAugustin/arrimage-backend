<?php
/**
 * @file    AccountLockedException.php
 * @package App\Exception
 * @desc    Exception levée lorsque le compte est temporairement verrouillé.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Exception;

final class AccountLockedException extends \RuntimeException
{
  public function __construct()
  {
    parent::__construct('Compte temporairement verrouillé suite à plusieurs tentatives échouées. Réessayez dans quelques minutes.');
  }
}
