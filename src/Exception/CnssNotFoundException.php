<?php
/**
 * @file    CnssNotFoundException.php
 * @package App\Exception
 * @desc    Exception levée lorsque le numéro CNSS n'existe pas dans le référentiel.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Exception;

final class CnssNotFoundException extends \RuntimeException
{
  public function __construct()
  {
    parent::__construct('Numéro employeur CNSS non reconnu. Vérifiez le document.');
  }
}
