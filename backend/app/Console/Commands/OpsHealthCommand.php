<?php

namespace App\Console\Commands;

use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/** Kiểm tra sức khoẻ vận hành: worker, scheduler, job lỗi tồn đọng, độ dài queue. Exit 1 nếu có vấn đề. */
class OpsHealthCommand extends Command
{
    protected $signature = 'ops:health {--json : In kết quả JSON} {--log : Ghi log error khi có vấn đề}';

    protected $description = 'Kiểm tra worker, scheduler, failed_jobs và độ dài queue';

    public function handle(): int
    {
        $problems = [];
        $workerAge = Heartbeat::age('worker');
        $schedulerAge = Heartbeat::age('scheduler');

        if ($workerAge === null || $workerAge > (int) config('ops.health.worker_max_age')) {
            $problems[] = 'Worker không hoạt động (nhịp cuối: '.($workerAge ?? 'chưa có').'s).';
        }

        if ($schedulerAge === null || $schedulerAge > (int) config('ops.health.scheduler_max_age')) {
            $problems[] = 'Scheduler không hoạt động (nhịp cuối: '.($schedulerAge ?? 'chưa có').'s).';
        }

        $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();

        if ($failed > (int) config('ops.health.failed_jobs_max')) {
            $problems[] = "failed_jobs tồn đọng: {$failed}.";
        }

        $sizes = [];

        foreach (config('ops.queues') as $queue) {
            try {
                $sizes[$queue] = Queue::size($queue);
            } catch (Throwable $e) {
                $sizes[$queue] = null;
                $problems[] = "Không đọc được queue {$queue}: ".$e::class;

                continue;
            }

            if ($sizes[$queue] > (int) config('ops.health.queue_backlog_max')) {
                $problems[] = "Queue {$queue} tồn đọng: {$sizes[$queue]} job.";
            }
        }

        $result = [
            'ok' => $problems === [],
            'worker_age' => $workerAge,
            'scheduler_age' => $schedulerAge,
            'failed_jobs' => $failed,
            'queues' => $sizes,
            'problems' => $problems,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE));
        } else {
            $this->line($result['ok'] ? 'OK' : 'LỖI: '.implode(' ', $problems));
        }

        if (! $result['ok'] && $this->option('log')) {
            Log::error('Cảnh báo vận hành queue/scheduler', $result);
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
