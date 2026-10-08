<?php

namespace App\Providers;

use App\Models\TeacherProfile;
use App\Models\User;
use App\Rules\NotCommonPassword;
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
use App\Support\CatalogThrottle;
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

        // US-020: không có Policy theo model vì không có quyền theo từng bản ghi. Giáo viên chỉ đi qua `/admin/me/...`
        // (hồ sơ của chính mình); Admin/QLT quản lý mọi hồ sơ nhưng KHÔNG đồng ý thay (Admin/QLT gọi `/me` → 403).
        Gate::define('manage-teacher-profiles', fn (User $user) => $user->isStaff());
        Gate::define('own-teacher-profile', fn (User $user) => $user->isTeacher());
        // L1 (security T36): quyền của chủ thể dữ liệu. Người ĐÃ TỪNG có hồ sơ (dòng `teacher_profiles`) mà nay đổi vai trò vẫn tự rút
        // đồng ý và xoá ảnh của mình được. Chỉ dùng cho 2 thao tác gỡ; đồng ý/sửa nội dung vẫn chỉ cho `own-teacher-profile`.
        Gate::define('withdraw-own-teacher-profile', fn (User $user) => $user->isTeacher()
            || TeacherProfile::query()->whereKey($user->getKey())->exists());
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
        // Học sinh: tối thiểu 8 ký tự + chặn mật khẩu phổ biến (danh sách cục bộ, không gọi dịch vụ ngoài nước).
        // Staff dùng `App\Support\StaffPassword` (12 ký tự).
        Password::defaults(fn () => Password::min(8)->rules([new NotCommonPassword]));
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
                Limit::perMinute((int) config('auth.otp.send_per_minute'))->by('otp-send-cooldown:'.$identity),
                Limit::perHour((int) config('auth.otp.max_per_hour'))->by('otp-send-hour:'.$identity),
                Limit::perDay((int) config('auth.otp.max_per_day'))->by('otp-send-day:'.$identity),
                Limit::perHour((int) config('auth.otp.send_per_ip_hour'))->by('otp-send-ip:'.$request->ip()),
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

        // 30 lần SAI/ngày/HS (S18) do CartService::applyCoupon đếm riêng (limiter chỉ đếm mọi request, sẽ phạt cả lần đúng).
        RateLimiter::for('coupon', function (Request $request) {
            $identity = $this->identity($request);

            return [
                Limit::perMinute(10)->by('coupon:'.$identity),
                Limit::perHour(60)->by('coupon-ip:'.$request->ip()),
            ];
        });

        // Cụm 3 L2: giỏ hàng + preview checkout (mỗi lần mở transaction, khoá `carts`, đếm `orders`): 60/phút/người.
        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute(60)->by('cart:'.$this->identity($request)));
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

        // T22: autosave quiz 120/phút/lượt (người làm bài chọn đáp án liên tục); bắt đầu/nộp 30/phút/người, xem/lịch sử 60/phút/người.
        RateLimiter::for('quiz-answer', function (Request $request) {
            $attempt = $request->route('attempt');

            return Limit::perMinute(120)->by($this->identity($request).':quiz-answer:'.(is_scalar($attempt) ? $attempt : ''));
        });
        RateLimiter::for('quiz', fn (Request $request) => Limit::perMinute(30)->by($this->identity($request).':quiz'));
        RateLimiter::for('quiz-read', fn (Request $request) => Limit::perMinute(60)->by($this->identity($request).':quiz-read'));

        // T23: Khóa học của tôi / tiến độ (đọc, tổng hợp nhiều bảng): 60/phút/người.
        RateLimiter::for('me-courses', fn (Request $request) => Limit::perMinute(60)->by($this->identity($request).':me-courses'));

        // US-020 (T36): ghi hồ sơ giáo viên 30/phút/người; tải ảnh (decode + encode WebP, tốn CPU) 10/phút/người.
        RateLimiter::for('teacher-profile', fn (Request $request) => Limit::perMinute(30)->by('teacher-profile:'.$this->identity($request)));
        RateLimiter::for('teacher-avatar', fn (Request $request) => Limit::perMinute(10)->by('teacher-avatar:'.$this->identity($request)));

        // T29/T34 (ADR-006). `privacy-read`: đọc đồng ý/liên hệ phụ huynh/hạn mức xuất dữ liệu. `parent-contact`: sửa liên hệ
        // phụ huynh 5/giờ, 10/ngày (route còn gắn `password-change`). `parent-notice-unsub`: trang huỷ nhận công khai, theo IP.
        RateLimiter::for('privacy-read', fn (Request $request) => Limit::perMinute(60)->by('privacy-read:'.$this->identity($request)));
        RateLimiter::for('parent-contact', function (Request $request) {
            $identity = $this->identity($request);

            return [
                Limit::perHour(5)->by('parent-contact:'.$identity),
                Limit::perDay(10)->by('parent-contact-day:'.$identity),
            ];
        });
        RateLimiter::for('parent-notice-unsub', fn (Request $request) => Limit::perHour(30)->by('parent-notice-unsub:'.$request->ip()));

        RateLimiter::for('catalog', fn (Request $request) => CatalogThrottle::limits($request));
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
