<?php

namespace App\Support;

/**
 * Escape ký tự đặc biệt của LIKE trước khi đưa vào query (vẫn dùng binding
 * tham số — S24, api-contract §2.1 `q`, §2.5 `q`).
 */
class Like
{
    /**
     * Escape `\`, `%`, `_` rồi bọc `%...%` — dùng cho tìm kiếm "chứa từ khoá".
     */
    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    /**
     * Escape rồi bọc `...%` — dùng cho tìm kiếm "bắt đầu bằng" (vd tên HS ở
     * `/admin/orders` — api-contract §2.5).
     */
    public static function startsWith(string $term): string
    {
        return self::escape($term).'%';
    }

    /**
     * Escape `\` TRƯỚC (ký tự escape của chính nó), sau đó `%` và `_`.
     */
    public static function escape(string $term): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $term,
        );
    }
}
