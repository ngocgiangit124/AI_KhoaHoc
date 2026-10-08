<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\CurrentPasswordGuard;
use App\Services\Privacy\DataExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

function vvT34VnNow(string $time): void
{
    test()->travelTo(Carbon::parse($time, 'Asia/Ho_Chi_Minh'));
}

test('(b) file khop snapshot tap khoa §2.8.4 va gia tri dung', function () {
    vvT34VnNow('2026-10-08 15:00:00');
    $me = vvT34Student(['phone' => '0912345678', 'parent_notice_opt_out_at' => null]);
    $seed = vvT34Seed($me);

    $r = vvT34Export()->assertOk();
    $data = json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($data))->toBe(['format_version', 'generated_at', 'policy_version', 'account', 'parent_contact', 'consents', 'enrollments', 'orders', 'learning_progress', 'quiz_attempts', 'cart', 'parent_notices'])
        ->and($data['format_version'])->toBe(1)
        ->and($data['generated_at'])->toBe('2026-10-08T15:00:00+07:00')
        ->and($data['policy_version'])->toBe('2026-10-tam')
        ->and(array_keys($data['account']))->toBe(['id', 'name', 'email', 'phone', 'date_of_birth', 'grade_level', 'role', 'status', 'email_verified_at', 'phone_verified_at', 'created_at', 'last_login_at', 'referral_code_used'])
        ->and($data['account']['id'])->toBe($me->id)
        ->and($data['account']['email'])->toBe($me->email)
        ->and($data['account']['phone'])->toBe('0912345678')
        ->and($data['account']['date_of_birth'])->toBe('2012-05-01')
        ->and($data['account']['role'])->toBe('hoc_sinh')
        ->and($data['account']['referral_code_used'])->toBe('REF123')
        ->and($data['parent_contact'])->toBe(['email' => 'phuhuynh@example.com', 'phone' => '0911111111', 'notices_opted_out_at' => null])
        ->and($data['consents'])->toHaveCount(2)
        ->and(array_keys($data['consents'][0]))->toBe(['type', 'policy_version', 'granted_by', 'channel', 'granted_at', 'revoked_at', 'ip', 'user_agent'])
        ->and($data['consents'][0]['ip'])->toBe('203.0.113.5')
        ->and($data['enrollments'])->toHaveCount(1)
        ->and(array_keys($data['enrollments'][0]))->toBe(['course', 'status', 'source', 'requested_at', 'activated_at', 'revoked_at', 'rejection_reason'])
        ->and($data['enrollments'][0]['course'])->toBe(['id' => $seed['course']->id, 'title' => 'Toán 8 nâng cao'])
        ->and($data['orders'])->toHaveCount(1)
        ->and(array_keys($data['orders'][0]))->toBe(['code', 'status', 'subtotal_amount', 'discount_amount', 'total_amount', 'coupon_code', 'payment_method', 'created_at', 'paid_at', 'cancelled_at', 'refunded_at', 'items'])
        ->and($data['orders'][0]['total_amount'])->toBe(450000)
        ->and($data['orders'][0]['status'])->toBe('paid')
        ->and($data['orders'][0]['items'])->toBe([['course' => ['id' => $seed['course']->id, 'title' => 'Toán 8 nâng cao'], 'price' => 500000, 'discount_amount' => 50000, 'final_amount' => 450000]])
        ->and($data['learning_progress'])->toHaveCount(1)
        ->and(array_keys($data['learning_progress'][0]))->toBe(['course', 'lesson', 'status', 'watched_seconds', 'last_position_seconds', 'completed_at', 'last_accessed_at'])
        ->and($data['learning_progress'][0]['lesson']['title'])->toBe('Bài 1 phương trình')
        ->and($data['learning_progress'][0]['watched_seconds'])->toBe(610)
        ->and($data['quiz_attempts'])->toHaveCount(1)
        ->and(array_keys($data['quiz_attempts'][0]))->toBe(['quiz', 'course', 'status', 'started_at', 'submitted_at', 'auto_submitted', 'total_questions', 'correct_count', 'score'])
        ->and($data['quiz_attempts'][0]['status'])->toBe('submitted')
        ->and($data['quiz_attempts'][0]['score'])->toBe(8.0)
        ->and($data['cart'])->toHaveCount(1)
        ->and(array_keys($data['cart'][0]))->toBe(['course', 'added_at'])
        ->and($data['cart'][0]['course']['title'])->toBe('Khóa trong giỏ')
        ->and($data['parent_notices'])->toHaveCount(1)
        ->and($data['parent_notices'][0]['kind'])->toBe('account_created')
        ->and($data['parent_notices'][0]['sent_at'])->toEndWith('+07:00');

    // Số điểm giữ dạng 8.0 (không rơi thành số nguyên) và thời gian luôn có offset +07:00.
    expect($r->getContent())->toContain('"score": 8.0')->toContain('+07:00')->not->toContain('+00:00');
});

