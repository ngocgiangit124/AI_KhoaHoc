<?php

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Finder\Finder;

/**
 * C4-L6 — Mailable/Notification xếp hàng mang dữ liệu cá nhân (email, tên, lý do) phải mã hoá payload: nếu không, dữ liệu nằm
 * rõ trong Redis queue và `failed_jobs` (30 ngày). Thêm class mới vào `app/Mail` hoặc `app/Notifications` là tự bị kiểm.
 */
test('moi Mailable/Notification ShouldQueue phai ShouldBeEncrypted', function (string $folder) {
    $dir = app_path($folder);
    $namespace = 'App\\'.$folder.'\\';

    if (! is_dir($dir)) {
        expect(true)->toBeTrue();

        return;
    }

    $checked = 0;

    foreach ((new Finder)->files()->in($dir)->name('*.php') as $file) {
        $class = $namespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        expect($reflection->implementsInterface(ShouldBeEncrypted::class))->toBeTrue("{$class} implements ShouldQueue nhưng thiếu ShouldBeEncrypted (C4-L6)");
        $checked++;
    }

    if ($folder === 'Mail') {
        expect($checked)->toBeGreaterThan(0);
    }
})->with(['Mail', 'Notifications']);
