<?php

namespace App\Support;

/**
 * Escape ký tự đặc biệt của LIKE (`%`, `_`, `\`) trước khi đưa vào mẫu — vẫn phải dùng binding (S24).
 */
final class Like
{
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /** Mẫu "chứa": `%từ khoá%`. */
    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    /** Mẫu "bắt đầu bằng": `từ khoá%`. */
    public static function startsWith(string $term): string
    {
        return self::escape($term).'%';
    }
}
