<?php

use App\Models\User;
use Laravel\Sanctum\HasApiTokens;
use Symfony\Component\Finder\Finder;

/**
 * L6 (review bảo mật T01/T02) — dự án chỉ xác thực SPA bằng cookie phiên,
 * không phát hành personal access token (S24). `User` không được dùng
 * `HasApiTokens`, và không nơi nào trong `app/` được gọi `createToken(`.
 */
test('User model khong dung trait HasApiTokens', function () {
    expect(in_array(HasApiTokens::class, class_uses_recursive(User::class), true))->toBeFalse();
});

test('khong co noi nao trong app/ goi createToken(', function () {
    $finder = (new Finder)->files()->in(app_path())->name('*.php');

    $offenders = [];

    foreach ($finder as $file) {
        if (str_contains($file->getContents(), 'createToken(')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Không được phát hành personal access token (S24): '.implode(', ', $offenders));
});
