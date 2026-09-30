<?php

use App\Enums\CourseStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Giỏ hàng (T16, US-004, api-contract §2.3).
 */
function vvCartUrl(string $path = ''): string
{
    return 'http://'.config('app.api_host').'/api/v1/cart'.$path;
}

function vvCartHeaders(): array
{
    return ['Origin' => config('app.frontend_url')];
}

function vvPaidCourse(int $price = 199000, array $state = []): Course
{
    return Course::factory()->published()->create(array_merge(['price' => $price], $state));
}

function vvAddToCart(User $student, Course $course)
{
    return test()->actingAs($student)->postJson(vvCartUrl('/items'), ['course_id' => $course->id], vvCartHeaders());
}

test('gio trong: tra cau truc rong, khong tao gio (AC5)', function () {
    $student = User::factory()->student()->create();

    $response = test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders());

    $response->assertOk();
    $response->assertExactJson([
        'items' => [],
        'count' => 0,
        'coupon' => null,
        'pricing' => ['subtotal' => 0, 'discount' => 0, 'total' => 0],
        'notices' => [],
    ]);
    expect(Cart::query()->count())->toBe(0);
});

test('them khoa co phi vao gio: 201 kem gio va tong tien (AC1)', function () {
    $student = User::factory()->student()->create();
    $course = vvPaidCourse(199000);

    $response = vvAddToCart($student, $course);

    $response->assertCreated();
    $response->assertJsonPath('count', 1);
    $response->assertJsonPath('items.0.course_id', $course->id);
    $response->assertJsonPath('items.0.price', 199000);
    $response->assertJsonPath('items.0.unavailable', false);
    $response->assertJsonPath('items.0.final_amount', 199000);
    $response->assertJsonPath('pricing', ['subtotal' => 199000, 'discount' => 0, 'total' => 199000]);
    expect(CartItem::query()->count())->toBe(1);
});

test('them trung tra 409 ALREADY_IN_CART va khong tao dong trung (AC2, BR2)', function () {
    $student = User::factory()->student()->create();
    $course = vvPaidCourse();

    vvAddToCart($student, $course)->assertCreated();
    $second = vvAddToCart($student, $course);

    $second->assertStatus(409);
    $second->assertJson(['code' => 'ALREADY_IN_CART']);
    expect(CartItem::query()->count())->toBe(1);
});

