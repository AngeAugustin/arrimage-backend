<?php
/**
 * @file    JwtCookieAuthenticator.php
 * @package App\Security
 * @desc    Authenticator Symfony lisant le JWT depuis le cookie HttpOnly access_token.
 *
 * Règles métier couvertes :
 *   - RG-09 : JWT exclusivement en cookie HttpOnly
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Security;

use App\Repository\UtilisateurRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class JwtCookieAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
  public function __construct(
    private readonly JWTEncoderInterface $jwtEncoder,
    private readonly UtilisateurRepository $utilisateurRepository,
  ) {
  }

  public function supports(Request $request): ?bool
  {
    $path = $request->getPathInfo();

    if (str_starts_with($path, '/api/auth/login') || str_starts_with($path, '/api/auth/refresh')) {
      return false;
    }

    return str_starts_with($path, '/api');
  }

  public function authenticate(Request $request): SelfValidatingPassport
  {
    $token = $request->cookies->get('access_token');

    if ($token === null || $token === '') {
      throw new AuthenticationException('Token d\'accès manquant.');
    }

    try {
      $payload = $this->jwtEncoder->decode($token);
    } catch (JWTDecodeFailureException) {
      throw new AuthenticationException('Token d\'accès invalide ou expiré.');
    }

    if (($payload['type'] ?? '') === 'refresh') {
      throw new AuthenticationException('Type de token invalide.');
    }

    $username = $payload['username'] ?? '';

    return new SelfValidatingPassport(
      new UserBadge($username, fn (string $userIdentifier) => $this->utilisateurRepository->findOneByUsername($userIdentifier))
    );
  }

  public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
  {
    return null;
  }

  public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
  {
    return $this->start($request, $exception);
  }

  /**
   * Point d'entrée pour les requêtes non authentifiées.
   */
  public function start(Request $request, ?AuthenticationException $authException = null): Response
  {
    return new JsonResponse([
      'success' => false,
      'error' => [
        'code' => 'UNAUTHORIZED',
        'message' => 'Session expirée ou non authentifiée.',
      ],
    ], Response::HTTP_UNAUTHORIZED);
  }
}
