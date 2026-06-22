<?php
/**
 * @file    JwtCookieService.php
 * @package App\Service
 * @desc    Gestion des cookies JWT HttpOnly (access + refresh).
 *
 * Règles métier couvertes :
 *   - RG-09 : access 1h, refresh 24h, cookies HttpOnly
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class JwtCookieService
{
  private const ACCESS_COOKIE = 'access_token';
  private const REFRESH_COOKIE = 'refresh_token';

  public function __construct(
    private readonly int $accessTtl,
    private readonly int $refreshTtl,
    private readonly bool $secureCookies,
  ) {
  }

  /**
   * Attache les cookies JWT à une réponse HTTP.
   */
  public function attachTokens(Response $response, string $accessToken, string $refreshToken): void
  {
    $response->headers->setCookie($this->createAccessCookie($accessToken));
    $response->headers->setCookie($this->createRefreshCookie($refreshToken));
  }

  /**
   * Invalide les cookies JWT (logout).
   */
  public function clearTokens(Response $response): void
  {
    $sameSite = $this->sameSite();
    $response->headers->clearCookie(self::ACCESS_COOKIE, '/api', null, $this->secureCookies, true, $sameSite);
    $response->headers->clearCookie(self::REFRESH_COOKIE, '/api/auth/refresh', null, $this->secureCookies, true, $sameSite);
  }

  private function createAccessCookie(string $token): Cookie
  {
    return Cookie::create(self::ACCESS_COOKIE)
      ->withValue($token)
      ->withExpires(time() + $this->accessTtl)
      ->withPath('/api')
      ->withSecure($this->secureCookies)
      ->withHttpOnly(true)
      ->withSameSite($this->sameSite());
  }

  private function createRefreshCookie(string $token): Cookie
  {
    return Cookie::create(self::REFRESH_COOKIE)
      ->withValue($token)
      ->withExpires(time() + $this->refreshTtl)
      ->withPath('/api/auth/refresh')
      ->withSecure($this->secureCookies)
      ->withHttpOnly(true)
      ->withSameSite($this->sameSite());
  }

  /**
   * Cross-origin (Vercel + Render) : SameSite=None + Secure requis.
   * Dev local (proxy Vite) : SameSite=Strict.
   */
  private function sameSite(): string
  {
    return $this->secureCookies ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_STRICT;
  }
}