test('unique (cart_id, course_id) o tang DB chan dong trung (race 2 tab)', function () {
    $cart = Cart::factory()->create();
    $course = vvPaidCourse();
    CartItem::factory()->create(['cart_id' => $cart->id, 'course_id' => $course->id]);

    expect(fn () => CartItem::factory()->create(['cart_id' => $cart->id, 'course_id' => $course->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('khoa da so huu (enrollment active) tra 409 ALREADY_OWNED, ca khi goi API truc tiep (AC3, BR3)', function () {
    $student = User::factory()->student()->create();
    $course = vvPaidCourse();
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    $response = vvAddToCart($student, $course);

    $response->assertStatus(409);
    $response->assertJson(['code' => 'ALREADY_OWNED']);
    expect(CartItem::query()->count())->toBe(0);
});

test('khoa dang cho duyet (pending_approval) tra 409 ENROLLMENT_PENDING', function () {
    $student = User::factory()->student()->create();
    $course = vvPaidCourse();
    Enrollment::factory()->pendingApproval()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    vvAddToCart($student, $course)->assertStatus(409)->assertJson(['code' => 'ENROLLMENT_PENDING']);
});

test('enrollment cu bi tu choi/thu hoi khong chan mua lai', function () {
    $student = User::factory()->student()->create();
    $course = vvPaidCourse();
    Enrollment::factory()->rejected()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    vvAddToCart($student, $course)->assertCreated();
});

test('khoa mien phi, chua publish, da xoa, khong ton tai: cung 422 VALIDATION_ERROR (BR6)', function () {
    $student = User::factory()->student()->create();

    $free = Course::factory()->published()->free()->create();
    $draft = Course::factory()->create(['price' => 199000]);
    $unpublished = Course::factory()->unpublished()->create(['price' => 199000]);
    $deleted = vvPaidCourse();
    $deleted->delete();

    foreach ([$free->id, $draft->id, $unpublished->id, $deleted->id, 99999999] as $id) {
        $response = test()->actingAs($student)->postJson(vvCartUrl('/items'), ['course_id' => $id], vvCartHeaders());

        $response->assertStatus(422);
        $response->assertJson(['code' => 'VALIDATION_ERROR']);
    }

    expect(CartItem::query()->count())->toBe(0);
});

test('course_id thieu hoac sai kieu tra 422, khong 500', function () {
    $student = User::factory()->student()->create();

    test()->actingAs($student)->postJson(vvCartUrl('/items'), [], vvCartHeaders())->assertStatus(422);
    test()->actingAs($student)->postJson(vvCartUrl('/items'), ['course_id' => 'abc'], vvCartHeaders())->assertStatus(422);
    test()->actingAs($student)->postJson(vvCartUrl('/items'), ['course_id' => [1]], vvCartHeaders())->assertStatus(422);
});

test('xoa khoa khoi gio: tong duoc tinh lai (AC4)', function () {
    $student = User::factory()->student()->create();
    $a = vvPaidCourse(199000);
    $b = vvPaidCourse(299000);
    vvAddToCart($student, $a);
    vvAddToCart($student, $b);

    $response = test()->actingAs($student)->deleteJson(vvCartUrl("/items/{$a->id}"), [], vvCartHeaders());

    $response->assertOk();
    $response->assertJsonPath('count', 1);
    $response->assertJsonPath('items.0.course_id', $b->id);
    $response->assertJsonPath('pricing.total', 299000);
});

test('xoa khoa khong co trong gio la idempotent', function () {
    $student = User::factory()->student()->create();
    $a = vvPaidCourse();
    vvAddToCart($student, $a);
    $other = vvPaidCourse();

    $response = test()->actingAs($student)->deleteJson(vvCartUrl("/items/{$other->id}"), [], vvCartHeaders());

    $response->assertOk();
    $response->assertJsonPath('count', 1);
});

test('khoa da go publish hoac xoa mem van hien trong gio voi co unavailable va xoa duoc', function () {
    $student = User::factory()->student()->create();
    $gone = vvPaidCourse(199000);
    $ok = vvPaidCourse(299000);
    vvAddToCart($student, $gone);
    vvAddToCart($student, $ok);

    $gone->delete();

    $response = test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders());

    $response->assertOk();
    $response->assertJsonPath('items.0.unavailable', true);
    $response->assertJsonPath('items.0.final_amount', null);
    $response->assertJsonPath('items.1.unavailable', false);
    // Khoa khong kha dung khong tinh vao tong.
    $response->assertJsonPath('pricing', ['subtotal' => 299000, 'discount' => 0, 'total' => 299000]);
    $response->assertJsonPath('notices.0.code', 'COURSE_UNAVAILABLE');
    $response->assertJsonPath('notices.0.course_id', $gone->id);

    // Xoa duoc khoa da xoa mem (route binding withTrashed).
    $delete = test()->actingAs($student)->deleteJson(vvCartUrl("/items/{$gone->id}"), [], vvCartHeaders());
    $delete->assertOk();
    $delete->assertJsonPath('count', 1);
});

test('khoa unpublish hoac doi thanh mien phi sau khi vao gio bi danh dau unavailable', function () {
    $student = User::factory()->student()->create();
    $unpub = vvPaidCourse();
    $nowFree = vvPaidCourse();
    vvAddToCart($student, $unpub);
    vvAddToCart($student, $nowFree);

    $unpub->forceFill(['status' => CourseStatus::Unpublished])->save();
    $nowFree->forceFill(['price' => 0])->save();

    $response = test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders());

    $response->assertJsonPath('items.0.unavailable', true);
    $response->assertJsonPath('items.1.unavailable', true);
    $response->assertJsonPath('pricing.total', 0);
});

test('khoa da so huu sau khi vao gio bi danh dau unavailable', function () {
    $student = User::factory()->student()->create();
    $course = vvPaidCourse();
    vvAddToCart($student, $course);
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    $response = test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders());

    $response->assertJsonPath('items.0.unavailable', true);
    $response->assertJsonPath('notices.0.code', 'COURSE_ALREADY_OWNED');
    $response->assertJsonPath('pricing.total', 0);
});

test('gio hang la cua rieng tung hoc sinh (IDOR): khong thay/xoa duoc gio nguoi khac', function () {
    $alice = User::factory()->student()->create();
    $bob = User::factory()->student()->create();
    $course = vvPaidCourse();
    vvAddToCart($alice, $course);

    // Bob khong thay gio cua Alice.
    test()->actingAs($bob)->getJson(vvCartUrl(), vvCartHeaders())->assertJsonPath('count', 0);

    // Bob "xoa" cung course_id chi tac dong gio cua Bob.
    test()->actingAs($bob)->deleteJson(vvCartUrl("/items/{$course->id}"), [], vvCartHeaders())->assertOk();
    expect(CartItem::query()->count())->toBe(1);

    // Body co user_id/cart_id la thong tin la, bi bo qua.
    $aliceCart = Cart::query()->where('user_id', $alice->id)->firstOrFail();
    test()->actingAs($bob)->postJson(vvCartUrl('/items'), [
        'course_id' => $course->id, 'user_id' => $alice->id, 'cart_id' => $aliceCart->id,
    ], vvCartHeaders())->assertCreated();

    expect(CartItem::query()->where('cart_id', $aliceCart->id)->count())->toBe(1);
    expect(Cart::query()->where('user_id', $bob->id)->firstOrFail()->items()->count())->toBe(1);
});

test('gio > 20 khoa tinh dung va so truy van khong tang theo so khoa', function () {
    $student = User::factory()->student()->create();
    $cart = Cart::factory()->create(['user_id' => $student->id]);
    $total = 0;

    for ($i = 0; $i < 25; $i++) {
        $course = vvPaidCourse(100000 + $i);
        $total += 100000 + $i;
        CartItem::factory()->create(['cart_id' => $cart->id, 'course_id' => $course->id]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders());

    $response->assertOk();
    $response->assertJsonPath('count', 25);
    $response->assertJsonPath('pricing.total', $total);

    // Toan bo request (auth, session, gio, khoa, enrollment) — bat N+1: nhieu hon
    // ~12 truy van cho 25 khoa la dau hieu lazy load.
    expect(count(DB::getQueryLog()))->toBeLessThan(15);
});

test('/auth/me tra cart_count that va viewer-state tra in_cart', function () {
    $student = User::factory()->student()->create();
    $course = vvPaidCourse();
    $other = vvPaidCourse();

    $host = 'http://'.config('app.api_host').'/api/v1';

    test()->actingAs($student)->getJson("{$host}/auth/me", vvCartHeaders())->assertJsonPath('cart_count', 0);
    test()->actingAs($student)->getJson("{$host}/courses/{$course->slug}/viewer-state", vvCartHeaders())
        ->assertJsonPath('viewer_state', 'can_buy');

    vvAddToCart($student, $course);

    test()->actingAs($student)->getJson("{$host}/auth/me", vvCartHeaders())->assertJsonPath('cart_count', 1);
    test()->actingAs($student)->getJson("{$host}/courses/{$course->slug}/viewer-state", vvCartHeaders())
        ->assertJsonPath('viewer_state', 'in_cart');
    test()->actingAs($student)->getJson("{$host}/courses/{$other->slug}/viewer-state", vvCartHeaders())
        ->assertJsonPath('viewer_state', 'can_buy');
});

test('hoc sinh chua xac thuc OTP va chua co dong y phu huynh van dung duoc gio (khong account.verified/parent.consent)', function () {
    $student = User::factory()->student()->minor()->create([
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);

    test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders())->assertOk();
    vvAddToCart($student, vvPaidCourse())->assertCreated();
});

test('khach chua dang nhap 401, giao vien 403 (BR1, AC6)', function () {
    $course = vvPaidCourse();

    test()->getJson(vvCartUrl(), vvCartHeaders())->assertStatus(401);
    test()->postJson(vvCartUrl('/items'), ['course_id' => $course->id], vvCartHeaders())->assertStatus(401);
    test()->putJson(vvCartUrl('/coupon'), ['code' => 'ABC'], vvCartHeaders())->assertStatus(401);
    test()->deleteJson(vvCartUrl('/coupon'), [], vvCartHeaders())->assertStatus(401);

    $teacher = User::factory()->teacher()->create();
    test()->actingAs($teacher)->getJson(vvCartUrl(), vvCartHeaders())->assertStatus(403);
    test()->actingAs($teacher)->postJson(vvCartUrl('/items'), ['course_id' => $course->id], vvCartHeaders())->assertStatus(403);
});

test('tai khoan bi khoa khong dung duoc gio', function () {
    $student = User::factory()->student()->locked()->create();

    test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders())->assertStatus(403);
});

test('response gio co Cache-Control no-store (S16)', function () {
    $student = User::factory()->student()->create();

    $response = test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders());

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('response gio khong lo id gio/user_id/used_count/max_uses (PII/roi ri)', function () {
    $student = User::factory()->student()->create();
    $course = vvPaidCourse();
    vvAddToCart($student, $course);
    $coupon = Coupon::factory()->create(['code' => 'GIAM10', 'max_uses' => 50, 'used_count' => 3]);
    test()->actingAs($student)->putJson(vvCartUrl('/coupon'), ['code' => 'giam10'], vvCartHeaders())->assertOk();

    $json = test()->actingAs($student)->getJson(vvCartUrl(), vvCartHeaders())->assertOk()->getContent();

    foreach (['used_count', 'max_uses', 'user_id', 'cart_id', 'email', 'created_by', (string) $student->email] as $needle) {
        expect($json)->not->toContain($needle);
    }
    expect($coupon->code)->toBe('GIAM10');
});

test('xoa hoc sinh cascade xoa gio (FK)', function () {
    $student = User::factory()->student()->create();
    vvAddToCart($student, vvPaidCourse());

    $student->delete();

    expect(Cart::query()->count())->toBe(0);
    expect(CartItem::query()->count())->toBe(0);
});
