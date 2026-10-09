<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Subject;
use App\Models\User;
use App\Services\Cart\CouponEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/../T04/helpers.php';

function vvCartGet(string $path = '/cart')
{
    return test()->getJson(vvApiUrl($path), vvWebHeaders());
}

function vvCartAdd(int $courseId)
{
    return test()->postJson(vvApiUrl('/cart/items'), ['course_id' => $courseId], vvWebHeaders());
}

function vvCartDel(int $courseId)
{
    return test()->deleteJson(vvApiUrl("/cart/items/{$courseId}"), [], vvWebHeaders());
}

function vvCartCoupon(string $code)
{
    return test()->putJson(vvApiUrl('/cart/coupon'), ['code' => $code], vvWebHeaders());
}

function vvPaid(int $price = 100000, array $attrs = []): Course
{
    return Course::factory()->published()->paid($price)->create($attrs);
}

beforeEach(function () {
    $this->student = vvActAsStudent(User::factory()->student()->create());
});

test('gio rong: items rong, pricing 0, khong tao gio (AC5)', function () {
    vvCartGet()->assertOk()->assertExactJson([
        'items' => [], 'coupon' => null,
        'pricing' => ['subtotal' => 0, 'discount' => 0, 'total' => 0], 'notices' => [], 'pending_order' => null,
    ]);

    expect(Cart::count())->toBe(0);
});

test('AC1: them khoa co phi -> 201 voi gio, badge /auth/me tang', function () {
    $c = vvPaid(120000);

    vvCartAdd($c->id)->assertCreated()
        ->assertJsonPath('items.0.course_id', $c->id)
        ->assertJsonPath('items.0.price', 120000)
        ->assertJsonPath('items.0.unavailable', false)
        ->assertJsonPath('items.0.final_amount', 120000)
        ->assertJsonPath('pricing.total', 120000)
        ->assertJsonMissingPath('data');

    test()->getJson(vvApiUrl('/auth/me'), vvWebHeaders())->assertJsonPath('cart_count', 1);
    test()->getJson(vvApiUrl("/courses/{$c->slug}/viewer-state"), vvWebHeaders())->assertJsonPath('viewer_state', 'in_cart');
});

test('AC2: them trung -> 409 ALREADY_IN_CART, van 1 dong', function () {
    $c = vvPaid();
    vvCartAdd($c->id)->assertCreated();

    vvCartAdd($c->id)->assertStatus(409)->assertJsonPath('code', 'ALREADY_IN_CART');

    expect(CartItem::count())->toBe(1);
});

test('AC3: da so huu -> 409 ALREADY_OWNED; yeu cau dang cho duyet khong chan', function () {
    $c = vvPaid();
    Enrollment::factory()->create(['user_id' => $this->student->id, 'course_id' => $c->id]);

    vvCartAdd($c->id)->assertStatus(409)->assertJsonPath('code', 'ALREADY_OWNED');
    expect(CartItem::count())->toBe(0);
});

test('BR6 + khoa khong ton tai/chua xuat ban/da xoa/sai kieu -> 422 field course_id', function () {
    $free = Course::factory()->published()->create();
    $draft = Course::factory()->paid()->create();
    $deleted = vvPaid();
    $deleted->delete();

    foreach ([$free->id, $draft->id, $deleted->id, 999999] as $id) {
        vvCartAdd($id)->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonStructure(['errors' => ['course_id']]);
    }
    test()->postJson(vvApiUrl('/cart/items'), ['course_id' => 'abc'], vvWebHeaders())->assertStatus(422);
    test()->postJson(vvApiUrl('/cart/items'), [], vvWebHeaders())->assertStatus(422);
    expect(Cart::count())->toBe(0);
});

test('AC4: xoa 1 khoa -> gio con 1, tong cap nhat; xoa khoa khong co trong gio van 200', function () {
    $a = vvPaid(100000);
    $b = vvPaid(50000);
    vvCartAdd($a->id);
    vvCartAdd($b->id);

    vvCartDel($a->id)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('pricing.total', 50000);
    vvCartDel($a->id)->assertOk()->assertJsonCount(1, 'items');
});

