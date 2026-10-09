<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
});

test('quyen: chua dang nhap 401, giao vien 403 TRUOC validate o moi route /admin/orders*, QLT va admin vao duoc', function () {
    vvAdminGet('/admin/orders?'.vvT24Range())->assertUnauthorized();
    vvAdminGet('/admin/orders/pending-count')->assertUnauthorized();

    $order = vvT24Order();

    vvStaffLogin(vvStaffUser('teacher'));
    // Tham số sai hoàn toàn + mã đơn không tồn tại: vẫn 403 (không 422, không 404).
    vvAdminGet('/admin/orders?from=sai&per_page=999&sort=x')->assertForbidden()->assertJson(['code' => 'FORBIDDEN']);
    vvAdminGet('/admin/orders/pending-count')->assertForbidden();
    vvAdminGet("/admin/orders/{$order->code}")->assertForbidden();
    vvAdminGet('/admin/orders/VVKHONGCO')->assertForbidden();
    vvT24Post("/admin/orders/{$order->code}/refund", [])->assertForbidden();
    expect(AuditLog::query()->where('action', 'order.view_pii')->where('subject_id', $order->id)->count())->toBe(0);

    foreach (['pageManager', 'admin'] as $state) {
        vvStaffLogin(vvStaffUser($state));
        vvAdminGet('/admin/orders?'.vvT24Range())->assertOk();
        vvAdminGet('/admin/orders/pending-count')->assertOk();
    }
});

test('hoc sinh khong vao duoc admin-api (dang nhap quan tri bi tu choi)', function () {
    $student = User::factory()->student()->verified()->create();

    $r = vvAdminLogin((string) $student->email);
    expect($r->status())->toBeGreaterThanOrEqual(400);
    vvAdminGet('/admin/orders?'.vvT24Range())->assertUnauthorized();
});

test('khoang ngay: thieu from/to -> 422, > 366 ngay -> 422, 366 ngay dung -> OK, tab Cho duyet (status=[pending]) khong can ngay', function () {
    vvT24Order();
    vvStaffLogin(vvStaffUser('admin'));

    vvAdminGet('/admin/orders')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors(['from', 'to'], 'errors');
    vvAdminGet('/admin/orders?from=2026-01-01')->assertStatus(422);
    vvAdminGet('/admin/orders?from=2026-01-01&to=2027-01-03')->assertStatus(422)->assertJsonPath('errors.to.0', 'Khoảng ngày tối đa 366 ngày.');
    vvAdminGet('/admin/orders?from=2026-01-01&to=2027-01-02')->assertOk(); // đúng 366 ngày
    vvAdminGet('/admin/orders?from=2026-02-31&to=2026-03-02')->assertStatus(422); // ngày không tồn tại
    vvAdminGet('/admin/orders?from=2026-05-02&to=2026-05-01')->assertStatus(422);

    // Chỉ pending -> không cần ngày. Pending + trạng thái khác -> vẫn bắt buộc.
    vvAdminGet('/admin/orders?status[]=pending')->assertOk();
    vvAdminGet('/admin/orders?status[]=pending&status[]=paid')->assertStatus(422);
    vvAdminGet('/admin/orders?status[]=paid')->assertStatus(422);

    // Tham số khác
    foreach (['status[]=bogus', 'payment_method=visa', 'sort=sideways', 'per_page=10', 'needs_review=maybe', 'pending_older_than_hours=0', 'pending_older_than_hours=721'] as $bad) {
        vvAdminGet('/admin/orders?'.vvT24Range().'&'.$bad)->assertStatus(422);
    }
    vvAdminGet('/admin/orders?'.vvT24Range().'&per_page=50')->assertOk()->assertJsonPath('meta.per_page', 50);
});

