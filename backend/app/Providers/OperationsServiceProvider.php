<?php

namespace App\Providers;

use App\Console\Commands\OpsHealthCommand;
use App\Console\Commands\OtpPruneCommand;
use App\Support\Heartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * T26 — lịch chạy tập trung + giám sát queue. Mọi lịch dùng `withoutOverlapping()->onOneServer()` (lock trong
 * cache Redis dùng chung, nên chạy nhiều máy cũng chỉ 1 bản).
 */
class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->isProduction() && ! (is_string($t = config('internal.ssr_token')) && $t !== '')) {
            Log::warning('INTERNAL_API_TOKEN rỗng: throttle catalog dùng chung 1 bucket theo IP kết nối (SSR sẽ dễ bị 429).');
        }

        $this->commands([OpsHealthCommand::class, OtpPruneCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('counters:recount')->dailyAt('03:30')->withoutOverlapping(180)->onOneServer();
            $schedule->command('videos:check-stuck')->everyFifteenMinutes()->withoutOverlapping(30)->onOneServer();
            $schedule->command('videos:prune-orphans')->hourly()->withoutOverlapping(120)->onOneServer();
            $schedule->command('videolab:cleanup')->dailyAt('03:20')->withoutOverlapping(30)->onOneServer();
            $schedule->command('quizzes:auto-submit-expired')->everyMinute()->withoutOverlapping(5)->onOneServer();
            $schedule->command('otp:prune')->dailyAt('03:00')->withoutOverlapping(30)->onOneServer();
            $schedule->command('queue:prune-failed', ['--hours' => config('ops.failed_jobs_retention_hours')])
                ->dailyAt('03:10')->withoutOverlapping(30)->onOneServer();
            $schedule->command('queue:monitor', [
                config('queue.default').':'.config('ops.queues.default').','.config('queue.default').':'.config('ops.queues.exports'),
                '--max' => config('ops.health.queue_backlog_max'),
            ])->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
            // Cảnh báo (log level error → kênh cảnh báo của hạ tầng) khi worker/scheduler chết hoặc failed_jobs tồn đọng.
            $schedule->command('ops:health', ['--log' => true])->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
            // Nhịp sống scheduler: KHÔNG onOneServer — mỗi máy chạy scheduler đều tự báo.
            $schedule->call(fn () => Heartbeat::beat('scheduler'))->name('ops-scheduler-heartbeat')->everyMinute();
        });

        Event::listen(Looping::class, fn () => Heartbeat::workerBeat());
        Event::listen(JobFailed::class, fn (JobFailed $e) => Log::error('Job queue thất bại', [
            'job' => $e->job->resolveName(),
            'queue' => $e->job->getQueue(),
            'exception' => $e->exception::class,
        ]));
        Event::listen(QueueBusy::class, fn (QueueBusy $e) => Log::warning('Queue tồn đọng', [
            'queue' => $e->queue,
            'size' => $e->size,
        ]));
    }
}
