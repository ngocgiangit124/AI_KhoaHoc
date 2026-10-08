<?php

namespace App\Jobs;

use App\Services\Privacy\AccountDeletionFinalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pha B của xoá tài khoản (T34.4). Idempotent, mỗi học sinh tối đa một job đang chờ (khoá duy nhất nhả khi bắt đầu xử lý,
 * để job tự phát lại chính nó được). Chỉ mang `user_id` và số vòng (không PII).
 *
 * Đơn pending còn link thanh toán sống bị bỏ qua: job tự phát lại sau `RETRY_DELAY_MINUTES`, tối đa `MAX_ROUNDS` vòng;
 * hết vòng thì job huỷ đơn quá hạn 12h (T20) phải quét cả đơn của user đã ẩn danh.
 */
class FinalizeAccountDeletionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ROUNDS = 6;

    public const RETRY_DELAY_MINUTES = 15;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /** Lớn hơn tổng backoff (1.270 s). */
    public int $uniqueFor = 1800;

    public function __construct(public readonly int $userId, public readonly int $round = 1)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'finalize-account-deletion:'.$this->userId;
    }

    public function handle(AccountDeletionFinalizer $finalizer): void
    {
        $complete = $finalizer->finalize($this->userId);

        if (! $complete && $this->round < self::MAX_ROUNDS) {
            self::dispatch($this->userId, $this->round + 1)->delay(now()->addMinutes(self::RETRY_DELAY_MINUTES));
        }
    }

    public function failed(Throwable $e): void
    {
        // Chỉ id và tên class: message của exception có thể chứa dữ liệu cá nhân.
        Log::channel('privacy')->error('privacy.account_deletion_failed', ['user_id' => $this->userId, 'round' => $this->round, 'exception' => $e::class]);
    }
}
