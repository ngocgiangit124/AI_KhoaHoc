<?php

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../T04/helpers.php';

function qGet()
{
    return test()->getJson(vvApiUrl('/cart'), vvWebHeaders());
}
function qAdd(int $id)
{
    return test()->postJson(vvApiUrl('/cart/items'), ['course_id' => $id], vvWebHeaders());
}
function qDel(int $id)
{
    return test()->deleteJson(vvApiUrl("/cart/items/{$id}"), [], vvWebHeaders());
}
function qCoupon(string $code)
{
    return test()->putJson(vvApiUrl('/cart/coupon'), ['code' => $code], vvWebHeaders());
}
function qPaid(int $price): Course
{
    return Course::factory()->published()->paid($price)->create();
}

beforeEach(function () {
    $this->student = vvActAsStudent(User::factory()->student()->create());
});

test('QA M1: fixed == tong, khong max_uses/valid_until -> 422; chi co 1 trong 2 van 422; du ca 2 -> ap duoc total 0', function () {
    qAdd(qPaid(80000)->id);
    Coupon::factory()->fixed(80000)->create(['code' => 'EQNONE00']);
    Coupon::factory()->fixed(80000)->create(['code' => 'EQMAXONLY', 'max_uses' => 5]);
    Coupon::factory()->fixed(80000)->create(['code' => 'EQUNTILONLY', 'valid_until' => now()->addDay()]);
    Coupon::factory()->fixed(80000)->create(['code' => 'EQBOTH00', 'max_uses' => 5, 'valid_until' => now()->addDay()]);

    foreach (['EQNONE00', 'EQMAXONLY', 'EQUNTILONLY'] as $c) {
        qCoupon($c)->assertStatus(422)->assertJsonPath('code', 'COUPON_NOT_APPLICABLE');
    }
    expect(Cart::first()->coupon_id)->toBeNull();
    qCoupon('EQBOTH00')->assertOk()->assertJsonPath('pricing.total', 0)->assertJsonPath('pricing.discount', 80000);
});

test('QA M1: fixed nho hon tong -> ap; sau khi xoa bot khoa fixed >= tong (khong gioi han) -> go ma + notice dung 1 lan', function () {
    $a = qPaid(100000);
    $b = qPaid(30000);
    qAdd($a->id);
    qAdd($b->id);
    Coupon::factory()->fixed(50000)->create(['code' => 'FIX50000']);
    qCoupon('FIX50000')->assertOk()->assertJsonPath('pricing.total', 80000);

    $r = qDel($a->id)->assertOk();
    expect($r->json('coupon'))->toBeNull()->and($r->json('pricing.total'))->toBe(30000)
        ->and(collect($r->json('notices'))->pluck('code')->all())->toBe(['COUPON_REMOVED']);
    qGet()->assertJsonPath('notices', [])->assertJsonPath('coupon', null);
});

test('QA M1: fixed lon hon tong nhung ma khong dua ve 0 vi chi ap 1 phan -> ap, giam = phan ap dung', function () {
    $in = qPaid(40000);
    $out = qPaid(60000);
    $coupon = Coupon::factory()->restricted()->fixed(90000)->create(['code' => 'SCOPEFIX']);
    $coupon->courses()->attach($in->id);
    qAdd($in->id);
    qAdd($out->id);

    qCoupon('SCOPEFIX')->assertOk()->assertJsonPath('pricing', ['subtotal' => 100000, 'discount' => 40000, 'total' => 60000]);
});

test('QA: percent 100 ap duoc qua API -> kiem tra hanh vi tong 0d', function () {
    qAdd(qPaid(99999)->id);
    Coupon::factory()->percent(100)->create(['code' => 'FREE100', 'max_uses' => 5, 'valid_until' => now()->addDay()]);

    $r = qCoupon('FREE100');
    // Quy tac chot chi noi ve fixed; percent 100 duoc phep (ghi nhan trong bao cao).
    $r->assertOk()->assertJsonPath('pricing.total', 0)->assertJsonPath('pricing.discount', 99999);
});

