<?php
/**
 * @file    DuplicateSaisieException.php
 * @package App\Exception
 * @desc    Exception levée lorsque le num_cnss est déjà saisi par Agent 1 (RG-01).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Exception;

final class DuplicateSaisieException extends \RuntimeException
{
  public function __construct()
  {
    parent::__construct('Ce numéro CNSS a déjà été saisi. Accédez à la correction si nécessaire.');
  }
}
