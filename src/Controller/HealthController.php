<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
  #[Route('/', name: 'health_root', methods: ['GET', 'HEAD'])]
  #[Route('/api/health', name: 'api_health', methods: ['GET', 'HEAD'])]
  public function __invoke(): JsonResponse
  {
    return new JsonResponse(['status' => 'ok']);
  }
}