test('xoa khoa da bi xoa mem khoi gio van duoc (khong 404)', function () {
    $c = vvPaid();
    vvCartAdd($c->id);
    $c->delete();

    vvCartGet()->assertOk()->assertJsonPath('items.0.unavailable', true)->assertJsonPath('items.0.final_amount', null)
        ->assertJsonPath('pricing.total', 0)->assertJsonPath('notices.0.code', 'ITEMS_UNAVAILABLE');
    vvCartDel($c->id)->assertOk()->assertJsonCount(0, 'items');
});

test('khoa bi ngung ban hoac doi sang mien phi: unavailable, khong tinh tien', function () {
    $a = vvPaid(100000);
    $b = vvPaid(40000);
    vvCartAdd($a->id);
    vvCartAdd($b->id);
    $a->forceFill(['status' => 'unpublished'])->save();
    $b->forceFill(['price' => 0])->save();

    $r = vvCartGet()->assertOk();
    expect(collect($r->json('items'))->pluck('unavailable')->all())->toBe([true, true])
        ->and($r->json('pricing.subtotal'))->toBe(0);
});

test('khoa da so huu sau khi them vao gio: unavailable', function () {
    $c = vvPaid();
    vvCartAdd($c->id);
    Enrollment::factory()->create(['user_id' => $this->student->id, 'course_id' => $c->id]);

    vvCartGet()->assertJsonPath('items.0.unavailable', true)->assertJsonPath('pricing.total', 0);
});

test('gio cua HS khac khong lo ra', function () {
    $c = vvPaid();
    $other = User::factory()->student()->create();
    $cart = Cart::factory()->for($other)->withCourses([$c])->create();

    vvCartGet()->assertJsonCount(0, 'items');
    vvCartDel($c->id)->assertOk();
    expect(CartItem::where('cart_id', $cart->id)->count())->toBe(1);
});

test('AC7: ap ma percent hop le, khong phan biet hoa thuong va khoang trang', function () {
    $c = vvPaid(200000);
    Coupon::factory()->percent(25)->create(['code' => 'TOAN2026']);
    vvCartAdd($c->id);

    vvCartCoupon('  toan2026 ')->assertOk()
        ->assertJsonPath('coupon.code', 'TOAN2026')
        ->assertJsonPath('coupon.discount_amount', 50000)
        ->assertJsonPath('coupon.applies_to_course_ids', [$c->id])
        ->assertJsonPath('items.0.discount_amount', 50000)
        ->assertJsonPath('items.0.final_amount', 150000)
        ->assertJsonPath('pricing', ['subtotal' => 200000, 'discount' => 50000, 'total' => 150000]);

    // Idempotent
    vvCartCoupon('TOAN2026')->assertOk()->assertJsonPath('pricing.total', 150000);
    expect(Coupon::first()->used_count)->toBe(0);
});

test('AC8: ma khong ton tai/chua bat dau/vo hieu -> cung COUPON_INVALID; khong doi gio', function () {
    $c = vvPaid();
    vvCartAdd($c->id);
    Coupon::factory()->upcoming()->create(['code' => 'UPCOMING1']);
    Coupon::factory()->inactive()->create(['code' => 'INACTIVE1']);

    foreach (['NOPE9999', 'UPCOMING1', 'INACTIVE1'] as $code) {
        $r = vvCartCoupon($code)->assertStatus(422)->assertJsonPath('code', 'COUPON_INVALID');
        $message = $r->json('message');
        expect($message)->toBe('Mã giảm giá không hợp lệ.');
    }
    expect(Cart::first()->coupon_id)->toBeNull();
});

test('AC8: het han / het luot -> COUPON_EXPIRED', function () {
    vvCartAdd(vvPaid()->id);
    Coupon::factory()->expired()->create(['code' => 'OLD12345']);
    Coupon::factory()->exhausted(3)->create(['code' => 'FULL1234']);

    vvCartCoupon('OLD12345')->assertStatus(422)->assertJsonPath('code', 'COUPON_EXPIRED');
    vvCartCoupon('FULL1234')->assertStatus(422)->assertJsonPath('code', 'COUPON_EXPIRED');
});

