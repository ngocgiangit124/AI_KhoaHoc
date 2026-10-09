<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * QA GL-A34 (A3): đường HTTP thật -> Handler thật -> file log THẬT (channel single trỏ file tạm).
 */
test('A3: lỗi DB có PII qua HTTP -> 500 không lộ gì; file log không có PII, có db.query_failed đủ thông tin', function () {
    $file = storage_path('framework/testing/qa-gl-a34-'.uniqid().'.log');
    File::ensureDirectoryExists(dirname($file));
    config(['logging.default' => 'single', 'logging.channels.single.path' => $file, 'logging.channels.single.level' => 'debug']);
    app('log')->forgetChannel('single');

    $email = 'pii.qa.http@example.test';
    $phone = '0987654321';
    $hash = '$2y$12$QAQAQAQAQAQAQAQAQAQAQAuQAQAQAQAQAQAQAQAQAQAQAQAQAQAQA';

    User::factory()->create(['email' => $email]);
    Route::post('/__qa/dup', function () use ($email, $phone, $hash) {
        // lỗi unique thật với giá trị PII
        DB::table('users')->insert(['name' => 'Dup', 'email' => $email, 'phone' => $phone, 'password' => $hash]);
    })->middleware('api');
    Route::post('/__qa/col', function () use ($email, $phone, $hash) {
        DB::select('select zzz from users where email = ? and phone = ? and password = ?', [$email, $phone, $hash]);
    })->middleware('api');

    foreach (['/__qa/dup', '/__qa/col'] as $uri) {
        $res = $this->postJson('http://'.config('app.api_host').$uri, [], ['Origin' => config('app.frontend_url')]);
        $res->assertStatus(500);
        $body = $res->getContent();
        expect($body)->not->toContain($email)->not->toContain($phone)->not->toContain('$2y$')
            ->not->toContain('Duplicate')->not->toContain('SQLSTATE')->not->toContain('select ')->not->toContain('users');
    }

    $log = File::exists($file) ? File::get($file) : '';
    File::delete($file);

    expect(substr_count($log, 'db.query_failed'))->toBe(2);
    expect($log)->not->toContain($email)->not->toContain($phone)->not->toContain('$2y$')
        ->not->toContain('Duplicate entry')->not->toContain('pii.qa')
        ->toContain('sqlstate')->toContain('driver_code')->toContain('connection')->toContain('location');
    // dòng log không chứa "@" (email) dù là nơi nào
    expect(preg_match('/\S@\S/', $log))->toBe(0);
});
