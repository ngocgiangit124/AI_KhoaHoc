<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\Captcha\CaptchaVerifier;
use App\Services\Auth\Captcha\FakeCaptchaVerifier;
use App\Services\Auth\Captcha\TurnstileVerifier;
use App\Support\ProductionConfigGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CaptchaVerifier::class, fn () => match ((string) config('captcha.driver')) {
            'turnstile' => new TurnstileVerifier,
            // fake chỉ hợp lệ ở local/testing; ProductionConfigGuard cấm ở production.
            'fake' => new FakeCaptchaVerifier,
            default => throw new RuntimeException('CAPTCHA_DRIVER không hợp lệ (turnstile|fake).'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configurePasswords();
        $this->configureRateLimiters();
        $this->configureGates();
        $this->configureJsonResources();
        (new ProductionConfigGuard)->check();
    }

    /**
     * api-contract §1.4 — 1 đối tượng trả JSON phẳng (không bọc "data"); danh
     * sách vẫn có dạng {data, meta, links} (paginator tự bọc, không đổi).
     */
    private function configureJsonResources(): void
    {
        JsonResource::withoutWrapping();
    }

    /**
     * ADR-004 §3 — không dùng Gate::before (bỏ qua quy tắc nghiệp vụ).
     */
    private function configureGates(): void
    {
        Gate::define('manage-system', fn (User $user) => $user->isAdmin());

        Gate::define('access-admin-area', fn (User $user) => $user->isStaff() || $user->isTeacher());
    }

    /**
     * S17 — mass assignment: bắt lỗi sớm khi gán thuộc tính không có trong $fillable,
     * khi truy cập quan hệ chưa eager-load, hoặc khi đọc thuộc tính không tồn tại.
     * Tắt ở production để không crash người dùng vì lỗi lập trình nhỏ.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::preventLazyLoading($this->app->environment('local', 'testing'));
    }

    private function configurePasswords(): void
    {
        Password::defaults(fn () => Password::min(8));
    }

    /**
     * Khai báo khung cho toàn bộ limiter ở api-contract §1.6 — route cụ thể gắn
     * `throttle:<tên>` dần ở các task sau (T03 trở đi).
     */
    private function configureRateLimiters(): void
    {
        // `login`: CHỈ là lớp chống flood thô theo IP (đếm mọi request). Giới hạn "sai 10 lần/giờ/tài
        // khoản" + "50 lần sai/giờ/IP" (contract §1.6) đếm lượt SAI trong LoginService (R1).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(120)->by('login-flood:'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(30)->by($request->ip()));

        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perHour(5)->by('password-reset:'.mb_strtolower((string) $request->input('login'))),
                Limit::perHour(30)->by($request->ip()),
            ];
        });

        RateLimiter::for('otp-send', function (Request $request) {
            $identity = $this->identity($request);

            return [
                Limit::perMinute(1)->by('otp-send-cooldown:'.$identity),
                Limit::perHour((int) config('auth.otp.max_per_hour'))->by('otp-send-hour:'.$identity),
                Limit::perDay((int) config('auth.otp.max_per_day'))->by('otp-send-day:'.$identity),
                Limit::perHour(30)->by('otp-send-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $identity = $this->identity($request);

            return [
                Limit::perMinute((int) config('auth.otp.max_verify_per_minute'))->by('otp-verify:'.$identity),
                Limit::perDay((int) config('auth.otp.max_verify_per_day'))->by('otp-verify-day:'.$identity),
                Limit::perHour(60)->by('otp-verify-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('coupon', function (Request $request) {
            $identity = $this->identity($request);

            return [
                Limit::perMinute(10)->by('coupon:'.$identity),
                Limit::perDay(30)->by('coupon-day:'.$identity),
                Limit::perHour(60)->by('coupon-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($this->identity($request)));
        RateLimiter::for('pay', fn (Request $request) => Limit::perMinute(10)->by($this->identity($request)));

        RateLimiter::for('check-payment', function (Request $request) {
            $order = $request->route('order');
            $orderKey = is_object($order) && method_exists($order, 'getKey') ? $order->getKey() : $order;

            return Limit::perSecond(1, 30)->by('check-payment:'.$orderKey);
        });

        RateLimiter::for('playback', fn (Request $request) => Limit::perMinute(30)->by($this->identity($request)));

        RateLimiter::for('heartbeat', function (Request $request) {
            $lesson = $request->route('lesson');
            $lessonKey = is_object($lesson) && method_exists($lesson, 'getKey') ? $lesson->getKey() : $lesson;

            return Limit::perMinute(6)->by($this->identity($request).':'.$lessonKey);
        });

        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('export', fn (Request $request) => Limit::perDay(10)->by($this->identity($request)));

        // M3 (review bảo mật T01/T02) — `csrf-token` không throttle trước đó:
        // client ngoài trình duyệt chỉ cần đặt Origin là tạo được 1 phiên Redis
        // mới (7 ngày) mỗi request, không giới hạn.
        RateLimiter::for('csrf', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }

    private function identity(Request $request): string
    {
        return (string) ($request->user()?->getKey() ?? $request->ip());
    }
}
