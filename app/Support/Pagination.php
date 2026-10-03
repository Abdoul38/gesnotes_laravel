<?php

namespace App\Support;

/** Portage de paginate() (ancien includes/functions.php). */
final class Pagination
{
    /** @return array{total:int, perPage:int, page:int, totalPages:int, offset:int} */
    public static function calculer(int $total, int $parPage, int $page): array
    {
        $totalPages = (int) ceil($total / $parPage);
        $page = max(1, min($page, $totalPages ?: 1));

        return ['total' => $total, 'perPage' => $parPage, 'page' => $page, 'totalPages' => $totalPages, 'offset' => ($page - 1) * $parPage];
    }
}
