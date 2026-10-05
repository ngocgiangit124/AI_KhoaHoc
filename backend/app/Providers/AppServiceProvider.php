<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\Captcha\CaptchaVerifier;
use App\Services\Auth\Captcha\FakeCaptchaVerifier;
use App\Services\Auth\Captcha\TurnstileVerifier;
use App\Services\Auth\LoginService;
use App\Services\Auth\Otp\LogSmsOtpSender;
use App\Services\Auth\Otp\OtpDispatcher;
use App\Services\Auth\Otp\OtpSender;
use App\Services\Auth\Otp\SmsOtpSender;
use App\Services\Auth\PasswordService;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\Fake\FakeGateway;
use App\Services\Payments\PaymentGatewayManager;
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

        $this->app->bind(OtpSender::class, OtpDispatcher::class);

        // S9: nhà cung cấp SMS giả lập CHỈ tồn tại ở local/testing. Production không bind gì
        // (kênh `sms` bị chặn ở validation và ProductionConfigGuard).
        if ($this->app->environment('local', 'testing')) {
            $this->app->bind(SmsOtpSender::class, LogSmsOtpSender::class);
        }

        // T17 (ADR-001): cổng thanh toán resolve qua manager (allowlist enabled_gateways). FakeGateway
        // CHỈ được đăng ký ở local/testing (S4); production không có driver `fake`.
        $this->app->singleton(PaymentGatewayManager::class, function ($app) {
            $manager = new PaymentGatewayManager($app);

            if ($app->environment('local', 'testing')) {
                $manager->extend('fake', fn () => new FakeGateway);
            }

            return $manager;
        });
        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(PaymentGatewayManager::class)->driver());
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
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(120)->by('login-flood:'.$request->getHost().':'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(30)->by($request->ip()));

        // Quên mật khẩu (T27): route chỉ chặn theo IP. Hạn mức THEO TÀI KHOẢN (cooldown 60s, 5/giờ) tính SAU captcha
        // trong `PasswordService::enforceForgotLimits()` để request không captcha không tiêu hao hạn mức của nạn nhân.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(30)->by('password-reset-ip:'.$request->ip()));

        // Đổi mật khẩu khi đang đăng nhập: chống dò mật khẩu hiện tại bằng phiên bị chiếm.
        RateLimiter::for('password-change', function (Request $request) {
            $identity = $this->identity($request);

            return [
                Limit::perMinute(5)->by('password-change:'.$identity),
                Limit::perHour(20)->by('password-change-hour:'.$identity),
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
            // Route đặt lại mật khẩu là guest: khoá theo tài khoản (login chuẩn hoá) thay vì chỉ theo IP,
            // để đổi IP không né được 5 lần/phút, 20 lần/ngày (US-015 BR7).
            $identity = $request->user() === null && is_string($request->input('login')) && $request->input('login') !== ''
                ? $this->resetIdentity($request)
                : $this->identity($request);

            return [
                Limit::perMinute((int) config('auth.otp.max_verify_per_minute'))->by('otp-verify:'.$identity),
                Limit::perDay((int) config('auth.otp.max_verify_per_day'))->by('otp-verify-day:'.$identity),
                Limit::perHour(60)->by('otp-verify-ip:'.$request->ip()),
            ];
        });

        // Đổi email/SĐT: chống flood (trần mã OTP nằm ở OtpService). Không cooldown 60s vì sửa nhầm
        // email ngay sau đăng ký là luồng chính.
        RateLimiter::for('contact', function (Request $request) {
            return [
                Limit::perHour(10)->by('contact:'.$this->identity($request)),
                Limit::perHour(30)->by('contact-ip:'.$request->ip()),
            ];
        });

        // Đổi mật khẩu quản trị (T28): chống dò mật khẩu hiện tại bằng phiên bị đánh cắp.
        RateLimiter::for('admin-password', function (Request $request) {
            $identity = $this->identity($request);

            return [
                Limit::perMinute(5)->by('admin-password:'.$identity),
                Limit::perHour(20)->by('admin-password-hour:'.$identity),
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

    /**
     * Danh tính cho limiter quên/đặt lại mật khẩu (guest): tài khoản có thật → theo id (email và SĐT của cùng
     * 1 người dùng chung hạn mức); không có → khoá chuẩn hoá `LoginService::accountKey`. Cả hai nhánh đều bị
     * giới hạn y hệt nhau nên 429 không lộ tài khoản tồn tại.
     */
    private function resetIdentity(Request $request): string
    {
        $login = $request->input('login');

        return PasswordService::throttleIdentity(is_string($login) ? $login : '');
    }

    private function identity(Request $request): string
    {
        return (string) ($request->user()?->getKey() ?? $request->ip());
    }
}
