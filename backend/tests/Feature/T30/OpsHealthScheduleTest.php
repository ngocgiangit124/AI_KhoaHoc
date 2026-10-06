<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * I3 (review bảo mật cụm 1): lịch gọi `ops:health --log='1'` (truyền `['--log' => true]`) làm Symfony báo
 * `The "--log" option does not accept a value` mỗi 5 phút nên giám sát không chạy. `--log` là cờ boolean.
 */
test('lịch ops:health dùng cờ --log trần, không gán giá trị', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $e) => str_contains((string) $e->command, 'ops:health'));

    expect($event)->not->toBeNull();
    $command = (string) $event->command;
    expect($command)->toEndWith('ops:health --log')
        ->and($command)->not->toContain('--log=')
        ->and($command)->not->toContain("'1'");
});

test('chạy đúng chuỗi lệnh mà scheduler dùng không nổ lỗi tùy chọn, và ghi log error khi có vấn đề', function () {
    Log::spy();

    // Chưa có nhịp worker/scheduler -> có vấn đề -> exit 1 và log error (không phải lỗi cú pháp tùy chọn).
    $exit = Artisan::call('ops:health --log');

    expect($exit)->toBe(1);
    Log::shouldHaveReceived('error')->atLeast()->once();
});