test('ma het han theo gio dia phuong: valid_until vua qua 1 phut (khong lech 7h do UTC)', function () {
    vvCartAdd(vvPaid()->id);
    Coupon::factory()->create(['code' => 'EDGE1234', 'valid_from' => now()->subDay(), 'valid_until' => now()->subMinute()]);
    Coupon::factory()->create(['code' => 'EDGE5678', 'valid_from' => now()->subDay(), 'valid_until' => now()->addMinute()]);

    vvCartCoupon('EDGE1234')->assertStatus(422)->assertJsonPath('code', 'COUPON_EXPIRED');
    vvCartCoupon('EDGE5678')->assertOk();
});

test('AC8: da dung ma -> COUPON_ALREADY_USED, nguoi khac van dung duoc (bang coupon_usages do T18 tao: gia lap)', function () {
    vvCartAdd(vvPaid()->id);
    $coupon = Coupon::factory()->create(['code' => 'ONCE1234']);
    $usedBy = [$this->student->id];

    app()->bind(CouponEvaluator::class, fn () => new class($usedBy) extends CouponEvaluator
    {
        public function __construct(private array $usedBy) {}

        protected function alreadyUsedBy(Coupon $coupon, User $user): bool
        {
            return in_array($user->getKey(), $this->usedBy, true);
        }
    });

    vvCartCoupon('ONCE1234')->assertStatus(422)->assertJsonPath('code', 'COUPON_ALREADY_USED');
    expect(Cart::first()->coupon_id)->toBeNull();
});

test('AC8/AC11: ma gioi han khoa/chuyen de: chi khoa trong pham vi duoc giam; gio khong co khoa nao thuoc -> NOT_APPLICABLE', function () {
    $in = vvPaid(100000);
    $out = vvPaid(300000);
    $viaSubject = vvPaid(200000);
    $subject = Subject::factory()->create();
    $viaSubject->subjects()->attach($subject->id);

    $byCourse = Coupon::factory()->percent(50)->restricted()->create(['code' => 'COURSEONLY']);
    $byCourse->courses()->attach($in->id);
    $bySubject = Coupon::factory()->percent(10)->restricted()->create(['code' => 'SUBJONLY']);
    $bySubject->subjects()->attach($subject->id);

    vvCartAdd($out->id);
    vvCartCoupon('COURSEONLY')->assertStatus(422)->assertJsonPath('code', 'COUPON_NOT_APPLICABLE');

    vvCartAdd($in->id);
    vvCartAdd($viaSubject->id);
    vvCartCoupon('COURSEONLY')->assertOk()->assertJsonPath('pricing', ['subtotal' => 600000, 'discount' => 50000, 'total' => 550000]);
    vvCartCoupon('SUBJONLY')->assertOk()->assertJsonPath('pricing.discount', 20000)
        ->assertJsonPath('coupon.applies_to_course_ids', [$viaSubject->id]);
});

test('review T15 b: is_restricted nhung pivot rong -> COUPON_NOT_APPLICABLE (khong ap toan bo)', function () {
    vvCartAdd(vvPaid()->id);
    Coupon::factory()->restricted()->create(['code' => 'EMPTYSCOPE']);

    vvCartCoupon('EMPTYSCOPE')->assertStatus(422)->assertJsonPath('code', 'COUPON_NOT_APPLICABLE');
});

test('review T16 M1 (US-013 BR6): fixed lon hon phan ap dung van ap duoc, giam toi da bang phan ap dung', function () {
    vvCartAdd(vvPaid(80000)->id);
    $other = vvPaid(50000);
    vvCartAdd($other->id);
    Coupon::factory()->fixed(100000)->create(['code' => 'BIGFIXED']);
    Coupon::factory()->fixed(30000)->create(['code' => 'SMALLFIX']);

    vvCartCoupon('BIGFIXED')->assertOk()->assertJsonPath('pricing', ['subtotal' => 130000, 'discount' => 100000, 'total' => 30000]);
    vvCartCoupon('SMALLFIX')->assertOk()->assertJsonPath('pricing.total', 100000);
});

