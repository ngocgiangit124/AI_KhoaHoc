<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;

/**
 * GL-A34 (A3) — report QueryException không được ghi bindings/PII vào log.
 * Bắt log THẬT: thay logger trong container (cả `log` cho facade và LoggerInterface mà Handler dùng để report mặc định)
 * bằng một Monolog có TestHandler. Bỏ `return false` ở hook thì report mặc định đi qua logger này và test đỏ.
 */
function vvCaptureRealLog(): TestHandler
{
    $handler = new TestHandler;
    $logger = new Logger(new Monolog('gl-a34', [$handler]));
    app()->instance('log', $logger);
    app()->instance(LoggerInterface::class, $logger);
    Log::clearResolvedInstance('log');

    return $handler;
}

/** Toàn bộ nội dung mọi bản ghi (message + context + chuỗi exception nếu có) để grep PII. */
function vvLogDump(TestHandler $handler): string
{
    $out = '';
    foreach ($handler->getRecords() as $r) {
        $ctx = $r['context'];
        $out .= $r['message'].' '.json_encode(array_map(fn ($v) => $v instanceof Throwable ? (string) $v : $v, $ctx), JSON_UNESCAPED_UNICODE)."\n";
    }

    return $out;
}

function vvDbQueryFailedCount(TestHandler $handler): int
{
    return count(array_filter($handler->getRecords(), fn ($r) => $r['message'] === 'db.query_failed'));
}

test('QueryException: log thật đúng 1 dòng db.query_failed, không có email/SĐT/hash', function () {
    $email = 'nguoi.dung.pii@example.test';
    $phone = '0912345678';
    $hash = '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

    $handler = vvCaptureRealLog();

    $caught = null;
    try {
        DB::select('select no_such_column from users where email = ? and phone = ? and password = ?', [$email, $phone, $hash]);
    } catch (QueryException $e) {
        $caught = $e;
    }
    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->toContain($email); // chứng minh message thô có PII

    report($caught);

    $dump = vvLogDump($handler);
    expect($handler->getRecords())->toHaveCount(1)
        ->and(vvDbQueryFailedCount($handler))->toBe(1)
        ->and($handler->getRecords()[0]['context']['sqlstate'])->toBe('42S22')
        ->and($handler->getRecords()[0]['context']['driver_code'])->toBe(1054)
        ->and($handler->getRecords()[0]['context']['sql'])->toContain('?')
        ->and($dump)->not->toContain($email)
        ->not->toContain($phone)
        ->not->toContain('$2y$')
        ->not->toContain('@')
        ->not->toContain('Duplicate entry');
});

test('lỗi unique khi tạo user: log thật không chứa giá trị trùng và "Duplicate entry"', function () {
    $user = User::factory()->create(['email' => 'trung.email@example.test']);
    $handler = vvCaptureRealLog();

    try {
        User::factory()->create(['email' => $user->email]);
        $this->fail('Phải ném QueryException');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('trung.email@example.test');
        report($e);
    }

    $dump = vvLogDump($handler);
    expect(vvDbQueryFailedCount($handler))->toBe(1)
        ->and($handler->getRecords())->toHaveCount(1)
        ->and($handler->getRecords()[0]['context']['sqlstate'])->toBe('23000')
        ->and($dump)->not->toContain('trung.email')
        ->not->toContain('@')
        ->not->toContain('Duplicate entry');
});
