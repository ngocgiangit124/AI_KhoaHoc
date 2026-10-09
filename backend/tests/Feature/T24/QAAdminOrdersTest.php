<?php

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
});

test('QA AC31: giao vien goi ma co that va ma khong co -> cung 403, cung body, o moi route', function () {
    $order = vvT24Order();
    vvStaffLogin(vvStaffUser('teacher'));

    $real = vvAdminGet("/admin/orders/{$order->code}");
    $fake = vvAdminGet('/admin/orders/VV999999ZZZZZZ');
    expect($real->status())->toBe(403)->and($fake->status())->toBe(403)->and(Arr::except($real->json(), 'request_id'))->toBe(Arr::except($fake->json(), 'request_id'));

    $r1 = vvT24Post("/admin/orders/{$order->code}/refund", ['confirm' => true]);
    $r2 = vvT24Post('/admin/orders/VV999999ZZZZZZ/refund', ['confirm' => true]);
    expect($r1->status())->toBe(403)->and($r2->status())->toBe(403)->and(Arr::except($r1->json(), 'request_id'))->toBe(Arr::except($r2->json(), 'request_id'));
    expect($order->fresh()->status->value)->toBe('pending');
});

test('QA: q la email chinh xac -> JSON tho khong co email/SDT ro, customer_note, thong tin phu huynh', function () {
    $s = User::factory()->student()->verified()->create([
        'email' => 'qa.exact@gmail.com', 'phone' => '0903334445',
        'parent_email' => 'ph.qa@example.com', 'parent_phone' => '0977111222',
    ]);
    $o = vvT24Order($s, ['customer_note' => 'ghi chu bi mat QA'], 'manual');
    vvStaffLogin(vvStaffUser('admin'));

    foreach (['qa.exact@gmail.com', '0903334445', '+84903334445'] as $q) {
        $r = vvAdminGet('/admin/orders?'.vvT24Range().'&q='.urlencode($q))->assertOk();
        expect(collect($r->json('data'))->pluck('code')->all())->toBe([$o->code]);
        $raw = $r->getContent();
        foreach (['qa.exact@gmail.com', '0903334445', 'ghi chu bi mat', 'ph.qa', '0977111222', 'parent', 'customer_note'] as $leak) {
            expect($raw)->not->toContain($leak);
        }
    }
});

test('QA: tab Cho duyet khong can ngay, cu nhat truoc, cursor 3 trang khong trung/sot', function () {
    $codes = [];
    for ($i = 0; $i < 60; $i++) {
        $codes[] = vvT24Order(null, ['created_at' => now()->subMinutes(500 - $i)], 'manual', 0)->code;
    }
    vvStaffLogin(vvStaffUser('pageManager'));

    $seen = [];
    $url = '/admin/orders?status[]=pending&payment_method=manual&sort=oldest&per_page=25';
    for ($page = 0; $page < 3; $page++) {
        $r = vvAdminGet($url)->assertOk();
        $seen = array_merge($seen, $r->json('data.*.code'));
        $next = $r->json('meta.next_cursor');
        if ($next === null) {
            break;
        }
        $url = '/admin/orders?status[]=pending&payment_method=manual&sort=oldest&per_page=25&cursor='.urlencode($next);
    }
    $mine = array_values(array_intersect($seen, $codes));
    expect(array_unique($seen))->toHaveCount(count($seen))
        ->and($mine)->toBe($codes); // đúng thứ tự cũ nhất trước, đủ 60, không sót
});

test('QA: expiring_soon o danh sach theo moc 12h; view_pii co actor + IP', function () {
    $soon = vvT24Order(null, ['expires_at' => now()->addHours(11)], 'manual');
    $later = vvT24Order(null, ['expires_at' => now()->addHours(13)], 'manual');
    $admin = vvStaffUser('admin');
    vvStaffLogin($admin);

    $rows = collect(vvAdminGet('/admin/orders?'.vvT24Range())->assertOk()->json('data'))->keyBy('code');
    expect($rows[$soon->code]['expiring_soon'])->toBeTrue()->and($rows[$later->code]['expiring_soon'])->toBeFalse();

    vvAdminGet("/admin/orders/{$soon->code}")->assertOk();
    $log = AuditLog::query()->where('action', 'order.view_pii')->where('subject_id', $soon->id)->latest('id')->first();
    expect($log->actor_id)->toBe($admin->id)->and($log->ip)->not->toBeNull()->and($log->ip)->not->toBe('');
});

test('QA: hoan tien khong tao audit view_pii, don khong ton tai 404 khong audit, giao vien khong doi du lieu', function () {
    vvStaffLogin(vvStaffUser('admin'));
    $before = AuditLog::query()->where('action', 'like', 'order.%')->count();
    vvT24Post('/admin/orders/VV000000NOPE00/refund', ['confirm' => true])->assertNotFound();
    vvAdminGet('/admin/orders/VV000000NOPE00')->assertNotFound();
    expect(AuditLog::query()->where('action', 'like', 'order.%')->count())->toBe($before);
    expect(Order::query()->where('code', 'VV000000NOPE00')->exists())->toBeFalse();
});
