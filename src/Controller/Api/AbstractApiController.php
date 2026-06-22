<?php
/**
 * @file    AbstractApiController.php
 * @package App\Controller\Api
 * @desc    Contrôleur de base fournissant des helpers de réponse JSON standardisés.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

abstract class AbstractApiController extends AbstractController
{
    /**
     * Retourne une réponse JSON de succès.
     *
     * @param mixed  $data    Données à sérialiser
     * @param int    $status  Code HTTP (200 par défaut)
     * @param string $message Message optionnel
     */
    protected function successResponse(mixed $data = null, int $status = 200, string $message = ''): JsonResponse
    {
        $payload = ['success' => true];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($message !== '') {
            $payload['message'] = $message;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * Retourne une réponse JSON d'erreur.
     *
     * @param string $code    Code d'erreur métier
     * @param string $message Message lisible par l'utilisateur
     * @param int    $status  Code HTTP (400 par défaut)
     */
    protected function errorResponse(string $code, string $message, int $status = 400): JsonResponse
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
