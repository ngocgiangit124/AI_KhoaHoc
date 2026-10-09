<?php

use App\Mail\ManualOrderCancelledMail;
use App\Mail\ManualOrderReceivedMail;
use App\Mail\NewManualOrderStaffMail;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\CouponCapacity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

if (! function_exists('vvMoGet')) {
    function vvMoGet(string $path)
    {
        return test()->getJson(vvApiUrl($path), vvWebHeaders());
    }
}
if (! function_exists('vvMoCancel')) {
    function vvMoCancel(string $code)
    {
        return test()->postJson(vvApiUrl("/orders/{$code}/cancel"), [], vvWebHeaders());
    }
}

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
    $this->student = vvMoStudent();
    $this->originalTz = date_default_timezone_get();
});

afterEach(function () {
    date_default_timezone_set($this->originalTz);
    config(['app.timezone' => $this->originalTz]);
});

// ---------- Hạn mức 5 đơn/ngày quanh 00:00 giờ VN, APP_TIMEZONE UTC và Asia/Ho_Chi_Minh ----------
test('QA hạn mức: ranh giới 00:00 VN không phụ thuộc APP_TIMEZONE', function (string $tz) {
    config(['app.timezone' => $tz]);
    date_default_timezone_set($tz);

    $this->travelTo(vvMoVn('2026-10-08 23:59:30'));
    vvMoCart($this->student, [vvMoCourse(100000)]);
    // 4 đơn trong ngày 08 (từ 00:00:00 đúng), 5 đơn của ngày 07 lúc 23:59:59 VN (không tính)
    for ($i = 0; $i < 5; $i++) {
        vvMoOrder($this->student, null, ['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'cancelled_at' => now(), 'created_at' => vvMoVn('2026-10-07 23:59:59')]);
    }
    for ($i = 0; $i < 3; $i++) {
        vvMoOrder($this->student, null, ['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'cancelled_at' => now(), 'created_at' => vvMoVn('2026-10-08 00:00:00')]);
    }
    // 3 đơn hôm nay + 5 đơn hôm qua: còn tạo được (đơn thứ 4 và 5 trong ngày)
    vvMoPost(100000)->assertCreated();
    $order = Order::where('status', 'pending')->firstOrFail();
    $order->forceFill(['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'cancelled_at' => now()])->save();

    // đơn thứ 5 trong ngày
    vvMoPost(100000)->assertCreated();
    Order::where('status', 'pending')->update(['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'cancelled_at' => now()]);

    // đã đủ 5 đơn hôm nay -> 429, resets 00:00 ngày sau, Retry-After = 30 giây
    $r = vvMoPost(100000)->assertStatus(429)->assertJsonPath('code', 'MANUAL_ORDER_LIMIT');
    expect($r->json('errors.resets_at'))->toBe('2026-10-09T00:00:00+07:00')->and((int) $r->headers->get('Retry-After'))->toBe(30);

    // đúng 00:00:00 VN ngày mới: bộ đếm về 0
    $this->travelTo(vvMoVn('2026-10-09 00:00:00'));
    vvMoPost(100000)->assertCreated();
})->with(['UTC', 'Asia/Ho_Chi_Minh']);

// ---------- Tắt cờ giữa chừng ----------
test('QA cờ manual tắt giữa chừng: đơn cũ xem + huỷ được; preview/checkout mới bị chặn đúng mã', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);
    $code = vvMoPost(100000)->assertCreated()->json('order_code');

    vvMoConfig(['features.manual_payment' => false, 'features.paid_checkout' => false]);

    vvMoGet("/orders/{$code}")->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('can_cancel', true);
    vvMoGet('/orders')->assertOk()->assertJsonPath('data.0.code', $code);

    $p = vvMoPreview()->assertOk();
    expect($p->json('can_checkout'))->toBeFalse();

    vvMoPost(100000)->assertStatus(503)->assertJsonPath('code', 'PAYMENT_DISABLED');
    expect(Order::count())->toBe(1);

    vvMoCancel($code)->assertOk()->assertJsonPath('status_reason', 'user_cancelled');
});

// ---------- Thư ----------
test('QA thư: QTV không có PII; thư học sinh có kênh liên hệ, không có STK/ngân hàng; ghi chú không vào thư nào', function () {
    $this->student->forceFill(['name' => 'Nguyễn Thị Hương Giang'])->save();
    vvMoCart($this->student, [vvMoCourse(100000)]);
    vvMoPost(100000, ['customer_note' => 'ZALO-MAMA-0987654321'])->assertCreated();

    Mail::assertQueued(NewManualOrderStaffMail::class, function ($m) {
        $html = $m->render();
        foreach ([$this->student->name, 'Giang', $this->student->email, (string) $this->student->phone, 'ZALO-MAMA', '0987654321'] as $pii) {
            expect($html)->not->toContain($pii);
        }

        return true;
    });
    Mail::assertQueued(ManualOrderReceivedMail::class, function ($m) {
        $html = $m->render();
        expect($html)->toContain('0901 234 567')->toContain('hotro@example.com')->toContain('zalo.me/0901234567')->toContain('8:00-21:00')
            ->not->toContain('ZALO-MAMA');
        foreach (['STK', 'Số tài khoản', 'số tài khoản', 'Ngân hàng', 'ngân hàng', 'QR'] as $bank) {
            expect($html)->not->toContain($bank);
        }

        return true;
    });
});

// ---------- customer_note ----------
test('QA customer_note: HTML/XSS, bidi, zero-width, điều khiển, 501 ký tự bị 422; 500 ký tự và tiếng Việt qua; không có đơn lạ', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);

    $bad = [
        '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', 'a<b', 'x>y', "zero\u{200B}width", "bom\u{FEFF}x", "zwj\u{200D}x", "lrm\u{200E}x",
        "rlo\u{202E}x", "isolate\u{2066}x", "nul\0x", "esc\x1bx", "ls\u{2028}x", str_repeat('a', 501), str_repeat('á', 501), str_repeat('Ế', 501),
    ];
    foreach ($bad as $i => $note) {
        Cache::flush();
        vvMoPost(100000, ['customer_note' => $note])->assertStatus(422)->assertJsonValidationErrors(['customer_note']);
    }
    expect(Order::count())->toBe(0);

    vvMoPost(100000, ['customer_note' => ['x']])->assertStatus(422);

    $ok = str_repeat('Đ', 500);
    vvMoPost(100000, ['customer_note' => $ok])->assertCreated();
    expect(Order::firstOrFail()->customer_note)->toBe($ok);

    $o = Order::firstOrFail();
    expect(vvMoGet("/orders/{$o->code}")->json('customer_note'))->toBe($ok);
});

test('QA customer_note có dấu & " \' được lưu nguyên, không bị mã hoá HTML hai lần', function () {
    vvMoCart($this->student, [vvMoCourse(100000)]);
    vvMoPost(100000, ['customer_note' => 'Gọi "mẹ" & bố, không phải \'khác\''])->assertCreated();
    expect(Order::firstOrFail()->customer_note)->toBe('Gọi "mẹ" & bố, không phải \'khác\'');
});

// ---------- expire-manual ----------
test('QA expire-manual: chạy 2 lần chỉ 1 thư; nhả lượt mã; đơn paid/cancelled không bị đụng', function () {
    $coupon = Coupon::factory()->percent(10)->create(['max_uses' => 1, 'valid_until' => now()->addDays(30)]);
    vvMoCart($this->student, [vvMoCourse(100000)], $coupon);
    $code = vvMoPost(90000)->assertCreated()->json('order_code');
    expect(app(CouponCapacity::class)->hasRoom($coupon->fresh()))->toBeFalse();

    $this->travelTo(now()->addHours(72)->addMinute());
    Mail::fake();
    Artisan::call('orders:expire-manual');
    Artisan::call('orders:expire-manual');

    expect(Order::where('code', $code)->first()->status_reason)->toBe('expired');
    Mail::assertQueued(ManualOrderCancelledMail::class, 1);
    expect(app(CouponCapacity::class)->hasRoom($coupon->fresh()))->toBeTrue();
    expect(DB::table('order_status_logs')->where('order_id', Order::where('code', $code)->value('id'))->where('to_status', 'cancelled')->count())->toBe(1);
});

// ---------- 409 + IDOR ----------
test('QA 409 PENDING_ORDER_EXISTS đủ errors; sau replace_pending replaced_by_code đúng ở chi tiết và danh sách; IDOR 404 cùng body', function () {
    $cart = vvMoCart($this->student, [vvMoCourse(100000)]);
    $first = vvMoPost(100000)->assertCreated()->json('order_code');
    vvMoAddToCart($cart, vvMoCourse(50000));

    $r = vvMoPost(150000)->assertStatus(409)->assertJsonPath('code', 'PENDING_ORDER_EXISTS');
    foreach (['order_code', 'payment_method', 'items_count', 'total', 'created_at', 'expires_at'] as $k) {
        expect($r->json('errors'))->toHaveKey($k);
    }
    expect($r->json('errors.payment_method'))->toBe('manual')->and($r->json('errors.created_at'))->toEndWith('+07:00');

    $second = vvMoPost(150000, ['replace_pending' => true])->assertCreated()->json('order_code');
    expect(vvMoGet("/orders/{$first}")->json('replaced_by_code'))->toBe($second);
    $list = collect(vvMoGet('/orders')->json('data'))->keyBy('code');
    expect($list[$first]['replaced_by_code'])->toBe($second)->and($list[$second]['replaced_by_code'])->toBeNull();

    // IDOR: HS khác
    $theirs = vvMoOrder(User::factory()->student()->verified()->create());
    $a = vvMoGet("/orders/{$theirs->code}")->assertStatus(404);
    $b = vvMoGet('/orders/VVNOTEXIST999')->assertStatus(404);
    $strip = fn ($r) => collect($r->json())->except('request_id')->all();
    expect($strip($a))->toBe($strip($b));
    $c = vvMoCancel($theirs->code)->assertStatus(404);
    expect($strip($c))->toBe($strip($b))->and($theirs->fresh()->status->value)->toBe('pending');
    // không lộ ghi chú của người khác trong danh sách
    expect(collect(vvMoGet('/orders')->json('data'))->pluck('code')->all())->not->toContain($theirs->code);
});

// ---------- /config/public ----------
test('QA /config/public: shape ổn định khi bật/tắt cờ, không lộ email nội bộ', function () {
    $url = 'http://'.config('app.api_host').'/api/v1/config/public';
    $on = test()->getJson($url)->assertOk();
    expect($on->getContent())->not->toContain('ops@example.com')->not->toContain('notify_emails');
    expect(array_keys($on->json('manual_payment')))->toBe(['label', 'description', 'pending_ttl_hours', 'contact']);

    vvMoConfig(['features.manual_payment' => false]);
    $off = test()->getJson($url)->assertOk();
    expect($off->json('manual_payment'))->toBeNull()->and($off->json('payment_methods'))->toBe([])->and($off->json('paid_checkout_enabled'))->toBeFalse();
    expect(array_keys($off->json()))->toEqual(array_keys($on->json()));
});

// ---------- EXPLAIN ----------
test('QA EXPLAIN: câu đếm hạn mức và câu quét expireDue dùng index, không quét toàn bảng', function () {
    $user = $this->student;
    for ($i = 0; $i < 30; $i++) {
        vvMoOrder(User::factory()->student()->verified()->create(), null, ['created_at' => now()->subHours($i)]);
    }
    DB::statement('ANALYZE TABLE orders');

    $count = DB::select('EXPLAIN SELECT count(*) FROM orders WHERE user_id = ? AND payment_method = ? AND created_at >= ?', [$user->id, 'manual', now()->startOfDay()]);
    $scan = DB::select("EXPLAIN SELECT id FROM orders WHERE payment_method = 'manual' AND status = 'pending' AND expires_at <= ? ORDER BY expires_at, id LIMIT 500", [now()]);

    fwrite(STDERR, "\nEXPLAIN count: ".json_encode($count)."\nEXPLAIN expireDue: ".json_encode($scan)."\n");

    expect($count[0]->key)->not->toBeNull()->and($count[0]->type)->not->toBe('ALL');
    expect($scan[0]->key)->not->toBeNull();
});
