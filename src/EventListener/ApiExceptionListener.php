<?php
/**
 * @file    ApiExceptionListener.php
 * @package App\EventListener
 * @desc    Transforme toutes les exceptions API en réponses JSON standardisées.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\EventListener;

use App\Exception\AccountDisabledException;
use App\Exception\AccountLockedException;
use App\Exception\CnssNotFoundException;
use App\Exception\ConsolidationException;
use App\Exception\DuplicateSaisieException;
use App\Exception\EntiteConsolideeException;
use App\Exception\IneligibleContresaisieException;
use App\Exception\InvalidCredentialsException;
use App\Exception\LastAdminException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class ApiExceptionListener
{
  /** @var array<class-string, array{string, int}> */
  private const ERROR_MAP = [
    InvalidCredentialsException::class => ['INVALID_CREDENTIALS', 401],
    AccountLockedException::class => ['ACCOUNT_LOCKED', 423],
    AccountDisabledException::class => ['ACCOUNT_DISABLED', 403],
    AccessDeniedException::class => ['FORBIDDEN', 403],
    CnssNotFoundException::class => ['CNSS_NOT_FOUND', 404],
    DuplicateSaisieException::class => ['DUPLICATE_CNSS', 409],
    EntiteConsolideeException::class => ['ENTITY_CONSOLIDATED', 423],
    IneligibleContresaisieException::class => ['NOT_ELIGIBLE', 422],
    ConsolidationException::class => ['NO_DATA', 422],
    LastAdminException::class => ['LAST_ADMIN', 409],
  ];

  public function __construct(
    private readonly bool $debug,
  ) {
  }

  public function __invoke(ExceptionEvent $event): void
  {
    $request = $event->getRequest();

    if (!str_starts_with($request->getPathInfo(), '/api')) {
      return;
    }

    $exception = $event->getThrowable();
    $class = $exception::class;

    if (isset(self::ERROR_MAP[$class])) {
      [$code, $status] = self::ERROR_MAP[$class];
      $event->setResponse($this->buildResponse($code, $exception->getMessage(), $status));

      return;
    }

    if ($exception instanceof HttpExceptionInterface) {
      $status = $exception->getStatusCode();
      $event->setResponse($this->buildResponse(
        'HTTP_ERROR',
        $exception->getMessage() ?: 'Erreur HTTP.',
        $status
      ));

      return;
    }

    $message = $this->debug
      ? $exception->getMessage()
      : 'Une erreur interne est survenue. Contactez l\'administrateur.';

    $event->setResponse($this->buildResponse('INTERNAL_ERROR', $message, 500));
  }

  private function buildResponse(string $code, string $message, int $status): JsonResponse
  {
    return new JsonResponse([
      'success' => false,
      'error' => [
        'code' => $code,
        'message' => $message,
      ],
    ], $status);
  }
}