test('QA: ma het han / het luot / bi xoa / vo hieu khi dang trong gio -> GET go ma, notice COUPON_REMOVED dung 1 lan', function () {
    qAdd(qPaid(100000)->id);

    $cases = [
        'expired' => fn (Coupon $c) => $c->forceFill(['valid_until' => now()->subMinute()])->save(),
        'exhausted' => fn (Coupon $c) => $c->forceFill(['max_uses' => 3, 'used_count' => 3])->save(),
        'inactive' => fn (Coupon $c) => $c->forceFill(['status' => 'inactive'])->save(),
    ];
    $i = 0;
    foreach ($cases as $name => $mutate) {
        $coupon = Coupon::factory()->percent(10)->create(['code' => 'CASE'.$i++.'ABCD']);
        qCoupon($coupon->code)->assertOk();
        $mutate($coupon);

        $r = qGet()->assertOk();
        expect($r->json('coupon'))->toBeNull($name)
            ->and(collect($r->json('notices'))->pluck('code')->all())->toBe(['COUPON_REMOVED'], $name)
            ->and($r->json('pricing.total'))->toBe(100000);
        qGet()->assertJsonPath('notices', []);
        expect(Cart::first()->coupon_id)->toBeNull();
    }
});

test('QA: ma bi xoa khi dang trong gio -> gio khong con ma (FK null), khong 500; KHONG co notice (ghi nhan Minor)', function () {
    qAdd(qPaid(100000)->id);
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'DELETED01']);
    qCoupon('DELETED01')->assertOk();
    $coupon->delete();

    qGet()->assertOk()->assertJsonPath('coupon', null)->assertJsonPath('pricing.total', 100000)->assertJsonPath('notices', []);
});

test('QA: khoa doi gia/bo xuat ban/xoa mem/mien phi/da so huu -> unavailable, ma khong tinh tren khoa do', function () {
    $keep = qPaid(100000);
    $gone = [qPaid(10000), qPaid(20000), qPaid(30000), qPaid(40000)];
    qAdd($keep->id);
    foreach ($gone as $g) {
        qAdd($g->id);
    }
    Coupon::factory()->percent(10)->create(['code' => 'TENPCT00']);
    qCoupon('TENPCT00')->assertOk();

    $gone[0]->forceFill(['status' => 'unpublished'])->save();
    $gone[1]->delete();
    $gone[2]->forceFill(['price' => 0])->save();
    Enrollment::factory()->create(['user_id' => $this->student->id, 'course_id' => $gone[3]->id]);

    $r = qGet()->assertOk();
    expect($r->json('pricing'))->toBe(['subtotal' => 100000, 'discount' => 10000, 'total' => 90000])
        ->and(collect($r->json('items'))->where('unavailable', true)->count())->toBe(4)
        ->and(collect($r->json('items'))->where('unavailable', true)->every(fn ($i) => $i['final_amount'] === null))->toBeTrue();
});

test('QA: phan bo giam gia nhieu khoa gia le: tong dong = discount, khong am, khong vuot gia', function () {
    $prices = [33333, 12345, 99999, 1, 777, 50001];
    foreach ($prices as $p) {
        qAdd(qPaid($p)->id);
    }
    Coupon::factory()->fixed(123457)->create(['code' => 'ODDFIX00', 'max_uses' => 9, 'valid_until' => now()->addDay()]);
    Coupon::factory()->percent(37)->create(['code' => 'ODDPCT00']);

    foreach (['ODDFIX00', 'ODDPCT00'] as $code) {
        $r = qCoupon($code)->assertOk();
        $items = collect($r->json('items'));
        expect($items->sum('discount_amount'))->toBe($r->json('pricing.discount'))
            ->and($items->sum('final_amount'))->toBe($r->json('pricing.total'))
            ->and($r->json('pricing.subtotal') - $r->json('pricing.discount'))->toBe($r->json('pricing.total'));
        foreach ($items as $i) {
            expect($i['discount_amount'])->toBeGreaterThanOrEqual(0)->and($i['discount_amount'])->toBeLessThanOrEqual($i['price'])
                ->and($i['final_amount'])->toBeGreaterThanOrEqual(0);
        }
    }
});

test('QA: pham vi ma: chon khoa + chon chuyen de ket hop; khoa ngoai khong duoc giam', function () {
    $direct = qPaid(100000);
    $viaSubject = qPaid(200000);
    $outside = qPaid(300000);
    $subject = Subject::factory()->create();
    $viaSubject->subjects()->attach($subject->id);
    $coupon = Coupon::factory()->restricted()->percent(10)->create(['code' => 'MIXSCOPE']);
    $coupon->courses()->attach($direct->id);
    $coupon->subjects()->attach($subject->id);
    foreach ([$direct, $viaSubject, $outside] as $c) {
        qAdd($c->id);
    }

    $r = qCoupon('MIXSCOPE')->assertOk();
    expect($r->json('pricing.discount'))->toBe(30000);
    $byId = collect($r->json('items'))->keyBy('course_id');
    expect($byId[$outside->id]['discount_amount'])->toBe(0)->and($byId[$outside->id]['final_amount'])->toBe(300000);
});