test('loc tung tham so: status, payment_method, needs_review, pending_older_than_hours, bien ngay (gom ca ngay to)', function () {
    $pendingManual = vvT24Order(null, [], 'manual');
    $paidManual = vvT24Order(null, ['payment_method' => 'manual'], 'paid');
    $paidMomo = vvT24Order(null, ['payment_method' => 'momo', 'needs_review' => true], 'paid');
    $none = vvT24Order(null, ['payment_method' => 'none'], 'paid');
    $cancelled = vvT24Order(null, ['payment_method' => 'manual'], 'cancelled');
    $oldPending = vvT24Order(null, ['created_at' => now()->subHours(30)], 'manual');
    $codes = fn ($r) => collect($r->json('data'))->pluck('code')->sort()->values()->all();
    $range = vvT24Range();

    vvStaffLogin(vvStaffUser('pageManager'));

    expect($codes(vvAdminGet("/admin/orders?{$range}&status[]=paid")))->toEqual(collect([$paidManual, $paidMomo, $none])->pluck('code')->sort()->values()->all());
    expect($codes(vvAdminGet("/admin/orders?{$range}&status[]=paid&status[]=cancelled")))->toHaveCount(4);
    expect($codes(vvAdminGet("/admin/orders?{$range}&payment_method=momo")))->toBe([$paidMomo->code]);
    expect($codes(vvAdminGet("/admin/orders?{$range}&payment_method=none")))->toBe([$none->code]);
    expect($codes(vvAdminGet("/admin/orders?{$range}&payment_method=manual")))->toHaveCount(4);
    expect($codes(vvAdminGet("/admin/orders?{$range}&needs_review=1")))->toBe([$paidMomo->code]);
    expect($codes(vvAdminGet("/admin/orders?{$range}&needs_review=0")))->toHaveCount(5);
    expect($codes(vvAdminGet("/admin/orders?{$range}&pending_older_than_hours=24")))->toBe([$oldPending->code]);
    expect($codes(vvAdminGet('/admin/orders?status[]=pending')))->toEqual(collect([$pendingManual, $oldPending])->pluck('code')->sort()->values()->all());

    // Biên: đơn tạo 23:59:59 hôm `to` còn nằm trong, 00:00:00 hôm sau thì không; 00:00:00 hôm `from` nằm trong.
    $day = '2026-03-10';
    $in1 = vvT24Order(null, ['created_at' => vvMoVn("{$day} 00:00:00")], 'paid');
    $in2 = vvT24Order(null, ['created_at' => vvMoVn("{$day} 23:59:59")], 'paid');
    $before = vvT24Order(null, ['created_at' => vvMoVn('2026-03-09 23:59:59')], 'paid');
    $after = vvT24Order(null, ['created_at' => vvMoVn('2026-03-11 00:00:00')], 'paid');
    expect($codes(vvAdminGet("/admin/orders?from={$day}&to={$day}")))->toEqual(collect([$in1, $in2])->pluck('code')->sort()->values()->all())
        ->and([$before->code, $after->code])->not->toContain(...$codes(vvAdminGet("/admin/orders?from={$day}&to={$day}")));
});

test('q: 4 dang (ma don, email chinh xac, SDT chuan hoa, ten tien to) va ky tu LIKE duoc escape', function () {
    $an = User::factory()->student()->verified()->create(['name' => 'Nguyễn Văn An', 'email' => 'nguyenvanan@gmail.com', 'phone' => '0901234123']);
    $binh = User::factory()->student()->verified()->create(['name' => '100% Bình_X', 'email' => 'binh@gmail.com', 'phone' => '0912345678']);
    $oAn = vvT24Order($an, [], 'paid');
    $oBinh = vvT24Order($binh, [], 'paid');
    $range = vvT24Range();
    $codes = fn ($q) => collect(vvAdminGet("/admin/orders?{$range}&q=".urlencode($q))->assertOk()->json('data'))->pluck('code')->all();

    vvStaffLogin(vvStaffUser('admin'));

    expect($codes($oAn->code))->toBe([$oAn->code])
        ->and($codes(strtolower($oAn->code)))->toBe([$oAn->code])
        ->and($codes('nguyenvanan@gmail.com'))->toBe([$oAn->code])
        ->and($codes('nguyenvanan@gmail'))->toBe([]) // email phải chính xác
        ->and($codes('0901234123'))->toBe([$oAn->code])
        ->and($codes('+84 901 234 123'))->toBe([$oAn->code])
        ->and($codes('090123'))->toBe([]) // không phải SĐT hợp lệ
        ->and($codes('Nguyễn'))->toBe([$oAn->code])
        ->and($codes('nguyen'))->toBe([$oAn->code]) // collation không phân biệt dấu/hoa thường
        ->and($codes('Văn An'))->toBe([]) // chỉ tiền tố
        ->and($codes('%'))->toBe([]) // % không phải ký tự đại diện
        ->and($codes('100%'))->toBe([$oBinh->code])
        ->and($codes('_'))->toBe([]);
});