test('(b) mang rong la [] (khong bo key), tai khoan moi van xuat duoc', function () {
    $me = vvT34Student(['parent_email' => null, 'parent_phone' => null]);

    $data = vvT34Export()->assertOk()->json();

    expect($data['consents'])->toBe([])
        ->and($data['enrollments'])->toBe([])->and($data['orders'])->toBe([])->and($data['learning_progress'])->toBe([])
        ->and($data['quiz_attempts'])->toBe([])->and($data['cart'])->toBe([])->and($data['parent_notices'])->toBe([])
        ->and($data['parent_contact'])->toBe(['email' => null, 'phone' => null, 'notices_opted_out_at' => null])
        ->and($data['account']['id'])->toBe($me->id);
});

test('(b) hoc sinh A va B cung khoa/don -> file cua A khong chua gi cua B', function () {
    $a = vvT34Student(['name' => 'Học Sinh Alpha', 'email' => 'alpha@example.com', 'phone' => '0911000001']);
    $b = vvT34Create(['name' => 'Học Sinh Bravo', 'email' => 'bravo@example.com', 'phone' => '0911000002', 'parent_email' => 'ph-bravo@example.com']);
    $course = Course::factory()->published()->paid(500000)->create(['title' => 'Khóa chung']);
    $seedA = vvT34Seed($a, $course);
    $seedB = vvT34Seed($b, $course);

    $body = vvT34Export()->assertOk()->getContent();

    expect($body)->toContain('alpha@example.com')->toContain($seedA['order']->code)
        ->not->toContain('Bravo')->not->toContain('bravo@example.com')->not->toContain('ph-bravo@example.com')
        ->not->toContain('0911000002')->not->toContain($seedB['order']->code);

    $data = json_decode($body, true);
    expect($data['account']['id'])->toBe($a->id)->and($data['orders'])->toHaveCount(1)->and($data['enrollments'])->toHaveCount(1);
});

test('(b) khong co mat khau/hash, session, OTP, dap an tung cau, nhat ky truy cap', function () {
    $me = vvT34Student();
    vvT34Seed($me);
    OtpCode::factory()->create(['user_id' => $me->id]);
    AuditLog::create(['actor_id' => $me->id, 'action' => 'auth.login', 'changes' => ['marker' => 'AUDIT-NHAT-KY-TRUY-CAP']]);

    $body = vvT34Export()->assertOk()->getContent();
    $keys = collect(new RecursiveIteratorIterator(new RecursiveArrayIterator(json_decode($body, true)), RecursiveIteratorIterator::SELF_FIRST))->keys()->unique()->all();

    expect($body)->not->toContain('$2y$')->not->toContain('AUDIT-NHAT-KY-TRUY-CAP')
        ->and($keys)->not->toContain('password')->not->toContain('current_session_id')->not->toContain('current_device_id')
        ->not->toContain('remember_token')->not->toContain('code_hash')->not->toContain('answers')->not->toContain('result')->not->toContain('question_ids')
        ->not->toContain('purpose');
    expect($body)->not->toContain((string) $me->fresh()->current_session_id);
});

