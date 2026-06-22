<?php
/**
 * @file    AccountDisabledException.php
 * @package App\Exception
 * @desc    Exception levée lorsque le compte est désactivé.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Exception;

final class AccountDisabledException extends \RuntimeException
{
  public function __construct()
  {
    parent::__construct('Ce compte a été désactivé. Contactez l\'administrateur.');
  }
}
