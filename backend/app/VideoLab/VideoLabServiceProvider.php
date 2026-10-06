<?php

namespace App\VideoLab;

use App\VideoLab\Console\VideoLabCleanupCommand;
use App\VideoLab\Http\Middleware\ForceJson;
use App\VideoLab\Services\MediaToolkit;
use App\VideoLab\Support\VideoLabStorage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Module VideoLab độc lập (ADR-002 §1): chỉ đăng ký route khi `videolab.enabled`. Không nhóm `web`.
 */
class VideoLabServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VideoLabStorage::class);
        $this->app->bind(MediaToolkit::class);
    }

    public function boot(): void
    {
        $this->commands([VideoLabCleanupCommand::class]);

        if (! config('videolab.enabled')) {
            return;
        }

        $this->guardProductionConfig();

        if (! $this->app->routesAreCached()) {
            Route::middleware([ForceJson::class])->group(base_path('routes/videolab.php'));
        }
    }

    /** Mọi môi trường ngoài local/testing: khoá phải đặt riêng, đủ dài; không dùng khoá suy ra từ APP_KEY (config trả null ở production). */
    public function guardProductionConfig(): void
    {
        if ($this->app->environment('local', 'testing')) {
            return;
        }

        foreach (['api_key', 'token_key', 'webhook_secret'] as $key) {
            $value = config("videolab.{$key}");

            throw_if(
                ! is_string($value) || mb_strlen($value) < 32,
                RuntimeException::class,
                'VIDEOLAB_'.strtoupper($key).' phải đặt trong .env và dài tối thiểu 32 ký tự (openssl rand -hex 32).'
            );
        }

        throw_if(
            ! str_starts_with((string) config('videolab.public_url'), 'https://'),
            RuntimeException::class,
            'VIDEOLAB_PUBLIC_URL phải dùng https (ngoài local/testing).'
        );
    }
}
