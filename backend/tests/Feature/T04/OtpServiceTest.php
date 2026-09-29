<?php

use App\Enums\OtpPurpose;
use App\Exceptions\DomainException;
use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * T04 — `OtpService`: sinh/gửi/xác thực mã (data-model §3.1, S9). Test ở tầng
 * Service (không qua HTTP/throttle) để kiểm chính xác hành vi nguyên tử của
 * `attempts`, không bị nhiễu bởi limiter theo phút/route.
 */
function vvVerifyFails(OtpService $service, User $user, string $code): ?ValidationException
{
    try {
        $service->verify($user, OtpPurpose::VerifyAccount, $code);

        return null;
    } catch (ValidationException $e) {
        return $e;
    }
}

test('send() tao ma moi, huy ma cu cung (user, purpose), va gui qua OtpMail (email)', function () {
    Mail::fake();
    $user = User::factory()->create();
    $service = app(OtpService::class);

    $resendAt1 = $service->send($user, OtpPurpose::VerifyAccount, 'email');
    $first = OtpCode::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

    expect($resendAt1->isFuture())->toBeTrue();
    expect($first->channel)->toBe('email');
    expect($first->destination)->toBe($user->email);
    expect($first->invalidated_at)->toBeNull();
    expect($first->consumed_at)->toBeNull();
    // Mã KHÔNG BAO GIỜ được lưu ở dạng rõ (S21).
    expect($first->code_hash)->not->toBeEmpty();
    expect(strlen($first->code_hash))->toBeGreaterThan(20);

    // T04 R1 — send() giờ tự giới hạn cooldown 60s; nhích đồng hồ qua mốc đó
    // để lần gửi thứ 2 không bị chính Service chặn (test hành vi huỷ mã cũ,
    // không phải test cooldown — cooldown có test riêng bên dưới).
    Carbon::setTestNow(now()->addSeconds(61));

    $service->send($user, OtpPurpose::VerifyAccount, 'email');
    $second = OtpCode::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

    expect($second->id)->not->toBe($first->id);
    expect($first->fresh()->invalidated_at)->not->toBeNull();
    expect($second->invalidated_at)->toBeNull();

    Mail::assertQueued(OtpMail::class, 2);
});

/**
 * T04 review R6 — `send()` phải huỷ mã active của (user, purpose) BẤT KỂ
 * `channel`: nếu chỉ huỷ đúng cùng kênh, 1 mã `sms` mới sinh sẽ để mã `email`
 * cũ (khác kênh) tồn tại song song "hợp lệ" trong DB, khiến `verify()` (chỉ
 * xét mã mới nhất theo `id`, không có field `channel` để chọn — api-contract
 * không định nghĩa field này cho `POST /auth/otp/verify`) không bao giờ so
 * khớp được với mã email dù người dùng nhập đúng.
 */
test('send() kenh moi huy ca ma cua kenh khac cung purpose (R6 — tranh 2 ma active dong thoi)', function () {
    config(['auth.otp.channels' => ['email', 'sms']]);
    $user = User::factory()->create(['phone' => '0912345678']);
    $service = app(OtpService::class);

    $service->send($user, OtpPurpose::VerifyAccount, 'email');
    $emailOtp = OtpCode::query()->where('user_id', $user->id)->where('channel', 'email')->latest('id')->firstOrFail();

    Carbon::setTestNow(now()->addSeconds(61));
    $service->send($user, OtpPurpose::VerifyAccount, 'sms');

    expect($emailOtp->fresh()->invalidated_at)->not->toBeNull();
});

test('send() kenh khong duoc bat (auth.otp.channels) nem loi', function () {
    config(['auth.otp.channels' => ['email']]);
    $user = User::factory()->create();
    $service = app(OtpService::class);

    expect(fn () => $service->send($user, OtpPurpose::VerifyAccount, 'sms'))
        ->toThrow(RuntimeException::class);

    expect(OtpCode::query()->where('channel', 'sms')->exists())->toBeFalse();
});

test('sendIfChannelEnabled bo qua yen lang khi kenh khong duoc bat', function () {
    config(['auth.otp.channels' => ['email']]);
    $user = User::factory()->create(['phone' => '0912345678']);
    $service = app(OtpService::class);

    $result = $service->sendIfChannelEnabled($user, OtpPurpose::VerifyAccount, 'sms');

    expect($result)->toBeNull();
    expect(OtpCode::query()->where('channel', 'sms')->exists())->toBeFalse();
});

/**
 * T04 review R1 [BLOCKER] — trần gửi PHẢI nằm trong `OtpService::send()` (độc
 * lập với `throttle:otp-send` ở tầng route), để `PUT /auth/contact` (không đi
 * qua route `/auth/otp/send`) cũng bị chặn giống hệt.
 */
