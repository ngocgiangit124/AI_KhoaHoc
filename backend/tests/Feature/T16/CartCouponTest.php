<?php

use App\Enums\CouponDiscountType;
use App\Enums\CouponStatus;
use App\Enums\CourseStatus;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Subject;
use App\Models\User;
use App\Services\Cart\CouponUsageChecker;
use App\Services\Cart\PricingCalculator;
use App\Services\Cart\PricingResult;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Áp mã giảm giá ở giỏ (T16, US-004 BR7-BR9 AC7-AC11, S18).
 */
function vvCouponUrl(): string
{
    return 'http://'.config('app.api_host').'/api/v1/cart/coupon';
}

function vvCouponHeaders(): array
{
    return ['Origin' => config('app.frontend_url')];
}

/**
 * @param  list<Course>  $courses
 */
function vvCartWith(User $student, array $courses): Cart
{
    $cart = Cart::factory()->create(['user_id' => $student->id]);

    foreach ($courses as $course) {
        CartItem::factory()->create(['cart_id' => $cart->id, 'course_id' => $course->id]);
    }

    return $cart;
}

function vvApply(User $student, string $code)
{
    return test()->actingAs($student)->putJson(vvCouponUrl(), ['code' => $code], vvCouponHeaders());
}

function vvPaid(int $price = 200000): Course
{
    return Course::factory()->published()->create(['price' => $price]);
}

beforeEach(function () {
    $this->student = User::factory()->student()->create();
});

test('ap ma percent hop le: hien so tien giam va tong sau giam (AC7)', function () {
    vvCartWith($this->student, [vvPaid(200000), vvPaid(300000)]);
    Coupon::factory()->create(['code' => 'GIAM20', 'discount_value' => 20]);

    $response = vvApply($this->student, 'GIAM20');

    $response->assertOk();
    $response->assertJsonPath('coupon', ['code' => 'GIAM20', 'discount_type' => 'percent', 'discount_value' => 20]);
    $response->assertJsonPath('pricing', ['subtotal' => 500000, 'discount' => 100000, 'total' => 400000]);
    $response->assertJsonPath('items.0.discount_amount', 40000);
    $response->assertJsonPath('items.1.discount_amount', 60000);
    expect(Cart::query()->firstOrFail()->coupon_id)->toBe(Coupon::query()->firstOrFail()->id);
});

test('ma khong phan biet hoa thuong va bo khoang trang (BR1)', function () {
    vvCartWith($this->student, [vvPaid()]);
    Coupon::factory()->create(['code' => 'TOAN2026']);

    vvApply($this->student, '  toan2026 ')->assertOk()->assertJsonPath('coupon.code', 'TOAN2026');
});

test('gio chua co khoa mua duoc thi ap ma tra COUPON_NOT_APPLICABLE', function () {
    Coupon::factory()->create(['code' => 'GIAM20']);

    vvApply($this->student, 'GIAM20')->assertStatus(422)->assertJson(['code' => 'COUPON_NOT_APPLICABLE']);
});

test('fixed_amount lon hon gia gio: giam toi da bang gia, tong khong am (BR8)', function () {
    vvCartWith($this->student, [vvPaid(100000)]);
    Coupon::factory()->fixedAmount(5_000_000)->create(['code' => 'BIGFIX']);

    $response = vvApply($this->student, 'BIGFIX');

    $response->assertOk();
    $response->assertJsonPath('pricing', ['subtotal' => 100000, 'discount' => 100000, 'total' => 0]);
});

test('ma khong ton tai / chua bat dau / vo hieu: CUNG COUPON_INVALID, cung thong diep (S18)', function () {
    vvCartWith($this->student, [vvPaid()]);

    Coupon::factory()->create(['code' => 'CHUABATDAU', 'valid_from' => now()->addDay(), 'valid_until' => now()->addMonth()]);
    Coupon::factory()->inactive()->create(['code' => 'VOHIEU']);

    $bodies = [];

    foreach (['KHONGTONTAI', 'CHUABATDAU', 'VOHIEU', 'sai dinh dang!!', str_repeat('A', 50)] as $code) {
        $response = vvApply($this->student, $code);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'COUPON_INVALID']);
        $bodies[$code] = $response->json();
    }

    // Code + message giong het nhau (khong co truong nao phan biet ly do).
    expect(collect($bodies)->map(fn ($b) => [$b['code'], $b['message']])->unique()->values())->toHaveCount(1);
    expect(collect($bodies)->map(fn ($b) => array_keys($b))->unique()->values())->toHaveCount(1);
    expect(Cart::query()->firstOrFail()->coupon_id)->toBeNull();
});

