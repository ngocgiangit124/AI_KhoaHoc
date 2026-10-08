<?php

use App\Mail\ParentNoticeMail;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\Otp\OtpService;
use App\Services\Privacy\ParentNoticeToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../T04/helpers.php';

/** Số thư ParentNoticeMail đã xếp hàng (bỏ qua OtpMail của đăng ký). */
function vvT29Notices(?string $kind = null): Collection
{
    return Mail::queued(ParentNoticeMail::class)->when($kind !== null, fn ($c) => $c->filter(fn (ParentNoticeMail $m) => $m->kind === $kind));
}

/** Đăng ký rồi xác thực OTP lần đầu (thời điểm duy nhất gửi thư account_created). */
function vvT29RegisterAndVerify(array $override = []): User
{
    $otp = vvFakeOtp();
    vvRegister($override)->assertCreated();

    $user = User::query()->where('email', vvRegisterPayload($override)['email'])->firstOrFail();
    app(OtpService::class)->verifyAccount($user, $otp->lastCode());

    return $user->fresh();
}

/** Học sinh ĐÃ XÁC THỰC có email phụ huynh, đã đăng nhập (actingAs). */
function vvT29Student(array $attrs = []): User
{
    return vvActAsStudent(User::factory()->student()->verified()->create(array_merge([
        'parent_email' => 'phuhuynh@example.com',
        'parent_phone' => '0911111111',
    ], $attrs)));
}

function vvT29Put(array $payload)
{
    return test()->putJson(vvApiUrl('/me/parent-contact'), array_merge(['current_password' => 'password'], $payload), vvWebHeaders());
}

function vvT29UnsubUrl(string $query = ''): string
{
    return vvApiUrl('/parent-notices/unsubscribe').$query;
}

/** Đơn có tiền (hoặc 0đ) ở trạng thái pending kèm 1 dòng, sẵn sàng cho markPaid. */
function vvT29PendingOrder(User $student, int $total = 100000, string $title = 'Toán 9 nâng cao'): Order
{
    $course = Course::factory()->published()->paid(max($total, 1000))->create(['title' => $title]);

    $order = Order::factory()->create([
        'user_id' => $student->id,
        'subtotal_amount' => $total,
        'total_amount' => $total,
    ]);

    DB::table('order_items')->insert([
        'order_id' => $order->id,
        'course_id' => $course->id,
        'course_title' => $title,
        'unit_price' => $total,
        'discount_amount' => 0,
        'final_amount' => $total,
    ]);

    return $order;
}

function vvT29Token(User $user): string
{
    return (string) ParentNoticeToken::make($user->fresh());
}
