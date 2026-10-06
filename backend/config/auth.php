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
    | Phiên quản trị (T28, ADR-004 §2.2)
    |--------------------------------------------------------------------------
    | Idle 120 phút và tối đa 12 giờ kể từ lúc đăng nhập bước mật khẩu; quá hạn → 401
    | STAFF_IDLE_TIMEOUT (middleware `staff.idle`). Giới hạn đăng nhập sai theo api-contract §1.6.
    */
    'staff' => [
        'idle_minutes' => (int) env('AUTH_STAFF_IDLE_MINUTES', 120),
        'absolute_hours' => (int) env('AUTH_STAFF_ABSOLUTE_HOURS', 12),
        'login_max_failures_per_account' => (int) env('AUTH_STAFF_LOGIN_MAX_FAILURES', 10),
        'login_max_failures_per_ip' => (int) env('AUTH_STAFF_LOGIN_MAX_FAILURES_IP', 50),
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