test('ma het han tra COUPON_EXPIRED, het luot tra COUPON_EXHAUSTED (api-contract 1.7, AC8), khong doi gio', function () {
    vvCartWith($this->student, [vvPaid()]);
    Coupon::factory()->expired()->create(['code' => 'HETHAN']);
    Coupon::factory()->create(['code' => 'HETLUOT', 'max_uses' => 5, 'used_count' => 5]);

    $expired = vvApply($this->student, 'HETHAN');
    $expired->assertStatus(422)->assertJson(['code' => 'COUPON_EXPIRED']);

    $exhausted = vvApply($this->student, 'HETLUOT');
    $exhausted->assertStatus(422)->assertJson(['code' => 'COUPON_EXHAUSTED']);

    expect($expired->json('message'))->not->toBe($exhausted->json('message'));
    expect(Cart::query()->firstOrFail()->coupon_id)->toBeNull();
});

test('ma vua het han dung bien: valid_until trong tuong lai 1 phut van dung duoc', function () {
    vvCartWith($this->student, [vvPaid()]);
    Coupon::factory()->create(['code' => 'SAPHET', 'valid_until' => now()->addMinute()]);

    vvApply($this->student, 'SAPHET')->assertOk();
});

test('ma khong co khoa nao trong gio thuoc pham vi: COUPON_NOT_APPLICABLE, khong doi tong tien (AC8)', function () {
    $inScope = vvPaid();
    $outScope = vvPaid();
    vvCartWith($this->student, [$outScope]);

    $coupon = Coupon::factory()->restricted()->create(['code' => 'CHIKHOA1']);
    $coupon->courses()->sync([$inScope->id]);

    $response = vvApply($this->student, 'CHIKHOA1');

    $response->assertStatus(422);
    $response->assertJson(['code' => 'COUPON_NOT_APPLICABLE']);
    expect(Cart::query()->firstOrFail()->coupon_id)->toBeNull();
});

test('ma gioi han theo khoa: chi khoa thuoc pham vi duoc giam, khoa con lai giu gia goc (AC11)', function () {
    $inScope = vvPaid(200000);
    $outScope = vvPaid(300000);
    vvCartWith($this->student, [$inScope, $outScope]);

    $coupon = Coupon::factory()->restricted()->create(['code' => 'CHIKHOA1', 'discount_value' => 50]);
    $coupon->courses()->sync([$inScope->id]);

    $response = vvApply($this->student, 'CHIKHOA1');

    $response->assertOk();
    $response->assertJsonPath('pricing', ['subtotal' => 500000, 'discount' => 100000, 'total' => 400000]);
    $response->assertJsonPath('items.0.discount_amount', 100000);
    $response->assertJsonPath('items.1.discount_amount', 0);
    $response->assertJsonPath('items.1.final_amount', 300000);
});

test('ma gioi han theo chuyen de: khoa thuoc chuyen de duoc giam (ke ca chuyen de bi an)', function () {
    $subject = Subject::factory()->create();
    $inScope = vvPaid(200000);
    $inScope->subjects()->attach($subject->id);
    $outScope = vvPaid(300000);
    vvCartWith($this->student, [$inScope, $outScope]);

    $coupon = Coupon::factory()->restricted()->create(['code' => 'CHUYENDE', 'discount_value' => 10]);
    $coupon->subjects()->sync([$subject->id]);

    $response = vvApply($this->student, 'CHUYENDE');

    $response->assertOk();
    $response->assertJsonPath('pricing.discount', 20000);
    $response->assertJsonPath('items.1.discount_amount', 0);
});

test('pham vi la hop cua khoa cu the va chuyen de', function () {
    $subject = Subject::factory()->create();
    $bySubject = vvPaid(100000);
    $bySubject->subjects()->attach($subject->id);
    $direct = vvPaid(100000);
    $neither = vvPaid(100000);
    vvCartWith($this->student, [$bySubject, $direct, $neither]);

    $coupon = Coupon::factory()->restricted()->create(['code' => 'HOP', 'discount_value' => 100, 'max_uses' => 10]);
    $coupon->subjects()->sync([$subject->id]);
    $coupon->courses()->sync([$direct->id]);

    vvApply($this->student, 'HOP')->assertOk()->assertJsonPath('pricing.total', 100000);
});

