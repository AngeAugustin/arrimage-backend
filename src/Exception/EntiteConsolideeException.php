<?php
/**
 * @file    EntiteConsolideeException.php
 * @package App\Exception
 * @desc    Exception levée lors d'une tentative de modification d'une saisie consolidée (RG-07).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Exception;

final class EntiteConsolideeException extends \RuntimeException
{
  public function __construct(\DateTimeImmutable $dtExport)
  {
    parent::__construct(sprintf(
      'Cette saisie a été consolidée le %s. Toute modification est impossible. Contactez l\'administrateur.',
      $dtExport->format('d/m/Y H:i')
    ));
  }
}