test('danh sach: email/SDT luon che, tai khoan da an danh khong 500, khong lo thong tin phu huynh', function () {
    $minor = User::factory()->student()->verified()->create([
        'name' => 'Nguyễn Văn An', 'email' => 'nguyenvanan@gmail.com', 'phone' => '0901234123',
        'parent_email' => 'phuhuynh.bimat@example.com', 'parent_phone' => '0988777666',
    ]);
    $gone = User::factory()->student()->verified()->create(['name' => 'Ten That', 'email' => 'gone@example.com', 'phone' => '0911000111']);
    $gone->forceFill(['name' => 'Tài khoản đã xoá', 'email' => null, 'phone' => null, 'anonymized_at' => now()])->save();
    $o1 = vvT24Order($minor, ['customer_note' => 'Goi sau 18h'], 'manual');
    $o2 = vvT24Order($gone, [], 'cancelled');

    vvStaffLogin(vvStaffUser('admin'));
    $r = vvAdminGet('/admin/orders?'.vvT24Range())->assertOk();
    $raw = $r->getContent();

    $row = collect($r->json('data'))->firstWhere('code', $o1->code);
    expect($row['student'])->toBe(['id' => $minor->id, 'name' => 'Nguyễn Văn An', 'email_masked' => 'n***@gmail.com', 'phone_masked' => '******4123', 'is_deleted' => false])
        ->and($raw)->not->toContain('nguyenvanan@gmail.com')->not->toContain('0901234123')
        ->not->toContain('phuhuynh')->not->toContain('0988777666')->not->toContain('parent')
        ->not->toContain('Goi sau 18h'); // ghi chú HS chỉ ở chi tiết

    $deleted = collect($r->json('data'))->firstWhere('code', $o2->code);
    expect($deleted['student'])->toBe(['id' => $gone->id, 'name' => 'Tài khoản đã xoá', 'email_masked' => null, 'phone_masked' => null, 'is_deleted' => true]);
});

