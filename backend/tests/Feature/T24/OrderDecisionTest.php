<?php

use App\Models\Order;
use App\Services\Orders\ManualOrderService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => config(['orders.manual.approval_window_days' => 30]));

/** @return array<string, mixed> */
function vvT24Decide(Order $o): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $d = app(ManualOrderService::class)->decide($o->fresh());
    $queries = count(DB::getQueryLog()); // `fresh()` là 1 truy vấn; decide() thuần không thêm truy vấn nào
    DB::disableQueryLog();
    expect($queries)->toBe(1);

    return $d;
}

test('decide(): ma tran quyet dinh + ma 409 cho duyet thuong / duyet muon / huy, khong I/O', function () {
    $pending = vvT24Order();
    $cancelledIn = vvT24Order(null, ['cancelled_at' => now()->subDays(29), 'status_reason' => 'expired'], 'cancelled');
    $cancelledOut = vvT24Order(null, ['cancelled_at' => now()->subDays(31), 'status_reason' => 'expired'], 'cancelled');
    $deleted = vvT24Order(null, ['cancelled_at' => now()->subDay(), 'status_reason' => 'account_deleted'], 'cancelled');
    $paid = vvT24Order(null, [], 'paid');
    $momoPending = vvT24Order(null, ['payment_method' => 'momo'], 'pending');
    $failed = vvT24Order(null, ['status' => 'failed'], 'pending');

    $pick = fn (Order $o) => collect(vvT24Decide($o))->only(['can_approve', 'can_approve_late', 'can_cancel', 'late_denied', 'approve_error', 'late_error', 'cancel_error'])->all();

    expect($pick($pending))->toBe(['can_approve' => true, 'can_approve_late' => false, 'can_cancel' => true, 'late_denied' => 'not_cancelled', 'approve_error' => null, 'late_error' => 'ORDER_STATUS_CHANGED', 'cancel_error' => null])
        ->and($pick($cancelledIn))->toBe(['can_approve' => false, 'can_approve_late' => true, 'can_cancel' => false, 'late_denied' => null, 'approve_error' => 'ORDER_STATUS_CHANGED', 'late_error' => null, 'cancel_error' => 'ALREADY_PROCESSED'])
        ->and($pick($cancelledOut))->toMatchArray(['can_approve_late' => false, 'late_denied' => 'window_expired', 'late_error' => 'ORDER_APPROVAL_WINDOW_PASSED'])
        ->and($pick($deleted))->toMatchArray(['can_approve_late' => false, 'late_denied' => 'account_deleted', 'late_error' => 'ORDER_APPROVAL_WINDOW_PASSED'])
        ->and($pick($paid))->toMatchArray(['can_approve' => false, 'late_denied' => 'not_cancelled', 'approve_error' => 'ALREADY_PROCESSED', 'late_error' => 'ALREADY_PROCESSED', 'cancel_error' => 'ORDER_STATUS_CHANGED'])
        ->and($pick($momoPending))->toBe(['can_approve' => false, 'can_approve_late' => false, 'can_cancel' => false, 'late_denied' => 'not_manual', 'approve_error' => 'ORDER_NOT_MANUAL', 'late_error' => 'ORDER_NOT_MANUAL', 'cancel_error' => 'ORDER_NOT_MANUAL'])
        ->and($pick($failed))->toMatchArray(['approve_error' => 'ORDER_STATUS_CHANGED', 'late_error' => 'ORDER_STATUS_CHANGED']);

    config(['orders.manual.approval_window_days' => 0]);
    expect($pick($cancelledIn))->toMatchArray(['can_approve_late' => false, 'late_denied' => 'disabled', 'late_error' => 'ORDER_APPROVAL_WINDOW_PASSED'])
        ->and(vvT24Decide($cancelledIn)['approval_window_until'])->toBeNull();
});

test('approvalState() = decide() + canh bao: bon gia tri quyet dinh trung nhau', function () {
    $o = vvT24Order(null, ['cancelled_at' => now()->subDays(2), 'status_reason' => 'expired'], 'cancelled');
    $svc = app(ManualOrderService::class);
    $d = $svc->decide($o->fresh());
    $a = $svc->approvalState($o->fresh());

    expect([$a['can_approve'], $a['can_approve_late'], $a['can_cancel']])->toBe([$d['can_approve'], $d['can_approve_late'], $d['can_cancel']])
        ->and($a['approval_window_until']->equalTo($d['approval_window_until']))->toBeTrue()
        ->and(array_keys($a))->toBe(['can_approve', 'can_approve_late', 'can_cancel', 'approval_window_until', 'warnings', 'late_approval_warnings']);
});
