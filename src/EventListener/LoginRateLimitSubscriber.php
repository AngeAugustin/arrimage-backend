<?php
/**
 * @file    LoginRateLimitSubscriber.php
 * @package App\EventListener
 * @desc    Limite le taux de requêtes sur /api/auth/login (protection brute-force).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 10)]
final class LoginRateLimitSubscriber
{
  public function __construct(
    private readonly RateLimiterFactory $loginLimiter,
  ) {
  }

  public function __invoke(RequestEvent $event): void
  {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    if ($request->getPathInfo() !== '/api/auth/login' || !$request->isMethod('POST')) {
      return;
    }

    if (($_ENV['E2E_TEST'] ?? $_SERVER['E2E_TEST'] ?? '') === '1') {
      return;
    }

    $ip = $request->getClientIp() ?? 'unknown';
    $limiter = $this->loginLimiter->create($ip);
    $limit = $limiter->consume();

    if (!$limit->isAccepted()) {
      $event->setResponse(new JsonResponse([
        'success' => false,
        'error' => [
          'code' => 'RATE_LIMIT',
          'message' => 'Trop de tentatives de connexion. Réessayez dans quelques minutes.',
        ],
      ], 429));
    }
  }
}