test('danh sach: items_count + first_item_title (dong id nho nhat), expiring_soon, confirmed_by, shape day du', function () {
    $staff = vvStaffUser('pageManager', ['name' => 'Trần Thị Bình']);
    $order = vvT24Order(null, ['subtotal_amount' => 550000, 'discount_amount' => 50000, 'total_amount' => 500000, 'coupon_code' => null], 'manual', 0);
    foreach (['Toán 9 nâng cao', 'Ngữ văn 9', 'Anh 9'] as $title) {
        vvT24Item($order, Course::factory()->published()->paid()->create(), $title);
    }
    $soon = vvT24Order(null, ['expires_at' => now()->addHours(11)], 'manual');
    $later = vvT24Order(null, ['expires_at' => now()->addHours(13)], 'manual');
    $momo = vvT24Order(null, ['payment_method' => 'momo', 'expires_at' => now()->addHours(2)], 'manual'); // đơn cổng sắp hết hạn: không gắn nhãn
    $paid = vvT24Order(null, ['payment_method' => 'manual', 'expires_at' => now()->addHours(1)], 'paid');
    DB::table('orders')->where('id', $paid->id)->update(['confirmed_by' => $staff->id]);

    vvStaffLogin(vvStaffUser('admin'));
    $rows = collect(vvAdminGet('/admin/orders?'.vvT24Range())->assertOk()->json('data'))->keyBy('code');

    expect($rows[$order->code])->toMatchArray([
        'status' => 'pending', 'status_reason' => null, 'payment_method' => 'manual', 'items_count' => 3, 'first_item_title' => 'Toán 9 nâng cao',
        'subtotal' => 550000, 'discount' => 50000, 'total' => 500000, 'needs_review' => false, 'expiring_soon' => false,
        'paid_at' => null, 'cancelled_at' => null, 'confirmed_by' => null,
    ])
        ->and(array_keys($rows[$order->code]))->toBe(['code', 'status', 'status_reason', 'payment_method', 'items_count', 'first_item_title', 'subtotal', 'discount', 'total', 'needs_review', 'created_at', 'expires_at', 'expiring_soon', 'paid_at', 'cancelled_at', 'confirmed_by', 'student'])
        ->and($rows[$order->code]['created_at'])->toEndWith('+07:00')
        ->and($rows[$soon->code]['expiring_soon'])->toBeTrue()
        ->and($rows[$later->code]['expiring_soon'])->toBeFalse()
        ->and($rows[$momo->code]['expiring_soon'])->toBeFalse()
        ->and($rows[$paid->code]['expiring_soon'])->toBeFalse()
        ->and($rows[$paid->code]['confirmed_by'])->toBe(['id' => $staff->id, 'name' => 'Trần Thị Bình']);
});

test('cursor: sort newest/oldest qua 2 trang khong trung/sot, ke ca nhieu don cung created_at; meta.total dung', function () {
    $same = now()->subHours(2)->startOfSecond();
    $ids = [];
    for ($i = 0; $i < 31; $i++) {
        $ids[] = vvT24Order(null, ['created_at' => $i < 10 ? $same : now()->subMinutes(100 - $i)], 'paid', 0)->code;
    }
    vvStaffLogin(vvStaffUser('admin'));
    $range = vvT24Range();

    foreach (['newest', 'oldest'] as $sort) {
        $p1 = vvAdminGet("/admin/orders?{$range}&sort={$sort}")->assertOk();
        expect($p1->json('data'))->toHaveCount(25)->and($p1->json('meta.total'))->toBe(31)->and($p1->json('meta.per_page'))->toBe(25)->and($p1->json('meta.prev_cursor'))->toBeNull();
        $cursor = $p1->json('meta.next_cursor');
        expect($cursor)->toBeString();

        $p2 = vvAdminGet("/admin/orders?{$range}&sort={$sort}&cursor=".urlencode($cursor))->assertOk();
        expect($p2->json('data'))->toHaveCount(6)->and($p2->json('meta.next_cursor'))->toBeNull()->and($p2->json('meta.prev_cursor'))->toBeString();

        $seen = array_merge(collect($p1->json('data'))->pluck('code')->all(), collect($p2->json('data'))->pluck('code')->all());
        expect($seen)->toHaveCount(31)->and(array_unique($seen))->toHaveCount(31)->and(collect($seen)->sort()->values()->all())->toEqual(collect($ids)->sort()->values()->all());

        $times = collect(array_merge($p1->json('data'), $p2->json('data')))->pluck('created_at')->map(fn ($t) => strtotime($t))->all();
        $sorted = $times;
        $sort === 'newest' ? rsort($sorted) : sort($sorted);
        expect($times)->toBe($sorted);

        // Trang trước quay lại đúng trang 1.
        $back = vvAdminGet("/admin/orders?{$range}&sort={$sort}&cursor=".urlencode($p2->json('meta.prev_cursor')))->assertOk();
        expect(collect($back->json('data'))->pluck('code')->all())->toBe(collect($p1->json('data'))->pluck('code')->all());
    }
});