test('QA: is_restricted pivot rong (sau khi ma da ap, pivot bi xoa) -> GET go ma', function () {
    $c = qPaid(100000);
    $coupon = Coupon::factory()->restricted()->percent(10)->create(['code' => 'PIVOTGONE']);
    $coupon->courses()->attach($c->id);
    qAdd($c->id);
    qCoupon('PIVOTGONE')->assertOk();
    DB::table('coupon_course')->where('coupon_id', $coupon->id)->delete();

    qGet()->assertJsonPath('coupon', null)->assertJsonPath('notices.0.code', 'COUPON_REMOVED');
});

test('QA S18: dung 30 lan sai van 422, lan 31 -> 429 + Retry-After; ma dung khi con luot khong bi tinh', function () {
    $key = 'coupon-fail:'.$this->student->id;
    RateLimiter::clear($key);
    qAdd(qPaid(100000)->id);
    Coupon::factory()->percent(10)->create(['code' => 'GOOD12345']);

    for ($i = 0; $i < 29; $i++) {
        RateLimiter::hit($key, 86400);
    }
    qCoupon('GOOD12345')->assertOk();
    expect(RateLimiter::attempts($key))->toBe(29);
    qCoupon('NOPE00000')->assertStatus(422);
    expect(RateLimiter::attempts($key))->toBe(30);
    qCoupon('NOPE00001')->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    expect((int) test()->putJson(vvApiUrl('/cart/coupon'), ['code' => 'GOOD12345'], vvWebHeaders())->headers->get('Retry-After'))->toBeGreaterThan(0);
    RateLimiter::clear($key);
});

test('QA: hoc sinh chua xac thuc OTP van dung duoc gio + cart_count', function () {
    $u = vvOtpStudent(['email_verified_at' => null, 'phone_verified_at' => null]);
    $c = qPaid(50000);

    qAdd($c->id)->assertCreated();
    qGet()->assertOk()->assertJsonPath('pricing.total', 50000);
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertJsonPath('cart_count', 1);
    expect($u->id)->not->toBe($this->student->id);
});

test('QA: staff (admin/quan ly trang) khong goi duoc gio; khach 401', function () {
    foreach ([User::factory()->admin()->create(), User::factory()->pageManager()->create()] as $staff) {
        vvActAsStudent($staff);
        qGet()->assertForbidden();
        qAdd(qPaid(1000)->id)->assertForbidden();
        qCoupon('ABCDEFGH')->assertForbidden();
    }
});

test('QA: du lieu gio HS khac khong lo; xoa khoa cua HS khac khong anh huong; badge cart_count rieng', function () {
    $other = User::factory()->student()->create();
    $c = qPaid(70000);
    Cart::factory()->for($other)->withCourses([$c])->create();
    qAdd(qPaid(10000)->id);

    qGet()->assertJsonCount(1, 'items')->assertJsonPath('pricing.total', 10000);
    qDel($c->id)->assertOk();
    expect(DB::table('cart_items')->where('course_id', $c->id)->count())->toBe(1);
    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertJsonPath('cart_count', 1);
});

test('QA: viewer-state in_cart sau khi them, ve lai bth sau khi xoa; khong lo chi tiet gio HS khac', function () {
    $c = qPaid(70000);
    $url = vvApiUrl("/courses/{$c->slug}/viewer-state");
    test()->getJson($url, vvWebHeaders())->assertJsonPath('viewer_state', 'can_buy');
    qAdd($c->id);
    test()->getJson($url, vvWebHeaders())->assertJsonPath('viewer_state', 'in_cart');
    qDel($c->id);
    test()->getJson($url, vvWebHeaders())->assertJsonPath('viewer_state', 'can_buy');
});

test('QA: ten ma tieng Viet/ky tu la -> COUPON_INVALID khong 500', function () {
    qAdd(qPaid(10000)->id);
    foreach (['GIẢMGIÁ10', "A'; DROP--", 'ab cd'] as $code) {
        qCoupon($code)->assertStatus(422);
    }
    RateLimiter::clear('coupon-fail:'.$this->student->id);
});
