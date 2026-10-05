<?php

namespace App\Providers;

use App\Console\Commands\VideosCheckStuckCommand;
use App\Console\Commands\VideosPruneOrphansCommand;
use App\Services\Video\Contracts\VideoProvider;
use App\Services\Video\Providers\FakeVideoProvider;
use App\Services\Video\VideoProviderManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class VideoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `fake` CHỈ tồn tại ở local/testing (ProductionConfigGuard cũng cấm bật ở production).
        $this->app->singleton(FakeVideoProvider::class);
        $this->app->singleton(VideoProviderManager::class, function ($app) {
            $manager = new VideoProviderManager($app);

            if ($app->environment('local', 'testing')) {
                $manager->extend('fake', fn () => $app->make(FakeVideoProvider::class));
            }

            return $manager;
        });
        $this->app->bind(VideoProvider::class, fn ($app) => $app->make(VideoProviderManager::class)->driver());
    }

    public function boot(): void
    {
        $this->commands([VideosCheckStuckCommand::class, VideosPruneOrphansCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('videos:check-stuck')->everyFifteenMinutes()->withoutOverlapping();
            $schedule->command('videos:prune-orphans')->hourly()->withoutOverlapping();
        });
    }
}