test('send() vuot cooldown (60s) nem TOO_MANY_ATTEMPTS, khong tao them otp_codes', function () {
    $user = User::factory()->create();
    $service = app(OtpService::class);

    $service->send($user, OtpPurpose::VerifyAccount, 'email');
    $countBefore = OtpCode::query()->where('user_id', $user->id)->count();

    $error = null;
    try {
        $service->send($user, OtpPurpose::VerifyAccount, 'email');
    } catch (DomainException $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->code())->toBe('TOO_MANY_ATTEMPTS');
    expect($error->status())->toBe(429);
    expect(OtpCode::query()->where('user_id', $user->id)->count())->toBe($countBefore);

    // T04 security review L3 — 429 ném từ tầng Service (không qua middleware
    // `throttle:`, vd `RegistrationService`/`ContactService` gọi trực tiếp)
    // TRƯỚC ĐÂY không có `Retry-After`, khác hẳn 429 của route.
    expect($error->headers())->toHaveKey('Retry-After');
    expect((int) $error->headers()['Retry-After'])->toBeGreaterThan(0);
});

test('send() vuot tran gio (max_per_hour) nem TOO_MANY_ATTEMPTS', function () {
    config(['auth.otp.max_per_hour' => 2, 'auth.otp.max_per_day' => 999]);
    $user = User::factory()->create();
    $service = app(OtpService::class);

    $service->send($user, OtpPurpose::VerifyAccount, 'email');
    Carbon::setTestNow(now()->addSeconds(61));
    $service->send($user, OtpPurpose::VerifyAccount, 'email');
    Carbon::setTestNow(now()->addSeconds(61));

    expect(fn () => $service->send($user, OtpPurpose::VerifyAccount, 'email'))
        ->toThrow(DomainException::class);
});

test('send() vuot tran ngay (max_per_day) nem TOO_MANY_ATTEMPTS du da qua nguong gio', function () {
    config(['auth.otp.max_per_hour' => 999, 'auth.otp.max_per_day' => 3]);
    $user = User::factory()->create();
    $service = app(OtpService::class);

    for ($i = 0; $i < 3; $i++) {
        $service->send($user, OtpPurpose::VerifyAccount, 'email');
        Carbon::setTestNow(now()->addSeconds(61));
    }

    expect(fn () => $service->send($user, OtpPurpose::VerifyAccount, 'email'))
        ->toThrow(DomainException::class);
    expect(OtpCode::query()->where('user_id', $user->id)->count())->toBe(3);
});

test('assertCanSend nem TOO_MANY_ATTEMPTS khi vuot tran, dung de ContactService kiem TRUOC khi doi lien he', function () {
    $user = User::factory()->create();
    $service = app(OtpService::class);

    $service->send($user, OtpPurpose::VerifyAccount, 'email');

    expect(fn () => $service->assertCanSend($user, 'email'))->toThrow(DomainException::class);
});

test('assertCanSend khong lam gi khi kenh khong duoc bat (khong nem loi)', function () {
    config(['auth.otp.channels' => ['email']]);
    $user = User::factory()->create();
    $service = app(OtpService::class);

    expect(fn () => $service->assertCanSend($user, 'sms'))->not->toThrow(DomainException::class);
});

test('verify() dung ma: xac thuc email_verified_at, consume ma, khong dung lai duoc', function () {
    $user = User::factory()->create();
    $otp = OtpCode::factory()->for($user)->create([
        'code_hash' => Hash::make('654321'),
        'channel' => 'email',
    ]);
    $service = app(OtpService::class);

    $service->verify($user, OtpPurpose::VerifyAccount, '654321');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    expect($otp->fresh()->consumed_at)->not->toBeNull();

    $error = vvVerifyFails($service, $user, '654321');
    expect($error)->not->toBeNull();
});

test('verify() sai ma nhieu lan: toi da 5 lan duoc tang attempts/so, cac lan sau khong tang tiep (S9)', function () {
    $user = User::factory()->create();
    $otp = OtpCode::factory()->for($user)->create();
    $service = app(OtpService::class);

    $failures = 0;

    for ($i = 0; $i < 20; $i++) {
        if (vvVerifyFails($service, $user, '000000') !== null) {
            $failures++;
        }
    }

    expect($failures)->toBe(20);
    // Dù gọi 20 lần, attempts không bao giờ vượt quá max_attempts_per_code (5)
    // — UPDATE có điều kiện `attempts < 5` chặn từ lần thứ 6 trở đi, đúng yêu
    // cầu "tối đa 5 lần được so" kể cả khi 20 request tới gần như đồng thời.
    expect($otp->fresh()->attempts)->toBe((int) config('auth.otp.max_attempts_per_code'));
    expect($otp->fresh()->consumed_at)->toBeNull();
});