test('review T16 M1: fixed dua tong ve 0d chi cho khi co max_uses + valid_until', function () {
    $c = vvPaid(80000);
    vvCartAdd($c->id);
    Coupon::factory()->fixed(100000)->create(['code' => 'ZEROOPEN']);
    Coupon::factory()->fixed(80000)->create(['code' => 'EQUALOPEN']);
    Coupon::factory()->fixed(100000)->create(['code' => 'ZEROSAFE', 'max_uses' => 10, 'valid_until' => now()->addDay()]);

    vvCartCoupon('ZEROOPEN')->assertStatus(422)->assertJsonPath('code', 'COUPON_NOT_APPLICABLE');
    vvCartCoupon('EQUALOPEN')->assertStatus(422)->assertJsonPath('code', 'COUPON_NOT_APPLICABLE');
    vvCartCoupon('ZEROSAFE')->assertOk()->assertJsonPath('pricing.total', 0)->assertJsonPath('pricing.discount', 80000);
});

test('review T16 M1: xoa bot khoa khien fixed lon hon tong (ma co gioi han) khong go ma im lang', function () {
    $a = vvPaid(100000);
    $b = vvPaid(20000);
    vvCartAdd($a->id);
    vvCartAdd($b->id);
    Coupon::factory()->fixed(60000)->create(['code' => 'FIX60000', 'max_uses' => 5, 'valid_until' => now()->addDay()]);
    vvCartCoupon('FIX60000')->assertOk();

    $r = vvCartDel($a->id)->assertOk();

    expect($r->json('coupon.code'))->toBe('FIX60000')->and($r->json('pricing.discount'))->toBe(20000)
        ->and($r->json('pricing.total'))->toBe(0)->and($r->json('notices'))->toBe([]);
});

test('ap ma khi gio rong / chua co gio -> NOT_APPLICABLE', function () {
    Coupon::factory()->create(['code' => 'NOCART123']);

    vvCartCoupon('NOCART123')->assertStatus(422)->assertJsonPath('code', 'COUPON_NOT_APPLICABLE');
    expect(Cart::count())->toBe(0);
});

test('AC9: ma moi thay ma cu; ma loi giu nguyen ma cu', function () {
    vvCartAdd(vvPaid(100000)->id);
    Coupon::factory()->percent(10)->create(['code' => 'TEN10000']);
    Coupon::factory()->percent(30)->create(['code' => 'THIRTY300']);

    vvCartCoupon('TEN10000')->assertOk()->assertJsonPath('pricing.discount', 10000);
    vvCartCoupon('THIRTY300')->assertOk()->assertJsonPath('coupon.code', 'THIRTY300')->assertJsonPath('pricing.discount', 30000);
    vvCartCoupon('NOPE00000')->assertStatus(422);
    vvCartGet()->assertJsonPath('coupon.code', 'THIRTY300')->assertJsonPath('pricing.discount', 30000);
});

test('AC10: xoa khoa duy nhat trong pham vi -> tu go ma + notice COUPON_REMOVED', function () {
    $in = vvPaid(100000);
    $out = vvPaid(50000);
    $coupon = Coupon::factory()->restricted()->percent(50)->create(['code' => 'SCOPED123']);
    $coupon->courses()->attach($in->id);
    vvCartAdd($in->id);
    vvCartAdd($out->id);
    vvCartCoupon('SCOPED123')->assertOk();

    vvCartDel($in->id)->assertOk()
        ->assertJsonPath('coupon', null)
        ->assertJsonPath('pricing.total', 50000)
        ->assertJsonPath('notices.0.code', 'COUPON_REMOVED');
    expect(Cart::first()->coupon_id)->toBeNull();
});

