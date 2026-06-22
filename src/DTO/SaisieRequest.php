<?php
/**
 * @file    SaisieRequest.php
 * @package App\DTO
 * @desc    DTO de validation pour la première saisie IFU (UC02).
 *
 * Règles métier couvertes :
 *   - RG-03 : concordance double saisie IFU
 *   - RG-16 : format IFU 13 chiffres
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SaisieRequest
{
  public function __construct(
    #[Assert\NotBlank(message: 'Le numéro CNSS est obligatoire.')]
    #[Assert\Length(max: 20)]
    public readonly string $numCnss = '',

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