test('verify() ma het han: thong diep rieng biet, khong tang attempts', function () {
    $user = User::factory()->create();
    $otp = OtpCode::factory()->for($user)->expired()->create();
    $service = app(OtpService::class);

    $error = vvVerifyFails($service, $user, '123456');

    expect($error)->not->toBeNull();
    expect($error->errors()['code'][0])->toContain('hết hạn');
    expect($otp->fresh()->attempts)->toBe(0);
});

test('verify() khong con ma hieu luc (da bi invalidate) tra loi chung, khong tao/sua ma nao', function () {
    $user = User::factory()->create();
    $invalidated = OtpCode::factory()->for($user)->invalidated()->create();
    $service = app(OtpService::class);

    $error = vvVerifyFails($service, $user, '123456');

    expect($error)->not->toBeNull();
    expect($invalidated->fresh()->attempts)->toBe(0);
});

test('verify() voi purpose khac khong khop ma cua purpose verify_account', function () {
    $user = User::factory()->create();
    OtpCode::factory()->for($user)->create(['purpose' => OtpPurpose::ResetPassword]);
    $service = app(OtpService::class);

    // Chỉ có mã purpose=reset_password — verify() với purpose=verify_account
    // phải KHÔNG tìm thấy mã nào (lọc đúng theo purpose), bất kể mã nhập gì.
    $error = vvVerifyFails($service, $user, '123456');

    expect($error)->not->toBeNull();
});

test('verify() qua kenh sms danh dau phone_verified_at (khong dung chung voi email_verified_at)', function () {
    $user = User::factory()->create();
    $otp = OtpCode::factory()->for($user)->create([
        'channel' => 'sms',
        'code_hash' => Hash::make('111222'),
        'destination' => $user->phone,
    ]);
    $service = app(OtpService::class);

    $service->verify($user, OtpPurpose::VerifyAccount, '111222');

    expect($user->fresh()->phone_verified_at)->not->toBeNull();
    expect($user->fresh()->email_verified_at)->toBeNull();
});

/**
 * T04 security review M1 [Medium] — "mã gắn với đích" (data-model §3.1):
 * mã có `destination` KHÁC với liên hệ HIỆN TẠI của user (vd do race với
 * `sendAfterContactChange()`, hoặc do lỗi dữ liệu khác) KHÔNG được coi là
 * hợp lệ, dù đúng mã và mã chưa hết hạn/chưa bị huỷ — người giữ mã ở địa chỉ
 * CŨ không được phép xác thực cho địa chỉ MỚI mà họ không sở hữu.
 */
test('verify() tu choi ma co destination khac voi lien he HIEN TAI cua user (M1)', function () {
    $user = User::factory()->create(['email' => 'victim-new-email@example.com']);
    // Mô phỏng mã được tạo khi user còn ở địa chỉ CŨ (đã đổi liên hệ sau đó
    // nhưng mã lại chưa được huỷ kịp — race ở M2, đã vá) hoặc dữ liệu bẩn.
    $otp = OtpCode::factory()->for($user)->create([
        'destination' => 'attacker-old-email@example.com',
        'code_hash' => Hash::make('654321'),
    ]);
    $service = app(OtpService::class);

    $error = vvVerifyFails($service, $user, '654321');

    expect($error)->not->toBeNull();
    expect($user->fresh()->email_verified_at)->toBeNull();
    // Mã vẫn bị "tiêu thụ" (không dùng lại được) dù bị từ chối ở bước so
    // đích — không mở thêm oracle nào để thử lại vô hạn với cùng 1 mã.
    expect($otp->fresh()->consumed_at)->not->toBeNull();
});