test('so truy van khong tang theo so don tren trang (danh sach, chi tiet)', function () {
    $run = function (int $orders): array {
        Order::query()->delete();
        for ($i = 0; $i < $orders; $i++) {
            $o = vvT24Order(null, ['coupon_code' => null], 'manual', 3);
            if ($i % 2 === 0) {
                DB::table('orders')->where('id', $o->id)->update(['confirmed_by' => vvStaffUser('pageManager')->id]);
            }
        }
        $target = Order::query()->first();
        foreach (range(1, $orders) as $n) {
            DB::table('order_notes')->insert(['order_id' => $target->id, 'author_id' => vvStaffUser('pageManager')->id, 'body' => "ghi chu {$n}", 'created_at' => now()]);
            DB::table('order_status_logs')->insert(['order_id' => $target->id, 'from_status' => 'pending', 'to_status' => 'pending', 'actor_type' => 'staff', 'actor_id' => vvStaffUser('pageManager')->id, 'meta' => null, 'created_at' => now()]);
        }

        vvAdminGet('/admin/orders?'.vvT24Range())->assertOk(); // làm nóng (phiên, cache cấu hình)
        DB::flushQueryLog();
        DB::enableQueryLog();
        vvAdminGet('/admin/orders?'.vvT24Range())->assertOk();
        $list = count(DB::getQueryLog());
        DB::flushQueryLog();
        vvAdminGet("/admin/orders/{$target->code}")->assertOk();
        $detail = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$list, $detail];
    };

    vvStaffLogin(vvStaffUser('admin'));
    [$list2, $detail2] = $run(2);
    [$list12, $detail12] = $run(12);

    expect($list12)->toBe($list2)->and($detail12)->toBe($detail2);
});

test('cursor bi sua/hong -> 422 errors.cursor (khong 500); cursor hop le van dung duoc', function () {
    for ($i = 0; $i < 27; $i++) {
        vvT24Order(null, [], 'paid', 0);
    }
    vvStaffLogin(vvStaffUser('admin'));
    $range = vvT24Range();
    $enc = fn (array $p) => rtrim(strtr(base64_encode(json_encode($p)), '+/', '-_'), '=');

    foreach (['abc', $enc(['id' => 1, '_pointsToNextItems' => true]), $enc(['created_at' => 'khong-phai-ngay', 'id' => 1, '_pointsToNextItems' => true]),
        $enc(['created_at' => ['a'], 'id' => 5, '_pointsToNextItems' => true]), $enc(['created_at' => '2026-01-01 00:00:00', 'id' => '1; DROP', '_pointsToNextItems' => true]),
        $enc(['created_at' => '2026-01-01 00:00:00', 'id' => 1, 'x' => 1, '_pointsToNextItems' => true])] as $bad) {
        vvAdminGet("/admin/orders?{$range}&cursor=".urlencode($bad))->assertStatus(422)->assertJsonValidationErrors(['cursor'], 'errors');
    }

    $next = vvAdminGet("/admin/orders?{$range}")->assertOk()->json('meta.next_cursor');
    vvAdminGet("/admin/orders?{$range}&cursor=".urlencode($next))->assertOk()->assertJsonCount(2, 'data');
});