test('ma da tung dung bi tu choi COUPON_ALREADY_USED (US-013 BR3)', function () {
    vvCartWith($this->student, [vvPaid()]);
    Coupon::factory()->create(['code' => 'DADUNG']);

    app()->bind(CouponUsageChecker::class, fn () => new class implements CouponUsageChecker
    {
        public function hasUsed(Coupon $coupon, User $user): bool
        {
            return true;
        }
    });

    vvApply($this->student, 'DADUNG')->assertStatus(422)->assertJson(['code' => 'COUPON_ALREADY_USED']);
});

test('ma moi thay ma cu, tong tinh lai theo ma moi (AC9)', function () {
    vvCartWith($this->student, [vvPaid(200000)]);
    Coupon::factory()->create(['code' => 'GIAM10', 'discount_value' => 10]);
    Coupon::factory()->create(['code' => 'GIAM50', 'discount_value' => 50]);

    vvApply($this->student, 'GIAM10')->assertOk()->assertJsonPath('pricing.total', 180000);
    $response = vvApply($this->student, 'GIAM50');

    $response->assertOk();
    $response->assertJsonPath('coupon.code', 'GIAM50');
    $response->assertJsonPath('pricing.total', 100000);
    expect(Cart::query()->count())->toBe(1);
});

test('ap ma sai khi dang co ma hop le: giu nguyen ma cu va tong tien (AC8)', function () {
    vvCartWith($this->student, [vvPaid(200000)]);
    Coupon::factory()->create(['code' => 'GIAM10', 'discount_value' => 10]);
    vvApply($this->student, 'GIAM10')->assertOk();

    vvApply($this->student, 'SAIMA')->assertStatus(422);

    $cart = test()->actingAs($this->student)->getJson('http://'.config('app.api_host').'/api/v1/cart', vvCouponHeaders());
    $cart->assertJsonPath('coupon.code', 'GIAM10');
    $cart->assertJsonPath('pricing.total', 180000);
});

test('xoa bot khoa khien ma het pham vi: tu go ma va bao (AC10)', function () {
    $inScope = vvPaid(200000);
    $other = vvPaid(300000);
    vvCartWith($this->student, [$inScope, $other]);
    $coupon = Coupon::factory()->restricted()->create(['code' => 'CHIKHOA1', 'discount_value' => 50]);
    $coupon->courses()->sync([$inScope->id]);
    vvApply($this->student, 'CHIKHOA1')->assertOk();

    $response = test()->actingAs($this->student)->deleteJson(
        'http://'.config('app.api_host')."/api/v1/cart/items/{$inScope->id}", [], vvCouponHeaders()
    );

    $response->assertOk();
    $response->assertJsonPath('coupon', null);
    $response->assertJsonPath('pricing', ['subtotal' => 300000, 'discount' => 0, 'total' => 300000]);
    $response->assertJsonPath('notices.0.code', 'COUPON_REMOVED');
    expect(Cart::query()->firstOrFail()->coupon_id)->toBeNull();
});

test('xoa bot khoa nhung ma van du dieu kien: giu ma va tinh lai giam gia (AC4)', function () {
    $a = vvPaid(200000);
    $b = vvPaid(300000);
    vvCartWith($this->student, [$a, $b]);
    Coupon::factory()->create(['code' => 'GIAM10', 'discount_value' => 10]);
    vvApply($this->student, 'GIAM10')->assertOk()->assertJsonPath('pricing.discount', 50000);

    $response = test()->actingAs($this->student)->deleteJson(
        'http://'.config('app.api_host')."/api/v1/cart/items/{$a->id}", [], vvCouponHeaders()
    );

    $response->assertJsonPath('coupon.code', 'GIAM10');
    $response->assertJsonPath('pricing', ['subtotal' => 300000, 'discount' => 30000, 'total' => 270000]);
    $response->assertJsonPath('notices', []);
});

