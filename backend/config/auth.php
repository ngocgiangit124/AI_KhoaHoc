<?php

use App\Models\User;

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
        'cooldown_seconds' => (int) env('AUTH_OTP_COOLDOWN_SECONDS', 60),
        'max_per_hour' => (int) env('AUTH_OTP_MAX_PER_HOUR', 5),
        'max_per_day' => (int) env('AUTH_OTP_MAX_PER_DAY', 10),
        'max_verify_per_minute' => (int) env('AUTH_OTP_MAX_VERIFY_PER_MINUTE', 5),
        'max_verify_per_day' => (int) env('AUTH_OTP_MAX_VERIFY_PER_DAY', 20),
        'max_attempts_per_code' => (int) env('AUTH_OTP_MAX_ATTEMPTS_PER_CODE', 5),
        // T04 security review L1 — trần THEO ĐỊA CHỈ NHẬN (không chỉ theo
        // user): nhiều tài khoản lần lượt đổi sang CÙNG 1 địa chỉ CHƯA đăng
        // ký vẫn có thể dồn mã tới cùng 1 hộp thư/SĐT theo thời gian nếu chỉ
        // giới hạn theo user (`OtpService::assertUnderDestinationLimit()`).
        // Đặt CAO HƠN `max_per_hour`/`max_per_day` ở trên (không dùng đúng ví
        // dụ 3/giờ, 10/ngày trong báo cáo security): trần theo đích tính
        // GỘP cho MỌI user cùng gửi tới 1 địa chỉ trong cùng cửa sổ — nếu đặt
        // bằng hoặc thấp hơn trần theo user, ngay cả 1 user hợp lệ tự gửi lại
        // OTP cho chính mình (không có ai khác tham gia) cũng chạm trần theo
        // đích TRƯỚC KHI chạm trần theo user, biến "5 lần/giờ" quảng cáo cho
        // 1 tài khoản thành ảo. Đặt gấp đôi để lớp này chỉ có tác dụng khi
        // TỪ 2 tài khoản trở lên cùng nhắm 1 địa chỉ trong cùng cửa sổ.
        'max_per_hour_per_destination' => (int) env('AUTH_OTP_MAX_PER_HOUR_PER_DESTINATION', 10),
        'max_per_day_per_destination' => (int) env('AUTH_OTP_MAX_PER_DAY_PER_DESTINATION', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phiên quản trị (T28 — ADR-004 §2.2, api-contract §1.7 STAFF_IDLE_TIMEOUT)
    |--------------------------------------------------------------------------
    |
    | `idle_minutes`: không hoạt động quá ngưỡng này → hết phiên. `max_hours`:
    | tổng thời lượng phiên kể từ lúc đăng nhập, bất kể còn hoạt động hay
    | không. Cả 2 do `App\Http\Middleware\StaffIdleTimeout` kiểm (session
    | cookie tự thân — ConfigureHostContext — chỉ đặt hạn tuyệt đối 12 giờ cho
    | trình duyệt, không tự trượt theo hoạt động).
    |
    */

    'staff' => [
        'idle_minutes' => (int) env('STAFF_SESSION_IDLE_MINUTES', 120),
        'max_hours' => (int) env('STAFF_SESSION_MAX_HOURS', 12),
    ],

];
