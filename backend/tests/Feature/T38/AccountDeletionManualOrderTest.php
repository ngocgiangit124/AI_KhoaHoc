<?php

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\ManualOrderService;
use App\Services\Privacy\AccountDeletionFinalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T34/helpers.php';

beforeEach(function () {
    vvMoConfig();
    Mail::fake();
});

test('BR16: đơn manual pending còn hạn chặn gửi OTP xoá tài khoản: 409 + retry_after_at = expires_at (+07:00) + pending_order_code + gợi ý huỷ đơn', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $order = vvMoOrder($me);

    $r = test()->postJson(vvApiUrl('/me/account/delete/otp'), [], vvWebHeaders())->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_HAS_PENDING_PAYMENT');

    expect($r->json('errors.pending_order_code'))->toBe($order->code)
        ->and($r->json('errors.retry_after_at'))->toEndWith('+07:00')
        ->and(Carbon::parse($r->json('errors.retry_after_at'))->equalTo($order->expires_at->copy()->startOfSecond()))->toBeTrue()
        ->and($r->json('message'))->toContain($order->code)->toContain('Đơn của tôi')
        ->and($otp->sent)->toBe([])
        ->and($me->fresh()->anonymized_at)->toBeNull();
});

test('BR16: xác nhận xoá cũng bị chặn khi có đơn manual pending, KHÔNG tiêu mã; huỷ đơn rồi xoá được', function () {
    $otp = vvFakeOtp();
    $me = vvT34Student();
    $code = vvT34OtpForDelete($otp);
    $order = vvMoOrder($me);

    vvT34Delete($code)->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_HAS_PENDING_PAYMENT')->assertJsonPath('errors.pending_order_code', $order->code);
    expect($me->fresh()->anonymized_at)->toBeNull();

    app(ManualOrderService::class)->cancelByStudent($order, $me);

    vvT34Delete($code)->assertOk();
    expect($me->fresh()->anonymized_at)->not->toBeNull();
});

test('đơn manual quá hạn (job chưa chạy) không chặn xoá; pha B giữ đơn, không phát lại job, orders:expire-manual dọn không gửi thư', function () {
    $me = User::factory()->student()->verified()->create();
    $order = vvMoOrder($me, null, [], 'expiredManual');
    DB::table('users')->where('id', $me->id)->update(['anonymized_at' => now(), 'name' => 'Tài khoản đã xoá', 'email' => null, 'phone' => null]);

    $complete = app(AccountDeletionFinalizer::class)->finalize($me->id);

    expect($complete)->toBeTrue()->and($order->fresh()->status->value)->toBe('pending');

    expect(app(ManualOrderService::class)->expireDue())->toBe(1);
    expect($order->fresh()->status_reason)->toBe('expired');
    Mail::assertNothingQueued();
});

test('pha B: đơn manual còn hạn do đua với pha A được GIỮ (không phải account_deleted); đơn MoMo không link vẫn bị huỷ như cũ', function () {
    $me = User::factory()->student()->verified()->create();
    $order = vvMoOrder($me);
    DB::table('users')->where('id', $me->id)->update(['anonymized_at' => now(), 'email' => null]);

    expect(app(AccountDeletionFinalizer::class)->finalize($me->id))->toBeTrue()
        ->and($order->fresh()->status->value)->toBe('pending');

    $other = User::factory()->student()->verified()->create();
    $momo = Order::factory()->create(['user_id' => $other->id]);
    DB::table('users')->where('id', $other->id)->update(['anonymized_at' => now(), 'email' => null]);
    app(AccountDeletionFinalizer::class)->finalize($other->id);
    expect($momo->fresh()->status_reason)->toBe('account_deleted');
});

test('S1: pha B xoá customer_note trên MỌI đơn của HS (paid/cancelled/pending), đơn và số tiền giữ nguyên; đơn người khác không đổi', function () {
    $me = User::factory()->student()->verified()->create();
    $paid = vvMoOrder($me, null, ['status' => 'paid', 'paid_at' => now(), 'customer_note' => 'Zalo của mẹ: 0911222333'], 'manual');
    $cancelled = vvMoOrder($me, null, ['status' => 'cancelled', 'status_reason' => 'user_cancelled', 'cancelled_at' => now(), 'customer_note' => 'ghi chu 2', 'created_at' => now()->subDay()], 'manual');
    $pending = vvMoOrder($me, null, ['customer_note' => 'ghi chu 3']);
    $other = vvMoOrder(User::factory()->student()->verified()->create(), null, ['customer_note' => 'cua nguoi khac']);
    DB::table('users')->where('id', $me->id)->update(['anonymized_at' => now(), 'email' => null]);

    app(AccountDeletionFinalizer::class)->finalize($me->id);
    app(AccountDeletionFinalizer::class)->finalize($me->id); // idempotent

    foreach ([$paid, $cancelled, $pending] as $o) {
        expect($o->fresh()->customer_note)->toBeNull();
    }
    expect($paid->fresh()->status->value)->toBe('paid')->and($paid->fresh()->total_amount)->toBe(100000)
        ->and($pending->fresh()->status->value)->toBe('pending')
        ->and($other->fresh()->customer_note)->toBe('cua nguoi khac');
});

test('S3: bản xuất dữ liệu có customer_note của đơn, không có ghi chú nội bộ (order_notes)', function () {
    $me = vvT34Student();
    $order = vvMoOrder($me, null, ['customer_note' => 'Gọi sau 18h']);
    DB::table('order_notes')->insert(['order_id' => $order->id, 'author_id' => User::factory()->admin()->create()->id, 'body' => 'GHICHUNOIBO', 'created_at' => now()]);

    $r = vvT34Export()->assertOk();
    $data = json_decode($r->getContent(), true, flags: JSON_THROW_ON_ERROR);
    expect($data['orders'][0]['customer_note'])->toBe('Gọi sau 18h')->and($r->getContent())->not->toContain('GHICHUNOIBO');
});