test('ma bi admin vo hieu hoa luc dang ap: vao lai gio tu go ma (edge case)', function () {
    vvCartWith($this->student, [vvPaid(200000)]);
    $coupon = Coupon::factory()->create(['code' => 'GIAM10', 'discount_value' => 10]);
    vvApply($this->student, 'GIAM10')->assertOk();

    $coupon->forceFill(['status' => CouponStatus::Inactive])->save();

    $response = test()->actingAs($this->student)->getJson('http://'.config('app.api_host').'/api/v1/cart', vvCouponHeaders());

    $response->assertJsonPath('coupon', null);
    $response->assertJsonPath('pricing.total', 200000);
    $response->assertJsonPath('notices.0.code', 'COUPON_REMOVED');
    expect(Cart::query()->firstOrFail()->coupon_id)->toBeNull();

    // Lan sau khong con thong bao.
    test()->actingAs($this->student)->getJson('http://'.config('app.api_host').'/api/v1/cart', vvCouponHeaders())
        ->assertJsonPath('notices', []);
});

test('ma het han hoac het luot sau khi ap cung bi go khi xem gio', function () {
    vvCartWith($this->student, [vvPaid(200000)]);
    $coupon = Coupon::factory()->create(['code' => 'GIAM10', 'max_uses' => 2, 'used_count' => 1]);
    vvApply($this->student, 'GIAM10')->assertOk();

    $coupon->forceFill(['used_count' => 2])->save();

    test()->actingAs($this->student)->getJson('http://'.config('app.api_host').'/api/v1/cart', vvCouponHeaders())
        ->assertJsonPath('coupon', null);
});

test('go ma: DELETE /cart/coupon, idempotent', function () {
    vvCartWith($this->student, [vvPaid(200000)]);
    Coupon::factory()->create(['code' => 'GIAM10', 'discount_value' => 10]);
    vvApply($this->student, 'GIAM10')->assertOk();

    $delete = fn () => test()->actingAs($this->student)->deleteJson(vvCouponUrl(), [], vvCouponHeaders());

    $delete()->assertOk()->assertJsonPath('coupon', null)->assertJsonPath('pricing.total', 200000);
    $delete()->assertOk()->assertJsonPath('coupon', null);
});

test('DELETE /cart/coupon khi chua co gio khong tao gio', function () {
    test()->actingAs($this->student)->deleteJson(vvCouponUrl(), [], vvCouponHeaders())->assertOk();

    expect(Cart::query()->count())->toBe(0);
});

test('khoa unavailable khong duoc tinh vao pham vi/giam gia', function () {
    $gone = vvPaid(500000);
    $ok = vvPaid(100000);
    vvCartWith($this->student, [$gone, $ok]);
    $gone->forceFill(['status' => CourseStatus::Unpublished])->save();
    Coupon::factory()->create(['code' => 'GIAM50', 'discount_value' => 50]);

    vvApply($this->student, 'GIAM50')->assertOk()->assertJsonPath('pricing', ['subtotal' => 100000, 'discount' => 50000, 'total' => 50000]);
});

test('code thieu / qua 50 ky tu tra 422 VALIDATION_ERROR (khong tinh lan sai)', function () {
    test()->actingAs($this->student)->putJson(vvCouponUrl(), [], vvCouponHeaders())->assertStatus(422)->assertJson(['code' => 'VALIDATION_ERROR']);
    vvApply($this->student, str_repeat('A', 51))->assertStatus(422)->assertJson(['code' => 'VALIDATION_ERROR']);
    test()->actingAs($this->student)->putJson(vvCouponUrl(), ['code' => ['a']], vvCouponHeaders())->assertStatus(422);

    expect(RateLimiter::attempts('coupon-fail:user:'.$this->student->id))->toBe(0);
});

test('chuoi tan cong (SQL, wildcard, unicode) chi ra COUPON_INVALID', function () {
    vvCartWith($this->student, [vvPaid()]);
    Coupon::factory()->create(['code' => 'ABCD1234']);

    foreach (["ABCD1234' OR '1'='1", 'ABCD%', 'ABC_1234', 'ＡＢＣＤ１２３４', 'ABCD 1234', 'ÀBCD1234'] as $code) {
        vvApply($this->student, $code)->assertStatus(422)->assertJson(['code' => 'COUPON_INVALID']);
    }
});

// --- S18: limiter 30 lan SAI / ngay ---------------------------------------