test('ma bi vo hieu / het han giua chung: vao lai gio -> tu go ma kem notice', function () {
    vvCartAdd(vvPaid(100000)->id);
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'LATER1234']);
    vvCartCoupon('LATER1234')->assertOk();

    $coupon->forceFill(['status' => 'inactive'])->save();

    vvCartGet()->assertOk()->assertJsonPath('coupon', null)->assertJsonPath('pricing.total', 100000)
        ->assertJsonPath('notices.0.code', 'COUPON_REMOVED');
    expect(Cart::first()->coupon_id)->toBeNull();
    vvCartGet()->assertJsonPath('notices', []);
});

test('gia khoa ha sau khi ap ma fixed: giam theo min, ma khong bi go', function () {
    $c = vvPaid(100000);
    vvCartAdd($c->id);
    Coupon::factory()->fixed(60000)->create(['code' => 'FIX60000']);
    vvCartCoupon('FIX60000')->assertOk()->assertJsonPath('pricing.total', 40000);

    $c->forceFill(['price' => 70000])->save();

    vvCartGet()->assertJsonPath('coupon.code', 'FIX60000')->assertJsonPath('pricing.total', 10000);
});

test('DELETE /cart/coupon go ma, idempotent', function () {
    vvCartAdd(vvPaid(100000)->id);
    Coupon::factory()->percent(10)->create(['code' => 'REMOVE123']);
    vvCartCoupon('REMOVE123');

    test()->deleteJson(vvApiUrl('/cart/coupon'), [], vvWebHeaders())->assertOk()->assertJsonPath('coupon', null)->assertJsonPath('pricing.total', 100000);
    test()->deleteJson(vvApiUrl('/cart/coupon'), [], vvWebHeaders())->assertOk();
});

test('S18: sai qua 30 lan/ngay -> 429 TOO_MANY_ATTEMPTS co Retry-After; lan dung khong bi tinh', function () {
    $key = 'coupon-fail:'.$this->student->id;
    RateLimiter::clear($key);
    vvCartAdd(vvPaid()->id);
    Coupon::factory()->percent(10)->create(['code' => 'GOOD12345']);

    for ($i = 0; $i < 5; $i++) {
        vvCartCoupon('GOOD12345')->assertOk();
    }
    expect(RateLimiter::attempts($key))->toBe(0);

    vvCartCoupon('NOPE00000')->assertStatus(422);
    expect(RateLimiter::attempts($key))->toBe(1);
    RateLimiter::clear($key);

    for ($i = 0; $i < 30; $i++) {
        RateLimiter::hit($key, 86400);
    }
    vvCartCoupon('GOOD12345')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_ATTEMPTS')->assertHeader('Retry-After');
    RateLimiter::clear($key);
});

test('thieu code hoac code qua dai -> 422', function () {
    test()->putJson(vvApiUrl('/cart/coupon'), [], vvWebHeaders())->assertStatus(422);
    test()->putJson(vvApiUrl('/cart/coupon'), ['code' => str_repeat('A', 51)], vvWebHeaders())->assertStatus(422);
    test()->putJson(vvApiUrl('/cart/coupon'), ['code' => ['x']], vvWebHeaders())->assertStatus(422);
});

test('gio > 20 khoa tinh dung, so truy van khong tang theo so khoa (khong N+1)', function () {
    $courses = Course::factory()->count(25)->published()->paid(10000)->create();
    Cart::factory()->for($this->student)->withCourses($courses)->create();

    DB::enableQueryLog();
    vvCartGet()->assertOk()->assertJsonCount(25, 'items')->assertJsonPath('pricing.total', 250000);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(15);
});

test('giao vien/admin khong dung duoc gio (role:hoc_sinh)', function () {
    vvActAsStudent(User::factory()->teacher()->create());

    vvCartGet()->assertForbidden();
});

test('xoa ma khi chua dung (coupons.delete) -> carts.coupon_id ve null (FK null on delete)', function () {
    vvCartAdd(vvPaid()->id);
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'GONE12345']);
    vvCartCoupon('GONE12345')->assertOk();

    $coupon->delete();

    expect(Cart::first()->coupon_id)->toBeNull();
    vvCartGet()->assertOk()->assertJsonPath('coupon', null);
});
