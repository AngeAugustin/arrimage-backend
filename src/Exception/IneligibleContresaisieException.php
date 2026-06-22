<?php
/**
 * @file    IneligibleContresaisieException.php
 * @package App\Exception
 * @desc    Exception levée lorsque l'Agent 2 ne peut pas contre-saisir (RG-02).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Exception;

final class IneligibleContresaisieException extends \RuntimeException
{
  public function __construct()
  {
    parent::__construct('Ce numéro employeur n\'a pas encore été saisi par l\'Agent 1 ou ne vous est pas accessible.');
  }
}