test('30 lan sai/ngay: lan thu 31 bi 429 TOO_MANY_ATTEMPTS + Retry-After, ke ca voi ma dung (S18)', function () {
    config(['coupon.max_failed_per_day' => 30]);
    vvCartWith($this->student, [vvPaid()]);
    Coupon::factory()->create(['code' => 'DUNGMA']);

    // Route throttle:coupon co lop 10 lan/phut rieng: nang de test tran ngay.
    RateLimiter::for('coupon', fn () => Limit::none());

    for ($i = 1; $i <= 30; $i++) {
        vvApply($this->student, 'SAI'.$i)->assertStatus(422)->assertJson(['code' => 'COUPON_INVALID']);
    }

    $blocked = vvApply($this->student, 'DUNGMA');

    $blocked->assertStatus(429);
    $blocked->assertJson(['code' => 'TOO_MANY_ATTEMPTS']);
    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
    expect(Cart::query()->firstOrFail()->coupon_id)->toBeNull();

    // Ghi audit dung 1 lan khi cham tran.
    vvApply($this->student, 'DUNGMA')->assertStatus(429);
    expect(AuditLog::query()->where('action', 'coupon.attempt_limit')->count())->toBe(1);
});

test('lan ap ma THANH CONG khong tieu hao han muc (chi lan sai moi tinh)', function () {
    config(['coupon.max_failed_per_day' => 3]);
    vvCartWith($this->student, [vvPaid()]);
    Coupon::factory()->create(['code' => 'DUNGMA']);
    RateLimiter::for('coupon', fn () => Limit::none());

    for ($i = 0; $i < 10; $i++) {
        vvApply($this->student, 'DUNGMA')->assertOk();
    }

    expect(RateLimiter::attempts('coupon-fail:user:'.$this->student->id))->toBe(0);

    vvApply($this->student, 'SAI1')->assertStatus(422);
    vvApply($this->student, 'SAI2')->assertStatus(422);
    vvApply($this->student, 'SAI3')->assertStatus(422);
    vvApply($this->student, 'DUNGMA')->assertStatus(429);
});

test('han muc tinh rieng tung tai khoan: hoc sinh khac khong bi anh huong', function () {
    config(['coupon.max_failed_per_day' => 2, 'coupon.max_failed_per_day_per_ip' => 100]);
    $other = User::factory()->student()->create();
    vvCartWith($this->student, [vvPaid()]);
    vvCartWith($other, [vvPaid()]);
    RateLimiter::for('coupon', fn () => Limit::none());

    vvApply($this->student, 'SAI1');
    vvApply($this->student, 'SAI2');
    vvApply($this->student, 'SAI3')->assertStatus(429);

    vvApply($other, 'SAI1')->assertStatus(422);
});

test('han muc theo IP: doi tai khoan van bi chan (S18)', function () {
    config(['coupon.max_failed_per_day' => 100, 'coupon.max_failed_per_day_per_ip' => 3]);
    RateLimiter::for('coupon', fn () => Limit::none());

    $students = User::factory()->student()->count(4)->create();

    foreach ($students as $i => $s) {
        vvCartWith($s, [vvPaid()]);
    }

    foreach ([0, 1, 2] as $i) {
        vvApply($students[$i], 'SAI')->assertStatus(422);
    }

    vvApply($students[3], 'SAI')->assertStatus(429);
});

test('bo dem duoc tang TRUOC khi tra ma (nguyen tu): vuot tran thi khong cham DB tra ma', function () {
    config(['coupon.max_failed_per_day' => 1]);
    vvCartWith($this->student, [vvPaid()]);
    RateLimiter::for('coupon', fn () => Limit::none());

    vvApply($this->student, 'SAI1')->assertStatus(422);
    vvApply($this->student, 'SAI2')->assertStatus(429);

    DB::flushQueryLog();
    DB::enableQueryLog();
    vvApply($this->student, 'SAI3')->assertStatus(429);

    $couponQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `coupons`'));
    expect($couponQueries)->toHaveCount(0);
});

test('throttle:coupon cua route van chan 10 lan/phut', function () {
    vvCartWith($this->student, [vvPaid()]);

    for ($i = 0; $i < 10; $i++) {
        vvApply($this->student, 'SAI'.$i)->assertStatus(422);
    }

    vvApply($this->student, 'SAI11')->assertStatus(429);
});

test('ma giam 100% khong lam tong am', function () {
    vvCartWith($this->student, [vvPaid(50000)]);
    Coupon::factory()->fullDiscount()->create(['code' => 'FREE100']);

    $response = vvApply($this->student, 'FREE100');

    $response->assertOk();
    $response->assertJsonPath('pricing', ['subtotal' => 50000, 'discount' => 50000, 'total' => 0]);
    expect(Coupon::query()->firstOrFail()->discount_type)->toBe(CouponDiscountType::Percent);
});

