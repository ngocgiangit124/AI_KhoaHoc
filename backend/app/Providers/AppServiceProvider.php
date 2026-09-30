<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Captcha\CaptchaVerifier;
use App\Services\Auth\Captcha\FakeCaptchaVerifier;
use App\Services\Auth\Captcha\TurnstileVerifier;
use App\Support\ProductionConfigGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
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
        // T03 — CaptchaVerifier: 'fake' CHỈ hợp lệ ở local/testing, production
        // cấm qua ProductionConfigGuard (M3/M4 — allowlist thật, không phải
        // blocklist). Không dùng singleton: rẻ để tạo, và tránh giữ secret
        // trong bộ nhớ lâu hơn cần thiết.
        //
        // M3 (review docs/security/review-T03-FW1.md) — TRƯỚC ĐÂY `default`
        // rơi vào `FakeCaptchaVerifier`: gõ sai chính tả/viết hoa
        // `CAPTCHA_DRIVER` (`Turnstile`, `TURNSTILE`, `none`, chuỗi rỗng...)
        // ở production vẫn chạy được nhưng KHÔNG CÓ captcha thật nào — "fail
        // open" thay vì "fail closed". Giờ chỉ 2 giá trị CHÍNH XÁC (phân biệt
        // hoa/thường) được chấp nhận; driver lạ ném exception ngay lúc resolve
        // (ứng dụng "không boot" được luồng cần captcha, thay vì âm thầm bỏ
        // qua bảo vệ).
        $this->app->bind(CaptchaVerifier::class, function () {
            return match (config('captcha.driver')) {
                'turnstile' => new TurnstileVerifier((string) config('services.turnstile.secret')),
                'fake' => new FakeCaptchaVerifier,
                default => throw new RuntimeException(
                    "CAPTCHA_DRIVER không hợp lệ: '".config('captcha.driver')."' (phải là 'turnstile' hoặc 'fake')."
                ),
            };
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
        // R2 (review docs/qa/review-T03-FW1.md) — api-contract §1.6 ghi "10 lần
        // SAI/giờ/login": lớp theo IP dưới đây vẫn đếm MỌI request (đúng như
        // trước, không phân biệt đúng/sai — hợp đồng không nói "sai" cho lớp
        // IP). Lớp theo TÀI KHOẢN không còn khai ở đây (middleware
        // `ThrottleRequests` đếm ngay khi request đi qua, không biết kết quả
        // xác thực) — chuyển sang `LoginService::authenticate()`, chỉ
        // `RateLimiter::hit()` khi sai thông tin đăng nhập (dùng chung tên
        // khoá `login:<login>` để 2 nơi không lệch nhau). T28 (đăng nhập
        // quản trị) phải tự áp lại cùng quy tắc "chỉ đếm lần sai" cho
        // `StaffAuthService` — limiter này chỉ còn lớp IP dùng chung 2 host.
        RateLimiter::for('login', fn (Request $request) => Limit::perHour(50)->by('login-ip:'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(30)->by($request->ip()));

        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perHour(5)->by('password-reset:'.mb_strtolower((string) $request->input('login'))),
                Limit::perHour(30)->by($request->ip()),
            ];
        });

        RateLimiter::for('otp-send', function (Request $request) {
            $identity = $this->identity($request);
            $dayRawKey = 'otp-send-day:'.$identity;
            $maxPerDay = (int) config('auth.otp.max_per_day');

            $this->auditOnceIfDailyLimitReached($request, 'otp-send', $dayRawKey, $maxPerDay);

            return [
                // T04 security review L3 — TRƯỚC ĐÂY cứng `perMinute(1)` (60
                // giây), lệch khỏi `auth.otp.cooldown_seconds` (nguồn cấu
                // hình DUY NHẤT mà `OtpService::assertUnderSendLimits()`
                // dùng) nếu ai đó đổi `AUTH_OTP_COOLDOWN_SECONDS` mà quên sửa
                // ở đây — 2 lớp cooldown (route + Service) lệch nhau.
                Limit::perSecond(1, (int) config('auth.otp.cooldown_seconds'))->by('otp-send-cooldown:'.$identity),
                Limit::perHour((int) config('auth.otp.max_per_hour'))->by('otp-send-hour:'.$identity),
                Limit::perDay($maxPerDay)->by($dayRawKey),
                Limit::perHour(30)->by('otp-send-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $identity = $this->identity($request);
            $dayRawKey = 'otp-verify-day:'.$identity;
            $maxPerDay = (int) config('auth.otp.max_verify_per_day');

            $this->auditOnceIfDailyLimitReached($request, 'otp-verify', $dayRawKey, $maxPerDay);

            return [
                Limit::perMinute((int) config('auth.otp.max_verify_per_minute'))->by('otp-verify:'.$identity),
                Limit::perDay($maxPerDay)->by($dayRawKey),
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

        // T14 review R3 — chặn vòng lặp gửi/bị từ chối/gửi lại sinh vô hạn dòng
        // `rejected` và làm nhiễu người duyệt.
        RateLimiter::for('free-enroll', fn (Request $request) => Limit::perMinute(10)->by('free-enroll:'.$this->identity($request)));

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
        RateLimiter::for('csrf', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }

    private function identity(Request $request): string
    {
        return (string) ($request->user()?->getKey() ?? $request->ip());
    }

    /**
     * T04 review R2 — data-model §3.1: "vượt trần ngày → khoá xác thực 24h +
     * ghi `audit_logs`" (action `otp.daily_limit`). Khoá 24h đã có SẴN (cửa sổ
     * `Limit::perDay()` của Laravel tự khoá tới khi hết `decaySeconds`); phần
     * còn thiếu là ghi audit.
     *
     * CỐ Ý KHÔNG dùng `Limit::response()`: response tuỳ biến của nó được
     * `ThrottleRequests` bọc trong `Illuminate\Http\Exceptions\HttpResponseException`
     * — exception này KHÔNG implement `HttpExceptionInterface`, nên
     * `ApiExceptionRenderer::resolve()` (backend/app/Support/ApiExceptionRenderer.php)
     * không nhận diện được và rơi vào nhánh mặc định `500 INTERNAL_ERROR`,
     * PHÁ VỠ hành vi 429 chuẩn đã qua review/test ở T01–T03. Thay vào đó, kiểm
     * TRƯỚC khi trả về danh sách `Limit` (closure này chạy lại mỗi request,
     * trước khi `ThrottleRequests` tự quyết định chặn hay không): nếu định
     * danh ĐÃ chạm trần ngày, ghi audit rồi mới trả về `Limit` như cũ — không
     * đổi response 429 mặc định.
     *
     * `Cache::add()` (chỉ ghi nếu key CHƯA tồn tại) đảm bảo chỉ ghi audit ĐÚNG
     * 1 LẦN cho mỗi lần "chạm trần" (không ghi lặp ở các request bị chặn tiếp
     * theo trong cùng ngày) — tự hết hạn sau 24h, khớp thời gian khoá.
     */
    private function auditOnceIfDailyLimitReached(Request $request, string $limiterName, string $dayRawKey, int $maxPerDay): void
    {
        if (! RateLimiter::tooManyAttempts($this->namedLimiterCacheKey($limiterName, $dayRawKey), $maxPerDay)) {
            return;
        }

        if (Cache::add('otp-daily-limit-audit:'.$limiterName.':'.$dayRawKey, true, now()->addDay())) {
            app(AuditLogger::class)->log('otp.daily_limit', $request->user());
        }
    }

    /**
     * Tái tạo ĐÚNG khoá cache mà `Illuminate\Routing\Middleware\ThrottleRequests`
     * dùng nội bộ cho limiter có TÊN (`self::$shouldHashKeys` mặc định `true`
     * từ Laravel 11 trở đi — dự án không gọi `ThrottleRequests::shouldHashKeys(false)`
     * ở đâu cả) — bắt buộc để đọc ĐÚNG bộ đếm mà `Limit::perDay()->by($rawKey)`
     * bên dưới sẽ tạo ra, thay vì tự duy trì 1 bộ đếm riêng (dễ lệch nhau).
     */
    private function namedLimiterCacheKey(string $limiterName, string $rawKey): string
    {
        return md5($limiterName.$rawKey);
    }
}
