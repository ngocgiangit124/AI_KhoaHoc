<?php

use App\Enums\ConsentType;
use App\Enums\OtpPurpose;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Chapter;
use App\Models\Consent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../T04/helpers.php';

/** Học sinh ĐÃ XÁC THỰC email, có email + SĐT + liên hệ phụ huynh, đã đăng nhập (actingAs). */
function vvT34Student(array $attrs = []): User
{
    return vvActAsStudent(vvT34Create($attrs));
}

function vvT34Create(array $attrs = []): User
{
    return User::factory()->student()->verified()->create(array_merge([
        'name' => 'Nguyễn Minh An',
        'email' => 'an.'.uniqid().'@example.com',
        'parent_email' => 'phuhuynh@example.com',
        'parent_phone' => '0911111111',
        'referral_code_used' => 'REF123',
        'date_of_birth' => '2012-05-01',
        'bio' => 'gioi thieu',
        'avatar_path' => 'x/y.webp',
    ], $attrs));
}

function vvT34Export(string $password = 'password')
{
    return test()->postJson(vvApiUrl('/me/data-export'), ['current_password' => $password], vvWebHeaders());
}

function vvT34Consent(User $user, ConsentType $type = ConsentType::Terms, ?string $version = null, array $extra = []): Consent
{
    return Consent::factory()->create(array_merge([
        'user_id' => $user->id,
        'type' => $type,
        'policy_version' => $version ?? (string) config('privacy.policy_version'),
        'ip' => '203.0.113.5',
        'user_agent' => 'Mozilla/5.0 Pest',
    ], $extra));
}

/** Gửi mã xoá tài khoản, trả mã rõ. */
function vvT34OtpForDelete(VvCapturingOtpSender $otp): string
{
    test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(202);

    return $otp->lastCode();
}

function vvT34Delete(string $code)
{
    return test()->postJson(vvApiUrl('/me/account/delete'), ['code' => $code], vvWebHeaders());
}

/** Dữ liệu đầy đủ cho một học sinh: ghi danh, đơn đã trả, tiến độ, quiz, giỏ, thông báo phụ huynh. @return array<string, mixed> */
function vvT34Seed(User $student, ?Course $course = null): array
{
    $course ??= Course::factory()->published()->paid(500000)->create(['title' => 'Toán 8 nâng cao']);
    $lesson = Lesson::factory()->create(['chapter_id' => Chapter::factory()->for($course)->create()->id, 'title' => 'Bài 1 phương trình']);
    $quiz = Quiz::factory()->forCourse($course)->create(['title' => 'Quiz tổng hợp']);

    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);
    $order = Order::factory()->paid()->create([
        'user_id' => $student->id, 'subtotal_amount' => 500000, 'discount_amount' => 50000, 'total_amount' => 450000,
        'coupon_code' => 'GIAM10', 'payment_method' => 'momo',
    ]);
    DB::table('order_items')->insert([
        'order_id' => $order->id, 'course_id' => $course->id, 'course_title' => 'Toán 8 nâng cao',
        'unit_price' => 500000, 'discount_amount' => 50000, 'final_amount' => 450000,
    ]);
    LessonProgress::factory()->completed()->create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'watched_seconds' => 610]);
    QuizAttempt::factory()->submitted(8, 8.0)->create([
        'user_id' => $student->id, 'quiz_id' => $quiz->id, 'question_ids' => [1, 2], 'answers' => ['1' => 5, '2' => 9],
        'result' => ['1' => ['selected' => 5, 'correct' => 5, 'ok' => true]],
    ]);
    $other = Course::factory()->published()->paid(100000)->create(['title' => 'Khóa trong giỏ']);
    $cart = Cart::query()->where('user_id', $student->id)->first() ?? Cart::factory()->state(['user_id' => $student->id])->create();
    DB::table('cart_items')->insert(['cart_id' => $cart->id, 'course_id' => $other->id, 'created_at' => now()]);
    vvT34Consent($student, ConsentType::Terms);
    vvT34Consent($student, ConsentType::PrivacyPolicy);
    AuditLog::create([
        'actor_id' => null, 'action' => 'parent_notice.sent', 'subject_type' => $student->getMorphClass(),
        'subject_id' => $student->id, 'changes' => ['kind' => 'account_created'],
    ]);

    return ['course' => $course, 'order' => $order, 'lesson' => $lesson, 'quiz' => $quiz, 'cart_course' => $other];
}

/** Đơn pending kèm attempt cho `$status`/`$expiresAt`. */
function vvT34PendingOrder(User $student, string $attemptStatus = 'pending', ?DateTimeInterface $expiresAt = null, bool $withAttempt = true): Order
{
    $order = Order::factory()->create(['user_id' => $student->id]);

    if ($withAttempt) {
        PaymentAttempt::factory()->create([
            'order_id' => $order->id,
            'status' => $attemptStatus,
            'expires_at' => $expiresAt ?? now()->addMinutes(20),
        ]);
    }

    return $order;
}

function vvT34Audits(string $action, ?int $subjectId = null): int
{
    return AuditLog::query()->where('action', $action)->when($subjectId !== null, fn ($q) => $q->where('subject_id', $subjectId))->count();
}

/** Giá trị enum OTP cho tiện. */
function vvT34Purpose(): string
{
    return OtpPurpose::DeleteAccount->value;
}