test('nguoi da cham tran tai khoan spam tiep KHONG dot han muc IP (R1)', function () {
    config(['coupon.max_failed_per_day' => 5, 'coupon.max_failed_per_day_per_ip' => 100]);
    vvCartWith($this->student, [vvPaid()]);
    RateLimiter::for('coupon', fn () => Limit::none());

    for ($i = 1; $i <= 5; $i++) {
        vvApply($this->student, 'SAI'.$i)->assertStatus(422);
    }

    $ipKey = 'coupon-fail:ip:127.0.0.1';
    expect(RateLimiter::attempts($ipKey))->toBe(5);

    for ($i = 0; $i < 200; $i++) {
        vvApply($this->student, 'SPAM'.$i)->assertStatus(429);
    }

    expect(RateLimiter::attempts($ipKey))->toBe(5);
    expect(RateLimiter::attempts('coupon-fail:user:'.$this->student->id))->toBe(5);

    // Hoc sinh khac cung IP van dung duoc (IP chua bi dot).
    $other = User::factory()->student()->create();
    vvCartWith($other, [vvPaid()]);
    vvApply($other, 'SAI')->assertStatus(422);
});

test('bi chan theo IP thi khong bi cong vao han muc tai khoan (R1)', function () {
    config(['coupon.max_failed_per_day' => 100, 'coupon.max_failed_per_day_per_ip' => 2]);
    vvCartWith($this->student, [vvPaid()]);
    RateLimiter::for('coupon', fn () => Limit::none());

    vvApply($this->student, 'SAI1')->assertStatus(422);
    vvApply($this->student, 'SAI2')->assertStatus(422);

    for ($i = 0; $i < 10; $i++) {
        vvApply($this->student, 'BLOCKED'.$i)->assertStatus(429);
    }

    expect(RateLimiter::attempts('coupon-fail:user:'.$this->student->id))->toBe(2);
    expect(RateLimiter::attempts('coupon-fail:ip:127.0.0.1'))->toBe(2);
});

test('audit coupon.attempt_limit ghi scope user hoac ip', function () {
    config(['coupon.max_failed_per_day' => 1, 'coupon.max_failed_per_day_per_ip' => 100]);
    vvCartWith($this->student, [vvPaid()]);
    RateLimiter::for('coupon', fn () => Limit::none());

    vvApply($this->student, 'SAI1')->assertStatus(422);
    vvApply($this->student, 'SAI2')->assertStatus(429);

    $log = AuditLog::query()->where('action', 'coupon.attempt_limit')->firstOrFail();
    expect($log->changes)->toBe(['scope' => 'user']);
});

function vvBindOverflowingCalculator(): void
{
    // Mo phong gio vuot gioi han so nguyen: calculate() ne InvalidArgumentException khi co ma.
    app()->instance(PricingCalculator::class, new class extends PricingCalculator
    {
        public function calculate(array $lines, ?CouponDiscountType $discountType = null, ?int $discountValue = null): PricingResult
        {
            if ($discountType !== null) {
                throw new InvalidArgumentException('Tổng giá vượt giới hạn tính toán.');
            }

            return parent::calculate($lines);
        }
    });
}

test('gio qua lon de tinh giam gia: ap ma bi 422 CART_TOO_LARGE, khong luu ma', function () {
    vvCartWith($this->student, [vvPaid(200000)]);
    Coupon::factory()->create(['code' => 'GIAM10']);
    vvBindOverflowingCalculator();

    vvApply($this->student, 'GIAM10')->assertStatus(422)->assertJson(['code' => 'CART_TOO_LARGE']);

    expect(Cart::query()->firstOrFail()->coupon_id)->toBeNull();
});

test('gio dang co ma nhung qua lon de tinh: GET /cart khong 500, tra gia goc kem PRICING_LIMIT', function () {
    $cart = vvCartWith($this->student, [vvPaid(200000)]);
    $coupon = Coupon::factory()->create(['code' => 'GIAM10']);
    $cart->forceFill(['coupon_id' => $coupon->id])->save();
    vvBindOverflowingCalculator();

    $response = test()->actingAs($this->student)->getJson('http://'.config('app.api_host').'/api/v1/cart', vvCouponHeaders());

    $response->assertOk();
    $response->assertJsonPath('coupon', null);
    $response->assertJsonPath('pricing', ['subtotal' => 200000, 'discount' => 0, 'total' => 200000]);
    $response->assertJsonPath('notices.0.code', 'PRICING_LIMIT');
});
