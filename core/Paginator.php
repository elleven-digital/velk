<?php

declare(strict_types=1);

namespace Velk;

/**
 * Cálculo de paginação das listagens do admin — puro, sem request/DB.
 * O per-page padrão do admin é 50 (a biblioteca de mídia usa 60).
 */
final class Paginator
{
    /**
     * @param mixed $rawPage valor cru da query string (string|null|array…)
     * @return array{page:int, perPage:int, offset:int, totalPages:int, total:int}
     */
    public static function make(mixed $rawPage, int $total, int $perPage = 50): array
    {
        $perPage = max(1, $perPage);
        $total = max(0, $total);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = is_scalar($rawPage) ? (int) $rawPage : 1;
        $page = max(1, min($page, $totalPages));

        return [
            'page' => $page,
            'perPage' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'totalPages' => $totalPages,
            'total' => $total,
        ];
    }
}