test('S1: q la email/SDT -> dung 1 audit order.search_contact (khong chua gia tri goc), q la ma don/ten -> khong ghi', function () {
    $an = User::factory()->student()->verified()->create(['name' => 'Nguyễn Văn An', 'email' => 'nguyenvanan@gmail.com', 'phone' => '0901234123']);
    $order = vvT24Order($an, [], 'paid');
    vvStaffLogin($admin = vvStaffUser('admin'));
    $range = vvT24Range();
    $count = fn () => AuditLog::query()->where('action', 'order.search_contact')->count();
    $before = $count();
    $hash = fn (string $v) => hash_hmac('sha256', $v, (string) config('app.key'));

    vvAdminGet("/admin/orders?{$range}&q=".urlencode('nguyenvanan@gmail.com'))->assertOk()->assertJsonCount(1, 'data');
    vvAdminGet("/admin/orders?{$range}&q=".urlencode('+84 901 234 123'))->assertOk()->assertJsonCount(1, 'data');
    vvAdminGet("/admin/orders?{$range}&q=".urlencode('0999999999'))->assertOk()->assertJsonCount(0, 'data');
    expect($count())->toBe($before + 3);

    $logs = AuditLog::query()->where('action', 'order.search_contact')->orderByDesc('id')->limit(3)->get()->reverse()->values();
    expect($logs[0]->changes)->toEqual(['kind' => 'email', 'hit' => true, 'q_hash' => $hash('nguyenvanan@gmail.com')])
        ->and($logs[1]->changes)->toEqual(['kind' => 'phone', 'hit' => true, 'q_hash' => $hash('0901234123')])
        ->and($logs[2]->changes)->toEqual(['kind' => 'phone', 'hit' => false, 'q_hash' => $hash('0999999999')])
        ->and($logs[0]->actor_id)->toBe($admin->id)->and($logs[0]->subject_id)->toBeNull();
    foreach ($logs as $log) {
        $raw = json_encode($log->changes);
        expect($raw)->not->toContain('nguyenvanan')->not->toContain('0901234123')->not->toContain('0999999999');
    }

    // Mã đơn, tên, và danh sách không có q: không ghi.
    vvAdminGet("/admin/orders?{$range}&q=".urlencode($order->code))->assertOk();
    vvAdminGet("/admin/orders?{$range}&q=Nguyen")->assertOk();
    vvAdminGet("/admin/orders?{$range}")->assertOk();
    // 422 (q hợp lệ nhưng thiếu ngày): không ghi.
    vvAdminGet('/admin/orders?q='.urlencode('nguyenvanan@gmail.com'))->assertStatus(422);
    expect($count())->toBe($before + 3);
});

test('S1: tim theo email/SDT gioi han rieng 30/phut va 300/ngay (429 + Retry-After), tim theo ten/ma don van 200', function () {
    $order = vvT24Order();
    vvStaffLogin($admin = vvStaffUser('admin'));
    $range = vvT24Range();

    for ($i = 0; $i < 30; $i++) {
        vvAdminGet("/admin/orders?{$range}&q=".urlencode("u{$i}@example.com"))->assertOk();
    }
    $r = vvAdminGet("/admin/orders?{$range}&q=".urlencode('u31@example.com'))->assertStatus(429);
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0)->and($r->json('code'))->toBe('TOO_MANY_ATTEMPTS');
    vvAdminGet("/admin/orders?{$range}&q=0901234123")->assertStatus(429); // chung hạn mức với email
    vvAdminGet("/admin/orders?{$range}&q=Nguyen")->assertOk();
    vvAdminGet("/admin/orders?{$range}&q=".urlencode($order->code))->assertOk();
    vvAdminGet("/admin/orders?{$range}")->assertOk();

    // Hết cửa sổ phút (xoá khoá phút) thì tìm lại được; khoá ngày vẫn đếm: đặt đủ 300 lượt ngày thì chặn dù phút còn trống.
    RateLimiter::clear('admin-order-contact-min:'.$admin->id);
    vvAdminGet("/admin/orders?{$range}&q=".urlencode('u40@example.com'))->assertOk();
    RateLimiter::clear('admin-order-contact-min:'.$admin->id);
    for ($i = 0; $i < 300; $i++) {
        RateLimiter::hit('admin-order-contact-day:'.$admin->id, 86400);
    }
    vvAdminGet("/admin/orders?{$range}&q=".urlencode('u41@example.com'))->assertStatus(429);
    vvAdminGet("/admin/orders?{$range}&q=Nguyen")->assertOk();
});
