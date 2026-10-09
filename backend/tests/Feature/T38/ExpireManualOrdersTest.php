<?php

use App\Exceptions\DomainException;
use App\Mail\ManualOrderCancelledMail;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use App\Services\Courses\CourseService;
use App\Services\Orders\ManualOrderNotifier;
use App\Services\Orders\ManualOrderService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
    $this->student = User::factory()->student()->verified()->create(['email' => 'hs@example.com']);
});

test('(l) đơn manual quá expires_at -> cancelled/expired (actor system), 1 thư; chạy lại không đổi gì, không thư lần 2', function () {
    $order = vvMoOrder($this->student, null, [], 'expiredManual');
    $cart = vvMoCart($this->student, [vvMoCourse()]);

    Artisan::call('orders:expire-manual');

    $order->refresh();
    expect($order->status->value)->toBe('cancelled')->and($order->status_reason)->toBe('expired')->and($order->cancelled_at)->not->toBeNull()
        ->and($cart->items()->count())->toBe(1);
    $log = DB::table('order_status_logs')->where('order_id', $order->id)->orderByDesc('id')->first();
    expect($log->actor_type)->toBe('system')->and($log->actor_id)->toBeNull()->and($log->reason)->toBe('expired');
    Mail::assertQueued(ManualOrderCancelledMail::class, 1);
    Mail::assertQueued(ManualOrderCancelledMail::class, fn ($m) => $m->hasTo('hs@example.com') && $m->variant === 'expired' && $m->orderCode === $order->code);

    $before = DB::table('order_status_logs')->count();
    Artisan::call('orders:expire-manual');
    expect(DB::table('order_status_logs')->count())->toBe($before);
    Mail::assertQueued(ManualOrderCancelledMail::class, 1);
});

test('(l) không đụng: đơn manual chưa hết hạn, đơn đã paid, đơn MoMo (fake) pending quá hạn', function () {
    $fresh = vvMoOrder($this->student);
    $paid = vvMoOrder(User::factory()->student()->verified()->create(), null, ['status' => 'paid', 'paid_at' => now(), 'expires_at' => now()->subHour()]);
    $momo = Order::factory()->create(['user_id' => User::factory()->student()->verified()->create()->id, 'expires_at' => now()->subHours(5)]);

    Artisan::call('orders:expire-manual');

    expect($fresh->fresh()->status->value)->toBe('pending')->and($paid->fresh()->status->value)->toBe('paid')->and($momo->fresh()->status->value)->toBe('pending');
    Mail::assertNothingQueued();
});

test('(l) tài khoản đã ẩn danh: đơn vẫn bị huỷ nhưng không gửi thư (AC27); HS không có email xác thực cũng không có thư', function () {
    $anon = User::factory()->student()->verified()->create(['email' => 'x@example.com']);
    $a = vvMoOrder($anon, null, [], 'expiredManual');
    DB::table('users')->where('id', $anon->id)->update(['anonymized_at' => now(), 'email' => null]);

    $noEmail = User::factory()->student()->create(['email' => null, 'phone' => '09'.random_int(10000000, 99999999)]);
    $b = vvMoOrder($noEmail, null, [], 'expiredManual');

    expect(app(ManualOrderService::class)->expireDue())->toBe(2);

    expect($a->fresh()->status_reason)->toBe('expired')->and($b->fresh()->status_reason)->toBe('expired');
    Mail::assertNothingQueued();
});

test('(l) --limit: mỗi lần chỉ xử lý tối đa N đơn, theo expires_at tăng dần', function () {
    $orders = [];
    foreach (range(1, 3) as $i) {
        $orders[] = vvMoOrder(User::factory()->student()->verified()->create(), null, ['expires_at' => now()->subHours(10 - $i)], 'manual');
    }

    Artisan::call('orders:expire-manual', ['--limit' => 2]);

    expect($orders[0]->fresh()->status->value)->toBe('cancelled')->and($orders[1]->fresh()->status->value)->toBe('cancelled')->and($orders[2]->fresh()->status->value)->toBe('pending');
});

test('(l) đơn vừa được duyệt (paid) giữa lúc lấy danh sách và lúc khoá không bị huỷ (kiểm lại dưới khoá)', function () {
    $order = vvMoOrder($this->student, null, [], 'expiredManual');

    // mô phỏng đua: đơn đổi sang paid sau khi job đã đọc id, trước khi khoá
    $service = new class(app(OrderStateMachine::class), app(ManualOrderNotifier::class), $order) extends ManualOrderService
    {
        public function __construct(OrderStateMachine $s, ManualOrderNotifier $n, private Order $o)
        {
            parent::__construct($s, $n);
        }

        public function expireDue(int $limit = 500): int
        {
            DB::table('orders')->where('id', $this->o->id)->update(['status' => 'paid', 'paid_at' => now()]);

            return parent::expireDue($limit); // danh sách rỗng ở đây; kiểm tiếp bằng gọi trực tiếp lõi qua reflection
        }
    };
    expect($service->expireDue())->toBe(0);

    $m = new ReflectionMethod(ManualOrderService::class, 'cancel');
    expect(fn () => $m->invoke($service, $order->id, 'expired', 'system', null, true, 'expired'))->toThrow(DomainException::class);
    expect($order->fresh()->status->value)->toBe('paid');
    Mail::assertNothingQueued();
});

test('lệnh được lên lịch mỗi 15 phút, không chồng lệnh, onOneServer', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'orders:expire-manual'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});

test('Course::delete: khóa nằm trong đơn manual pending còn hạn (72 giờ) -> 409 COURSE_HAS_PENDING_ORDERS; huỷ xong thì xoá được', function () {
    $course = vvMoCourse();
    $order = vvMoOrder($this->student, $course);

    expect(fn () => app(CourseService::class)->delete($course))
        ->toThrow(DomainException::class);
    expect($course->fresh())->not->toBeNull();

    app(ManualOrderService::class)->cancelByStudent($order, $this->student);
    app(CourseService::class)->delete($course);
    expect(Course::withTrashed()->find($course->id)->trashed())->toBeTrue();
});
