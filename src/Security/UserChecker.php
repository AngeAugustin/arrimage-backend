<?php
/**
 * @file    UserChecker.php
 * @package App\Security
 * @desc    Vérifications supplémentaires sur l'utilisateur avant authentification.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Security;

use App\Entity\Utilisateur;
use App\Exception\AccountDisabledException;
use App\Exception\AccountLockedException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
  public function checkPreAuth(UserInterface $user): void
  {
    if (!$user instanceof Utilisateur) {
      return;
    }

    if (!$user->isActive()) {
      throw new CustomUserMessageAccountStatusException(
        (new AccountDisabledException())->getMessage()
      );
    }

    $lockUntil = $user->getDureeVerrouillage();
    if ($lockUntil !== null && $lockUntil > new \DateTimeImmutable()) {
      throw new CustomUserMessageAccountStatusException(
        (new AccountLockedException())->getMessage()
      );
    }
  }

  public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
  {
  }
}
