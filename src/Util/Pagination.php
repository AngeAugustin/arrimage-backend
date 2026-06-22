<?php
/**
 * @file    Pagination.php
 * @package App\Util
 * @desc    Helpers pour la pagination des réponses API.
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Util;

use Symfony\Component\HttpFoundation\Request;

final class Pagination
{
    /**
     * @return array{0: int, 1: int}
     */
    public static function parse(Request $request, int $defaultLimit = 20, int $maxLimit = 100): array
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min($maxLimit, max(1, (int) $request->query->get('limit', $defaultLimit)));

        return [$page, $limit];
    }

    /**
     * @return array{total: int, page: int, limit: int, totalPages: int}
     */
    public static function meta(int $total, int $page, int $limit): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $limit > 0 ? (int) ceil($total / $limit) : 0,
        ];
    }
}
