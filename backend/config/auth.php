<?php

use App\Models\User;

$otpRelaxed = filter_var(env('AUTH_OTP_E2E_RELAXED', false), FILTER_VALIDATE_BOOL)
    && in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | OTP (US-001, US-004 — S9)
    |--------------------------------------------------------------------------
    |
    | `channels`: danh sách kênh được phép gửi OTP. Production MVP chỉ `email`
    | (S9, S11 — chưa có nhà cung cấp SMS thật). `LogSmsOtpSender` (T04) chỉ
    | được bind ở local/testing.
    |
    */

    'otp' => [
        'channels' => array_filter(array_map('trim', explode(',', (string) env('AUTH_OTP_CHANNELS', 'email')))),
        'ttl_minutes' => (int) env('AUTH_OTP_TTL_MINUTES', 10),
        // Chế độ nới hạn mức cho e2e/dev: CHỈ có hiệu lực khi APP_ENV là local/testing (production luôn bỏ qua
        // AUTH_OTP_E2E_RELAXED và giữ 1/phút, 5/giờ, 10/ngày).
        'e2e_relaxed' => $otpRelaxed,
        'cooldown_seconds' => $otpRelaxed ? 1 : (int) env('AUTH_OTP_COOLDOWN_SECONDS', 60),
        'max_per_hour' => $otpRelaxed ? 1000 : (int) env('AUTH_OTP_MAX_PER_HOUR', 5),
        'max_per_day' => $otpRelaxed ? 10000 : (int) env('AUTH_OTP_MAX_PER_DAY', 10),
        // Số lần gọi route gửi OTP/phút/tài khoản (limiter `otp-send`) và trần/giờ/IP; quên mật khẩu theo tài khoản.
        'send_per_minute' => $otpRelaxed ? 1000 : 1,
        'send_per_ip_hour' => $otpRelaxed ? 10000 : 30,
        'forgot_cooldown_max' => $otpRelaxed ? 1000 : 1,
        'forgot_per_hour' => $otpRelaxed ? 1000 : 5,
        'max_verify_per_minute' => (int) env('AUTH_OTP_MAX_VERIFY_PER_MINUTE', 5),
        'max_verify_per_day' => (int) env('AUTH_OTP_MAX_VERIFY_PER_DAY', 20),
        'max_attempts_per_code' => (int) env('AUTH_OTP_MAX_ATTEMPTS_PER_CODE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Đăng nhập học sinh: đòi captcha thay vì khoá (GL-A2, api-contract §1.6)
    |--------------------------------------------------------------------------
    | Dưới `captcha_threshold` lần sai/giờ/tài khoản (đếm theo định danh đã chuẩn hoá, mọi IP): đăng nhập bình thường. Từ
    | ngưỡng: phải kèm `captcha_token` hợp lệ (422 CAPTCHA_REQUIRED / CAPTCHA_INVALID), KHÔNG khoá tài khoản. Trần cứng
    | `max_failures_per_account` (kể cả có captcha) và `max_failures_per_ip` → 429. Staff: khoá `auth.staff.*` bên dưới.
    */
    'login' => [
        'captcha_threshold' => (int) env('AUTH_LOGIN_CAPTCHA_THRESHOLD', 5),
        'max_failures_per_account' => (int) env('AUTH_LOGIN_MAX_FAILURES', 100),
        // R1 (NAT lớp học): khoá IP CHỈ đếm lượt sai KHÔNG kèm captcha hợp lệ; mặc định 200/giờ (CHỜ PO XÁC NHẬN SỐ).
        'max_failures_per_ip' => (int) env('AUTH_LOGIN_MAX_FAILURES_IP', 200),
        // V2-2: trần IP riêng, cao, cho lượt sai CÓ captcha hợp lệ (chặn trên tốc độ stuffing kèm dịch vụ giải captcha).
        'max_captcha_failures_per_ip' => (int) env('AUTH_LOGIN_MAX_CAPTCHA_FAILURES_IP', 1000),
        // R2/S7 + V2-3a: lượt bị captcha từ chối (thiếu/sai) mỗi phút theo cặp IP+tài khoản (thấp) và theo IP (cao, chống đốt
        // quota siteverify/giữ worker); dùng chung 2 host (khoá limiter riêng từng host).
        'captcha_rejects_per_minute' => (int) env('AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE', 10),
        'captcha_rejects_per_minute_ip' => (int) env('AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE_IP', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phiên quản trị (T28, ADR-004 §2.2)
    |--------------------------------------------------------------------------
    | Idle 120 phút và tối đa 12 giờ kể từ lúc đăng nhập bước mật khẩu; quá hạn → 401
    | STAFF_IDLE_TIMEOUT (middleware `staff.idle`). Giới hạn đăng nhập sai theo api-contract §1.6.
    */
    'staff' => [
        'login_captcha_threshold' => (int) env('AUTH_STAFF_LOGIN_CAPTCHA_THRESHOLD', 5),
        'idle_minutes' => (int) env('AUTH_STAFF_IDLE_MINUTES', 120),
        'absolute_hours' => (int) env('AUTH_STAFF_ABSOLUTE_HOURS', 12),
        'login_max_failures_per_account' => (int) env('AUTH_STAFF_LOGIN_MAX_FAILURES', 100),
        'login_max_failures_per_ip' => (int) env('AUTH_STAFF_LOGIN_MAX_FAILURES_IP', 200),
        'login_max_captcha_failures_per_ip' => (int) env('AUTH_STAFF_LOGIN_MAX_CAPTCHA_FAILURES_IP', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mật khẩu tài khoản demo (CHỈ local)
    |--------------------------------------------------------------------------
    | `UserFactory` (seeder demo) dùng giá trị này ở môi trường local; ở testing dùng `password`. Đạt chính sách staff
    | (>= 12 ký tự, không nằm trong danh sách phổ biến). Không có hiệu lực ở production.
    */
    'demo_password' => env('DEMO_ACCOUNT_PASSWORD', 'Demo-VitaminVui-2026'),

];