test('(b) header Content-Disposition, no-store, nosniff, JSON utf-8; CORS expose Content-Disposition', function () {
    vvT34VnNow('2026-10-08 23:59:00');
    vvT34Student();

    $r = test()->postJson(vvApiUrl('/me/data-export'), ['current_password' => 'password'], vvWebHeaders())->assertOk();

    $r->assertHeader('Content-Disposition', 'attachment; filename="vitaminvui-du-lieu-ca-nhan-20261008.json"')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($r->headers->get('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and(strtolower((string) $r->headers->get('Access-Control-Expose-Headers')))->toContain('content-disposition')
        ->and(config('cors.exposed_headers'))->toContain('Content-Disposition');

    // Preflight: route cho phép POST từ Origin web.
    $pre = test()->call('OPTIONS', vvApiUrl('/me/data-export'), [], [], [], [
        'HTTP_ORIGIN' => config('app.frontend_url'),
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-csrf-token',
    ]);
    expect($pre->getStatusCode())->toBeIn([200, 204]);
});

test('(b) ten file theo NGAY VIET NAM, khong theo UTC', function () {
    vvT34VnNow('2026-10-09 01:00:00'); // = 2026-10-08 18:00 UTC
    vvT34Student();

    vvT34Export()->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="vitaminvui-du-lieu-ca-nhan-20261009.json"');
});

test('(b) so truy van khong tang theo so dong (khong N+1)', function () {
    $me = vvT34Student();
    vvT34Seed($me);
    $service = app(DataExportService::class);

    DB::enableQueryLog();
    $service->build($me);
    $small = count(DB::getQueryLog());

    foreach (range(1, 6) as $i) {
        vvT34Seed($me);
    }
    DB::flushQueryLog();
    $service->build($me);
    $big = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($big)->toBe($small);
});

test('(b) can mat khau: thieu -> 422, sai -> 422 current_password va KHONG tinh luot', function () {
    $me = vvT34Student();

    test()->postJson(vvApiUrl('/me/data-export'), [], vvWebHeaders())->assertStatus(422)->assertJsonValidationErrors('current_password');
    vvT34Export('sai-mat-khau')->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Mật khẩu hiện tại không đúng.');

    expect(vvT34Audits('privacy.data_export', $me->id))->toBe(0);
    test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertOk()->assertJsonPath('used_today', 0)->assertJsonPath('remaining', 2);
});

test('(b) can dang nhap; giao vien/admin khong goi duoc', function () {
    test()->postJson(vvApiUrl('/me/data-export'), ['current_password' => 'password'], vvWebHeaders())->assertStatus(401);
    test()->actingAs(User::factory()->teacher()->create())->postJson(vvApiUrl('/me/data-export'), ['current_password' => 'password'], vvWebHeaders())->assertStatus(403);
});

test('(c) lan 1,2 -> 200; lan 3 -> 429 DATA_EXPORT_LIMIT + Retry-After + resets_at 00:00 hom sau gio VN', function () {
    vvT34VnNow('2026-10-08 23:30:00');
    $me = vvT34Student();

    vvT34Export()->assertOk();
    vvT34Export()->assertOk();
    $r = vvT34Export()->assertStatus(429)
        ->assertJsonPath('code', 'DATA_EXPORT_LIMIT')
        ->assertJsonPath('errors.limit', 2)
        ->assertJsonPath('errors.resets_at', '2026-10-09T00:00:00+07:00');

    expect((int) $r->headers->get('Retry-After'))->toBe(30 * 60);
    expect(vvT34Audits('privacy.data_export', $me->id))->toBe(2);
});

test('(c) qua 00:00 gio VN la duoc tai lai; ranh gioi UTC khong anh huong', function () {
    vvT34VnNow('2026-10-08 23:59:30');
    $me = vvT34Student();
    vvT34Export()->assertOk();
    vvT34Export()->assertOk();
    vvT34Export()->assertStatus(429);

    vvT34VnNow('2026-10-09 00:00:01');
    vvT34Export()->assertOk();
    expect(vvT34Audits('privacy.data_export', $me->id))->toBe(3);
});

test('(c) 2 lan luc 00:30 gio VN (= 17:30 UTC hom truoc) van tinh chung mot ngay VN', function () {
    vvT34VnNow('2026-10-09 00:30:00');
    vvT34Student();
    vvT34Export()->assertOk();
    vvT34VnNow('2026-10-09 22:00:00');
    vvT34Export()->assertOk();
    vvT34Export()->assertStatus(429);
});

test('(c) loi dung file khong bi tinh luot', function () {
    $me = vvT34Student();
    app()->bind(DataExportService::class, fn ($app) => new class($app->make(AuditLogger::class), $app->make(CurrentPasswordGuard::class)) extends DataExportService
    {
        public function build(User $user): array
        {
            throw new RuntimeException('boom');
        }
    });

    vvT34Export()->assertStatus(500);
    vvT34Export()->assertStatus(500);
    vvT34Export()->assertStatus(500);

    expect(vvT34Audits('privacy.data_export', $me->id))->toBe(0);
    test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertJsonPath('remaining', 2);
});

test('(c) han muc tinh theo tung hoc sinh', function () {
    $a = vvT34Student();
    vvT34Export()->assertOk();
    vvT34Export()->assertOk();
    vvT34Export()->assertStatus(429);

    vvResetClient();
    vvT34Student();
    vvT34Export()->assertOk();
});

test('(c) audit privacy.data_export chi co format_version + bytes (khong PII)', function () {
    $me = vvT34Student();
    $body = vvT34Export()->assertOk()->getContent();

    $log = AuditLog::query()->where('action', 'privacy.data_export')->where('actor_id', $me->id)->firstOrFail();
    expect($log->changes)->toEqual(['format_version' => 1, 'bytes' => strlen($body)]);
});

test('(d) GET /me/data-export tra used_today/remaining dung', function () {
    vvT34VnNow('2026-10-08 10:00:00');
    vvT34Student();

    test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertOk()->assertExactJson([
        'limit_per_day' => 2, 'used_today' => 0, 'remaining' => 2, 'resets_at' => '2026-10-09T00:00:00+07:00', 'requires_password' => true,
    ]);

    vvT34Export()->assertOk();
    test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertJsonPath('used_today', 1)->assertJsonPath('remaining', 1);

    vvT34Export()->assertOk();
    test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertJsonPath('used_today', 2)->assertJsonPath('remaining', 0);

    vvT34VnNow('2026-10-09 00:00:05');
    test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertJsonPath('used_today', 0)->assertJsonPath('remaining', 2)
        ->assertJsonPath('resets_at', '2026-10-10T00:00:00+07:00');
});

test('(d) han muc doc tu config', function () {
    config(['privacy.data_export_daily_limit' => 1]);
    vvT34Student();

    vvT34Export()->assertOk();
    vvT34Export()->assertStatus(429)->assertJsonPath('errors.limit', 1);
});

test('(b) route chi nhan POST cho tai file (GET khong tra file)', function () {
    vvT34Student();
    $r = test()->getJson(vvApiUrl('/me/data-export'), vvWebHeaders())->assertOk();
    expect($r->headers->get('Content-Disposition'))->toBeNull();
});

test('(b) don cua nguoi khac khong bao gio vao file du la cung gio', function () {
    $a = vvT34Student();
    $b = vvT34Create();
    Order::factory()->paid()->create(['user_id' => $b->id, 'code' => 'VVB-SECRET-ORDER']);

    expect(vvT34Export()->assertOk()->getContent())->not->toContain('VVB-SECRET-ORDER');
});
