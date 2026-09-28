<?php

/**
 * Chỉ dùng trong worktree T06 (Claude Code on the web, không Docker).
 *
 * `vendor/` của worktree này là SYMLINK trỏ tới vendor thật của repo gốc
 * (chính sách môi trường: không `composer install`/`update` trong worktree).
 * Composer đã sinh `vendor/composer/autoload_psr4.php` với `$baseDir` cố định
 * = thư mục gốc CỦA REPO GỐC (vì __DIR__ của các file trong vendor được PHP
 * `realpath()` xuyên symlink) — nên autoload mặc định của Composer nạp NHẦM
 * `App\*`, `Database\Factories\*`, `Tests\*` từ repo gốc thay vì worktree
 * này. Đăng ký thêm 1 autoloader ưu tiên trước (prepend) để nạp đúng các
 * namespace do worktree này sở hữu; namespace bên thứ 3 vẫn rơi xuống
 * autoloader gốc của Composer (không đổi, không cần composer install).
 */
spl_autoload_register(function (string $class): void {
    $prefixes = [
        'App\\' => __DIR__.'/../app/',
        'Database\\Factories\\' => __DIR__.'/../database/factories/',
        'Database\\Seeders\\' => __DIR__.'/../database/seeders/',
        'Tests\\' => __DIR__.'/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir.str_replace('\\', '/', $relative).'.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
}, true, true);

require __DIR__.'/../vendor/autoload.php';
