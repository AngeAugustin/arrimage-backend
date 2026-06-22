<?php
/**
 * @file    CorrectionRequest.php
 * @package App\DTO
 * @desc    DTO de validation pour la correction IFU après discordance (UC05).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class CorrectionRequest
{
  public function __construct(
    #[Assert\NotBlank(message: "L'IFU est obligatoire.")]
    #[Assert\Regex(
      pattern: '/^\d{13}$/',
      message: "L'IFU doit contenir exactement 13 chiffres."
    )]
    public readonly string $ifu = '',

    #[Assert\NotBlank(message: "La confirmation de l'IFU est obligatoire.")]
    public readonly string $ifuConfirmation = '',
  ) {
  }

  #[Assert\Callback]
  public function validateIFUConcordance(ExecutionContextInterface $context): void
  {
    if ($this->ifu !== $this->ifuConfirmation) {
      $context->buildViolation('Les deux IFU saisis ne correspondent pas. Recommencez la saisie.')
        ->atPath('ifuConfirmation')
        ->addViolation();
    }
  }
}
