<?php

namespace App\Console\Commands;

use App\Models\QuizAttempt;
use App\Services\Quiz\QuizAttemptService;
use Illuminate\Console\Command;

/**
 * Tự nộp lượt quiz đã quá hạn (US-007: học sinh đóng trình duyệt vẫn bị chốt theo giờ server). Idempotent:
 * lượt đã nộp bởi người dùng/tab khác được bỏ qua trong service (khoá dòng + submitted_at IS NULL).
 */
class QuizzesAutoSubmitExpiredCommand extends Command
{
    protected $signature = 'quizzes:auto-submit-expired {--limit=500 : Số lượt tối đa mỗi lần chạy}';

    protected $description = 'Tự nộp các lượt làm quiz đã quá hạn (expires_at + ân hạn)';

    public function handle(QuizAttemptService $service): int
    {
        $cutoff = now()->subSeconds((int) config('quiz.submit_grace_seconds'));
        $done = 0;

        QuizAttempt::query()
            ->whereNull('submitted_at')->whereNotNull('expires_at')->where('expires_at', '<', $cutoff)
            ->orderBy('expires_at')->limit((int) $this->option('limit'))
            ->get()
            ->each(function (QuizAttempt $attempt) use ($service, &$done): void {
                try {
                    $service->finalizeIfExpired($attempt);
                    $done++;
                } catch (\Throwable $e) {
                    // Cô lập lỗi từng lượt: 1 lượt hỏng không được chặn các lượt sau (không log đáp án).
                    $this->error("Lượt {$attempt->getKey()} lỗi: ".$e::class);
                    report($e);
                }
            });

        $this->info("Đã xử lý {$done} lượt quá hạn.");

        return self::SUCCESS;
    }
}
