<?php

use Symfony\Component\Finder\Finder;

/**
 * S12.8 (ADR-001 §2) — cổng thanh toán luôn verify TLS; cấm dùng
 * `withoutVerifying()` của `Illuminate\Http\Client` ở bất kỳ đâu trong
 * `App\Services\Payments` (adapter MoMo và các cổng thêm sau này).
 */
test('khong co adapter thanh toan nao tat verify TLS (S12.8)', function () {
    $finder = (new Finder)->files()->in(app_path('Services/Payments'))->name('*.php');

    $offenders = [];

    foreach ($finder as $file) {
        // `->withoutVerifying(` (lời gọi thật) — không bắt nhầm chuỗi này khi
        // xuất hiện trong docblock/comment (vd giải thích "không dùng ...").
        if (str_contains($file->getContents(), '->withoutVerifying(')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Không được tắt verify TLS trong adapter thanh toán (S12.8): '.implode(', ', $offenders));
});

/**
 * S12.9 — log kênh `payments` không được chứa `secretKey`/`signature` thô.
 * Chỉ xét NỘI DUNG MẢNG CONTEXT của từng lời gọi `Log::channel('payments')->
 * info|warning|error(...)` (không xét toàn file — file còn xây payload GỬI
 * ĐI cho MoMo có khoá `signature` hợp lệ, không phải log). Regex giả định
 * mảng context không lồng mảng con (đúng với mọi lời gọi hiện có); nếu sau
 * này có mảng lồng, cập nhật lại cách bắt bằng AST thay vì regex.
 */
test('log channel payments khong truyen thang khoa secretKey/signature trong context (S12.9)', function () {
    $finder = (new Finder)->files()->in(app_path('Services/Payments'))->name('*.php');

    $offenders = [];

    foreach ($finder as $file) {
        $contents = $file->getContents();

        if (! preg_match_all(
            "/Log::channel\('payments'\)->\w+\([^\[]*\[(.*?)\]\s*\)/s",
            $contents,
            $matches
        )) {
            continue;
        }

        foreach ($matches[1] as $context) {
            if (preg_match("/'(secretKey|signature)'\s*=>/", $context)) {
                $offenders[] = $file->getRelativePathname();

                break;
            }
        }
    }

    expect($offenders)->toBe([], 'Log channel "payments" không được có khoá secretKey/signature trong context (S12.9): '.implode(', ', $offenders));
});
