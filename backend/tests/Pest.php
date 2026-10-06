<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// LƯU Ý `audit_logs`: bảng bất biến ở tầng DB (trigger L2, migration 2026_10_16_100000), test KHÔNG xoá/sửa được dòng đã
// commit (race worker, test commit thật). Dòng audit tích luỹ trong DB test; muốn sạch thì chạy `migrate:fresh` trên DB test.
// Test phải đếm theo mức nền (đếm trước rồi so phần tăng) hoặc lọc theo `subject_id`, không đếm tuyệt đối. Đặt `created_at`
// cũ bằng INSERT, không UPDATE.

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Arch');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