test('verify() destination khac hoa/thuong (khong dau, khong lo hoa thuong) van khop duoc', function () {
    $user = User::factory()->create(['email' => 'hocsinh@example.com']);
    $otp = OtpCode::factory()->for($user)->create([
        'destination' => 'HocSinh@Example.com',
        'code_hash' => Hash::make('654321'),
    ]);
    $service = app(OtpService::class);

    $service->verify($user, OtpPurpose::VerifyAccount, '654321');

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

/**
 * T04 security review M2 [Medium] — `send()`/`sendAfterContactChange()` PHẢI
 * khoá hàng `users` (`SELECT ... FOR UPDATE`) TRƯỚC khi đếm trần, để 2
 * transaction đồng thời cho CÙNG 1 user không thể cùng đọc thấy "chưa vượt
 * trần" (check-then-act). Xác minh bằng query log THẬT (không chỉ đọc code):
 * câu SQL khoá đúng bảng `users`, đúng `id` của user, và chạy TRƯỚC câu đếm
 * `otp_codes`.
 *
 * (Không dựng được test đa tiến trình/đa kết nối THẬT trong môi trường này:
 * Pest bọc mỗi test trong 1 transaction CHƯA commit trên connection mặc định
 * — RefreshDatabase — nên 1 tiến trình/connection khác sẽ KHÔNG thấy được
 * user vừa tạo trong test, trừ khi tắt hẳn RefreshDatabase cho riêng file này
 * và tự dọn dữ liệu thật trong DB test dùng chung `vitaminvui_testing_t04`
 * — rủi ro làm bẩn DB không đáng đánh đổi cho 1 test. `SELECT ... FOR UPDATE`
 * là cơ chế khoá hàng tiêu chuẩn của InnoDB — kiểm đúng câu lệnh này được
 * phát ra, đúng lúc, đúng bảng là bằng chứng kỹ thuật hợp lý cho tính đúng
 * đắn của cơ chế, không cần tái tạo race thật bằng nhiều tiến trình.)
 */
test('send() phat ra SELECT...FOR UPDATE tren bang users TRUOC khi dem tran (M2)', function () {
    $user = User::factory()->create();
    $service = app(OtpService::class);

    DB::enableQueryLog();
    $service->send($user, OtpPurpose::VerifyAccount, 'email');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $lockQueryIndex = null;
    $countQueryIndex = null;

    foreach ($queries as $index => $query) {
        $sql = mb_strtolower((string) $query['query']);

        if ($lockQueryIndex === null && str_contains($sql, 'select') && str_contains($sql, 'from `users`') && str_contains($sql, 'for update')) {
            $lockQueryIndex = $index;
        }

        if ($countQueryIndex === null && str_contains($sql, 'select count') && str_contains($sql, 'from `otp_codes`')) {
            $countQueryIndex = $index;
        }
    }

    expect($lockQueryIndex)->not->toBeNull();
    expect($countQueryIndex)->not->toBeNull();
    expect($lockQueryIndex)->toBeLessThan($countQueryIndex);
});

/**
 * T04 security review M2 — bằng chứng InnoDB (không phải MyISAM — MyISAM
 * KHÔNG hỗ trợ khoá hàng, `lockForUpdate()` sẽ âm thầm là no-op) thật sự
 * chặn 1 transaction THỨ 2 cố khoá CÙNG 1 hàng: dùng 2 CONNECTION THẬT, ĐỘC
 * LẬP (không phải mô phỏng tuần tự) tới CÙNG database test — dữ liệu setup
 * ghi qua connection PHỤ (tự commit ngay, không phụ thuộc transaction bọc
 * ngoài của Pest trên connection mặc định) để cả 2 bên đều thấy được.
 */
test('SELECT...FOR UPDATE that su chan connection thu 2 (bang chung InnoDB that, khong mo phong) (M2)', function () {
    config(['database.connections.mysql_lock_probe' => config('database.connections.mysql')]);
    $probe = DB::connection('mysql_lock_probe');

    $userId = $probe->table('users')->insertGetId([
        'name' => 'Khoa thu nghiem M2',
        'email' => 'lock-probe-'.uniqid().'@example.com',
        'phone' => '09'.random_int(10000000, 99999999),
        'password' => bcrypt('password'),
        'role' => 'hoc_sinh',
        'status' => 'active',
        'grade_level' => 10,
        'date_of_birth' => '2000-01-01',
        'parent_consent_status' => 'not_required',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        // Connection PHỤ giữ khoá (mô phỏng transaction ĐẦU TIÊN của
        // `OtpService::send()` đang xử lý request thứ nhất, CHƯA commit).
        $probe->beginTransaction();
        $probe->table('users')->where('id', $userId)->lockForUpdate()->first();

        // Connection MẶC ĐỊNH (connection thật mà OtpService dùng) cố khoá
        // CÙNG hàng — giới hạn thời gian chờ khoá rất ngắn để test không treo:
        // nếu khoá KHÔNG có hiệu lực thật (vd bảng dùng engine không hỗ trợ
        // row-lock), câu này thành công NGAY LẬP TỨC thay vì bị từ chối.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $blockedException = null;

        try {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
        } catch (QueryException $e) {
            $blockedException = $e;
        }

        expect($blockedException)->not->toBeNull();
        expect(mb_strtolower($blockedException->getMessage()))->toContain('lock wait timeout');
    } finally {
        $probe->rollBack();
        $probe->table('users')->where('id', $userId)->delete();
        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    }
});
