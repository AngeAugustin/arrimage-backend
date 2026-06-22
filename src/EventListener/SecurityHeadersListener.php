<?php
/**
 * @file    SecurityHeadersListener.php
 * @package App\EventListener
 * @desc    Ajoute les en-têtes HTTP de sécurité sur toutes les réponses API.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class SecurityHeadersListener
{
  public function __construct(
    private readonly string $environment,
  ) {
  }

  public function __invoke(ResponseEvent $event): void
  {
    if (!$event->isMainRequest()) {
      return;
    }

    $headers = $event->getResponse()->headers;

    $headers->set('X-Content-Type-Options', 'nosniff');
    $headers->set('X-Frame-Options', 'DENY');
    $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    $headers->set('X-XSS-Protection', '0');

    if ($this->environment === 'prod') {
      $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
  }
}
