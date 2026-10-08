<?php

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderFulfillmentService;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => Mail::fake());

test('(l) markPaid don co tien -> 1 thu order_paid; IPN trung khong them thu', function () {
    $student = User::factory()->student()->create(['name' => 'Trần Thị Bích Ngọc', 'parent_email' => 'ph@example.com']);
    $order = vvT29PendingOrder($student, 250000, 'Hình học 8');

    app(OrderFulfillmentService::class)->markPaid($order, 'ipn', 'REF-1');

    $notices = vvT29Notices();
    expect($notices)->toHaveCount(1);
    $mail = $notices->first();
    expect($mail->kind)->toBe('order_paid')->and($mail->hasTo('ph@example.com'))->toBeTrue()
        ->and($mail->orderCode)->toBe($order->code)->and($mail->totalAmount)->toBe(250000)
        ->and($mail->courseTitles)->toBe(['Hình học 8'])
        ->and($mail->maskedStudentName)->toBe('Trần Thị Bích N**');

    $html = $mail->render();
    expect($html)->toContain($order->code)->toContain('250.000')->toContain('Hình học 8')->not->toContain('Ngọc');

    app(OrderFulfillmentService::class)->markPaid($order->fresh(), 'ipn', 'REF-1');
    app(OrderFulfillmentService::class)->markPaid(Order::find($order->id), 'query');
    expect(vvT29Notices())->toHaveCount(1);
});

test('(l) don 0d -> khong thu', function () {
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com']);
    $order = vvT29PendingOrder($student, 0);

    app(OrderFulfillmentService::class)->markPaid($order, 'checkout');

    expect($order->fresh()->status->value)->toBe('paid')->and(vvT29Notices())->toHaveCount(0);
});

test('(l) khong co email phu huynh / da huy nhan / cong tat -> khong thu, markPaid van thanh cong', function () {
    $none = User::factory()->student()->create();
    $optedOut = User::factory()->student()->create(['parent_email' => 'ph@example.com', 'parent_notice_opt_out_at' => now()]);
    $flagOff = User::factory()->student()->create(['parent_email' => 'ph3@example.com']);

    foreach ([$none, $optedOut] as $student) {
        $order = vvT29PendingOrder($student);
        app(OrderFulfillmentService::class)->markPaid($order, 'ipn');
        expect($order->fresh()->status->value)->toBe('paid');
    }

    config(['features.parent_notices' => false]);
    $order = vvT29PendingOrder($flagOff);
    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    expect($order->fresh()->status->value)->toBe('paid')->and(vvT29Notices())->toHaveCount(0);
});

test('(l) loi khi xep thu khong lam hong viec cap quyen/chuyen paid', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    $student = User::factory()->student()->create(['parent_email' => 'ph@example.com']);
    $order = vvT29PendingOrder($student);

    app(OrderFulfillmentService::class)->markPaid($order, 'ipn');

    expect($order->fresh()->status->value)->toBe('paid');
});
