<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\StudentSessionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * ADR-003 — test bắt buộc liệt kê ở cuối tài liệu ("Quy tắc cho Dev"). Mô
 * phỏng nhiều "thiết bị" trong CÙNG 1 hàm test Pest bằng cách tự tay luân
 * chuyển cookie phiên (`Set-Cookie` của response này → cookie gửi ở response
 * khác) — test client mặc định KHÔNG tự làm việc này cho `getJson()`/
 * `postJson()` (chỉ hoạt động khi bật `withCredentials()`, mặc định tắt).
 */
function vvMeUrl(): string
{
    return 'http://'.config('app.api_host').'/api/v1/auth/me';
}

/**
 * "Thiết bị mới" — 1 hàm test Pest dùng CHUNG 1 instance `test()`/`$this` (và
 * CHUNG 1 `Illuminate\Session\Store` — `session.store` là SINGLETON trong
 * container) cho mọi lệnh gọi HTTP rời rạc mô phỏng nhiều thiết bị, khác hẳn
 * trình duyệt/tiến trình thật. 3 thứ PHẢI dọn sạch để mô phỏng đúng 1 thiết
 * bị HOÀN TOÀN MỚI (thiếu 1 trong 3 đều làm phiên "cũ" sống lại nhầm):
 * 1. `Store::loadSession()` (chạy mỗi request thật qua `StartSession`) chỉ
 *    `array_replace($this->attributes, $this->readFromHandler())` — TRỘN vào
 *    dữ liệu SẴN CÓ, không reset trước. Đổi sang session id mới (chưa có
 *    trong handler) vẫn giữ nguyên dữ liệu đăng nhập CŨ trong bộ nhớ nếu
 *    không tự `flush()` — không liên quan gì tới cookie gửi lên.
 * 2. `Illuminate\Auth\SessionGuard`/`RequestGuard` cache `$this->user` NGAY
 *    TRÊN INSTANCE guard (không tái tạo giữa các lệnh gọi) — `forgetGuards()`
 *    buộc lần `Auth::guard()` kế tiếp dựng lại guard mới, đọc lại từ session.
 * 3. Cookie phiên gửi lên (nếu request trước đó có gọi `withUnencryptedCookie`)
 *    vẫn còn trong `$this->unencryptedCookies` — gán 1 giá trị KHÔNG giải mã
 *    được để `App\Http\Middleware\EncryptCookies` coi như không có cookie nào.
 *
 * (T28 review, đợt sửa theo `docs/reviews/review-T05.md`) — dùng
 * `app()->resolved('session.store')`, KHÔNG dùng `app()->bound(...)`:
 * `bound()` chỉ hỏi container có BIẾT CÁCH tạo ra binding đó không (luôn
 * `true` ngay từ lúc `SessionServiceProvider::register()` chạy, TRƯỚC MỌI
 * request), nên dùng nó làm điều kiện sẽ luôn ép container RESOLVE (dựng)
 * `session.store` NGAY LẦN GỌI ĐẦU TIÊN của helper này trong 1 test — tức là
 * TRƯỚC KHI request thật đầu tiên chạy qua `ConfigureHostContext`/`StartSession`
 * (nơi quyết định tên cookie đúng theo host: `vv_session` ở host api,
 * `vv_admin_session` ở host admin-api). `Store` object bị cố định tên cookie
 * ngay lúc dựng — resolve sớm với cấu hình chưa đúng ngữ cảnh sẽ khoá cứng
 * SAI tên cho suốt phần đời còn lại của Application trong test đó.
 * `resolved()` chỉ trả `true` SAU KHI đã có ít nhất 1 lần thực sự resolve
 * (ở đây là do chính request đăng nhập THẬT đầu tiên trong test kích hoạt,
 * qua `AuthManager::createSessionDriver()`) — lần gọi ĐẦU TIÊN của helper
 * trong 1 test luôn thấy `resolved() === false` (không có gì để dọn, đúng ý:
 * thiết bị đầu tiên không cần "dọn" gì) và KHÔNG tự ý resolve sớm.
 */
function vvLoginNewDevice(string $login, string $password, ?string $deviceId = null): TestResponse
{
    if (app()->resolved('session.store')) {
        app('session.store')->flush();
    }

    Auth::forgetGuards();

    test()->withUnencryptedCookie((string) config('session.cookie'), 'khong-phai-cookie-phien-that')
        ->withCredentials();

    return test()->postJson('http://'.config('app.api_host').'/api/v1/auth/login', [
        'login' => $login,
        'password' => $password,
    ], array_filter([
        'Origin' => config('app.frontend_url'),
        'X-Device-Id' => $deviceId,
    ], fn ($v) => $v !== null));
}

/**
 * @return array{0: string, 1: string} [tên cookie, giá trị (đã mã hoá, y hệt
 *                                     header `Set-Cookie` — dùng lại NGUYÊN
 *                                     VĂN, không mã hoá lại lần 2)]
 */
function vvSessionCookie(TestResponse $response): array
{
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull();

    return [$cookie->getName(), (string) $cookie->getValue()];
}

/**
 * Gọi tiếp 1 request bằng ĐÚNG phiên (`Set-Cookie`) của response đăng nhập
 * trước đó — mô phỏng "thiết bị" đã đăng nhập gọi request tiếp theo.
 */
function vvCallAsDevice(TestResponse $loginResponse, string $method, string $url, ?string $deviceId = null): TestResponse
{
    // Cùng lý do với `vvLoginNewDevice()` (xem chú thích ở đó, kể cả vì sao
    // dùng `resolved()` thay vì `bound()`) — mỗi "thiết bị" gọi tiếp phải
    // buộc guard/session đọc lại từ ĐÚNG cookie phiên của CHÍNH NÓ, không
    // dùng lại dữ liệu đã merge/user đã resolve (cache trên `Store`/instance
    // guard) từ lệnh gọi trước đó của 1 thiết bị KHÁC.
    if (app()->resolved('session.store')) {
        app('session.store')->flush();
    }

    Auth::forgetGuards();

    [$name, $value] = vvSessionCookie($loginResponse);

    $headers = array_filter([
        'Origin' => config('app.frontend_url'),
        'X-Device-Id' => $deviceId,
    ], fn ($v) => $v !== null);

    return test()->withUnencryptedCookie($name, $value)
        ->withCredentials()
        ->json($method, $url, [], $headers);
}

/**
 * (T28 review, đợt sửa theo `docs/reviews/review-T05.md`) — xác nhận trực
 * tiếp bằng response THẬT (không chỉ tin `config('session.cookie')` có thể
 * đã bị 1 lần resolve sớm/sai làm lệch): cookie phiên học sinh trên host api
 * PHẢI có tên literal `vv_session` (ADR-004 §2.2 — `.env`/`.env.example`
 * `SESSION_COOKIE=vv_session`), không phải `vv_admin_session` hay giá trị bị
 * "khoá cứng" từ 1 lần resolve `session.store` không đúng ngữ cảnh.
 */
test('cookie phien dang nhap hoc sinh dung ten literal vv_session (khong bi lech host)', function () {
    User::factory()->create(['email' => 'ten-cookie@example.com', 'password' => Hash::make('matkhau123')]);

    $login = vvLoginNewDevice('ten-cookie@example.com', 'matkhau123');
    $login->assertOk();

    [$cookieName] = vvSessionCookie($login);

    expect($cookieName)->toBe('vv_session');
    expect($cookieName)->toBe(config('session.cookie'));
});

/**
 * (T28 review, đợt sửa theo `docs/reviews/review-T05.md`) — MỌI test khác
 * trong file này đi qua đường "phiên cũ đã bị xoá khỏi store" (trường hợp
 * THƯỜNG GẶP theo ADR-003 — `bind()` của thiết bị mới huỷ session cũ NGAY
 * trong cùng request), nên `auth:sanctum` tự ném `AuthenticationException`
 * TRƯỚC KHI kịp chạy tới `EnforceSingleStudentSession` — 401 SESSION_REPLACED
 * ở các test đó thực chất đến từ `ApiExceptionRenderer` đọc tombstone, KHÔNG
 * phải middleware. Đã kiểm bằng mutation test thủ công (tạm return $next()
 * ngay đầu `EnforceSingleStudentSession::handle()`) — toàn bộ 19 test còn lại
 * VẪN PASS, nghĩa là middleware này chưa từng được test nào ở trên "bắt lỗi"
 * nếu bị vô hiệu hoá.
 *
 * Test này dựng ĐÚNG "cửa sổ race" mà middleware sinh ra để xử lý (ADR-003 —
 * "Xử lý trường hợp phiên cũ CÒN dữ liệu trong session store"): đổi thẳng
 * `current_session_id`/`current_device_id` trong DB (mô phỏng 1 thiết bị khác
 * đã bind() xong) mà KHÔNG đụng gì tới session store của A — session A vẫn
 * còn nguyên nên `auth:sanctum` xác thực được bình thường, buộc
 * `EnforceSingleStudentSession` phải là nơi từ chối request.
 */
test('EnforceSingleStudentSession tu choi khi phien cu VAN CON trong store nhung current_session_id da doi (cua so race)', function () {
    $user = User::factory()->create(['email' => 'race-window@example.com', 'password' => Hash::make('matkhau123')]);

    $loginA = vvLoginNewDevice('race-window@example.com', 'matkhau123', 'aaaaaaaa-7000-4700-8700-700000000000');
    $loginA->assertOk();

    // Đổi THẲNG DB, không gọi bind()/qua HTTP nào — session A trong store
    // (array handler) không hề bị đụng tới.
    $user->forceFill([
        'current_session_id' => 'phien-cua-thiet-bi-khac-gia-lap',
        'current_device_id' => 'bbbbbbbb-7001-4701-8701-700100000001',
    ])->save();

    $meFromA = vvCallAsDevice($loginA, 'GET', vvMeUrl(), 'aaaaaaaa-7000-4700-8700-700000000000');

    $meFromA->assertStatus(401);
    $meFromA->assertJson(['code' => 'SESSION_REPLACED']);
});

/**
 * Cùng kịch bản "cửa sổ race" ở trên, nhưng `X-Device-Id` của A khớp
 * `current_device_id` MỚI trong DB (đăng nhập lại đúng trên CHÍNH thiết bị A,
 * chưa kịp huỷ session cũ) — middleware phải trả `SESSION_EXPIRED`, không
 * phải `SESSION_REPLACED` (không báo nhầm "thiết bị khác").
 */
test('EnforceSingleStudentSession tra SESSION_EXPIRED (khong phai SESSION_REPLACED) khi cung device_id, phien cu van con trong store', function () {
    $user = User::factory()->create(['email' => 'race-window-cung-thietbi@example.com', 'password' => Hash::make('matkhau123')]);
    $deviceId = 'cccccccc-7002-4702-8702-700200000002';

    $loginA = vvLoginNewDevice('race-window-cung-thietbi@example.com', 'matkhau123', $deviceId);
    $loginA->assertOk();

    $user->forceFill([
        'current_session_id' => 'phien-moi-cung-thiet-bi-gia-lap',
        'current_device_id' => $deviceId,
    ])->save();

    $meFromA = vvCallAsDevice($loginA, 'GET', vvMeUrl(), $deviceId);

    $meFromA->assertStatus(401);
    $meFromA->assertJson(['code' => 'SESSION_EXPIRED']);
});

test('A dang nhap roi B dang nhap cung tai khoan: A goi /auth/me nhan 401 SESSION_REPLACED', function () {
    $user = User::factory()->create(['email' => 'a-b@example.com', 'password' => Hash::make('matkhau123')]);

    $loginA = vvLoginNewDevice('a-b@example.com', 'matkhau123', 'aaaaaaaa-1111-4111-8111-111111111111');
    $loginA->assertOk();

    $loginB = vvLoginNewDevice('a-b@example.com', 'matkhau123', 'bbbbbbbb-2222-4222-8222-222222222222');
    $loginB->assertOk();

    $meFromA = vvCallAsDevice($loginA, 'GET', vvMeUrl(), 'aaaaaaaa-1111-4111-8111-111111111111');

    $meFromA->assertStatus(401);
    $meFromA->assertJson(['code' => 'SESSION_REPLACED']);

    // B vẫn là phiên hợp lệ duy nhất.
    $meFromB = vvCallAsDevice($loginB, 'GET', vvMeUrl(), 'bbbbbbbb-2222-4222-8222-222222222222');
    $meFromB->assertOk();
    $meFromB->assertJson(['id' => $user->id]);
});

/**
 * Test bắt buộc quan trọng nhất của ADR-003 (S11) — trước đây một thiết kế
 * lỗi khiến phiên A "sống lại" sau khi B chủ động đăng xuất (cột
 * `current_session_id` bị đưa về giá trị coi là "trống"). Ở đây B đăng xuất
 * xong, A gọi lại `/auth/me` VẪN phải nhận 401 — không được coi A là phiên
 * hợp lệ trở lại.
 */
test('A dang nhap, B dang nhap, B dang xuat: A goi /auth/me van phai 401 (khong song lai)', function () {
    User::factory()->create(['email' => 'khong-song-lai@example.com', 'password' => Hash::make('matkhau123')]);

    $loginA = vvLoginNewDevice('khong-song-lai@example.com', 'matkhau123', 'aaaaaaaa-3333-4333-8333-333333333333');
    $loginA->assertOk();

    $loginB = vvLoginNewDevice('khong-song-lai@example.com', 'matkhau123', 'cccccccc-4444-4444-8444-444444444444');
    $loginB->assertOk();

    $logoutB = vvCallAsDevice($loginB, 'POST', 'http://'.config('app.api_host').'/api/v1/auth/logout', 'cccccccc-4444-4444-8444-444444444444');
    $logoutB->assertNoContent();

    $meFromA = vvCallAsDevice($loginA, 'GET', vvMeUrl(), 'aaaaaaaa-3333-4333-8333-333333333333');
    $meFromA->assertStatus(401);

    // B đã tự đăng xuất — request tiếp theo của B cũng phải 401 (không phải
    // effect phụ của việc A còn "sống", mà vì B đã chủ động đăng xuất).
    $meFromB = vvCallAsDevice($loginB, 'GET', vvMeUrl(), 'cccccccc-4444-4444-8444-444444444444');
    $meFromB->assertStatus(401);
});

test('dang nhap 2 lan tren CUNG device_id: phien dau nhan SESSION_EXPIRED, khong bao nham thiet bi khac', function () {
    User::factory()->create(['email' => 'cung-thiet-bi@example.com', 'password' => Hash::make('matkhau123')]);
    $deviceId = 'dddddddd-5555-4555-8555-555555555555';

    $firstLogin = vvLoginNewDevice('cung-thiet-bi@example.com', 'matkhau123', $deviceId);
    $firstLogin->assertOk();

    $secondLogin = vvLoginNewDevice('cung-thiet-bi@example.com', 'matkhau123', $deviceId);
    $secondLogin->assertOk();

    $meFromFirst = vvCallAsDevice($firstLogin, 'GET', vvMeUrl(), $deviceId);

    $meFromFirst->assertStatus(401);
    $meFromFirst->assertJson(['code' => 'SESSION_EXPIRED']);
});

test('doi mat khau: phien khac nhan SESSION_REVOKED (chuan bi cho T27)', function () {
    $user = User::factory()->create(['email' => 'doi-mk@example.com', 'password' => Hash::make('matkhau123')]);

    $login = vvLoginNewDevice('doi-mk@example.com', 'matkhau123', 'eeeeeeee-6666-4666-8666-666666666666');
    $login->assertOk();

    // T27 (quên/đổi mật khẩu) ngoài phạm vi T05 — gọi thẳng cơ chế thu hồi
    // phiên đã chuẩn bị sẵn, đúng như ADR-003 mô tả cho luồng đổi mật khẩu.
    app(StudentSessionService::class)->revokeForPasswordChange($user->fresh());

    $me = vvCallAsDevice($login, 'GET', vvMeUrl(), 'eeeeeeee-6666-4666-8666-666666666666');

    $me->assertStatus(401);
    $me->assertJson(['code' => 'SESSION_REVOKED']);
});

/**
 * BR4/US-014 — Giáo Viên/Quản lý trang/Admin KHÔNG bị giới hạn 1 thiết
 * bị/1 phiên. Chưa có endpoint đăng nhập quản trị (T28) để dựng qua HTTP thật
 * — kiểm bằng cách xác nhận `student.single_session` cho qua (không tự trả
 * 401) với vai trò khác `hoc_sinh` dù `current_session_id` không khớp: request
 * đi tiếp tới `role:hoc_sinh` (chạy SAU) và bị chặn ở ĐÓ (403), KHÔNG bị chặn
 * bởi `student.single_session` (401 SESSION_REPLACED) — nếu
 * `EnforceSingleStudentSession` áp nhầm cho giáo viên, response sẽ là 401
 * thay vì 403.
 */
test('giao vien khong bi ap 1 thiet bi/1 phien (BR4)', function () {
    $teacher = User::factory()->teacher()->create();
    $teacher->forceFill(['current_session_id' => 'phien-khong-khop-that-su'])->save();

    $response = test()->actingAs($teacher)->getJson(vvMeUrl(), [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertStatus(403);
    $response->assertJson(['code' => 'FORBIDDEN']);
});

test('X-Device-Id sai dinh dang bi bo qua, khong lam sap request', function () {
    User::factory()->create(['email' => 'device-id-sai@example.com', 'password' => Hash::make('matkhau123')]);

    $loginA = vvLoginNewDevice('device-id-sai@example.com', 'matkhau123', 'khong-phai-uuid');
    $loginA->assertOk();

    $loginB = vvLoginNewDevice('device-id-sai@example.com', 'matkhau123', null);
    $loginB->assertOk();

    // Header X-Device-Id sai định dạng ở request SAU đăng nhập cũng phải bị
    // bỏ qua an toàn (không 500), coi như không có → SESSION_REPLACED (không
    // thể là "cùng thiết bị" vì không có device_id hợp lệ nào để so khớp).
    $meFromA = vvCallAsDevice($loginA, 'GET', vvMeUrl(), 'van-khong-phai-uuid');

    $meFromA->assertStatus(401);
    $meFromA->assertJson(['code' => 'SESSION_REPLACED']);
});

test('dang xuat khong anh huong phien cua thiet bi khac vua dang nhap dung luc (dieu kien UPDATE, ADR-003)', function () {
    $user = User::factory()->create(['email' => 'logout-khong-dung-de@example.com', 'password' => Hash::make('matkhau123')]);

    $loginA = vvLoginNewDevice('logout-khong-dung-de@example.com', 'matkhau123', 'ffffffff-7777-4777-8777-777777777777');
    $loginA->assertOk();

    // B đăng nhập, thay thế A trong DB (current_session_id đổi sang B).
    $loginB = vvLoginNewDevice('logout-khong-dung-de@example.com', 'matkhau123', 'aaaaaaaa-8888-4888-8888-888888888888');
    $loginB->assertOk();

    // A (đã bị thay thế) gọi đăng xuất — UPDATE có điều kiện phải KHÔNG khớp
    // (current_session_id trong DB giờ là của B), nên không được đụng gì.
    $logoutA = vvCallAsDevice($loginA, 'POST', 'http://'.config('app.api_host').'/api/v1/auth/logout', 'ffffffff-7777-4777-8777-777777777777');

    // Route logout chỉ cần auth:sanctum — phiên A đã bị `bind()` của B xoá
    // khỏi store nên `auth:sanctum` ném AuthenticationException trước khi
    // chạm được `LoginController::destroy()`.
    $logoutA->assertStatus(401);

    // B vẫn còn nguyên phiên hợp lệ.
    $meFromB = vvCallAsDevice($loginB, 'GET', vvMeUrl(), 'aaaaaaaa-8888-4888-8888-888888888888');
    $meFromB->assertOk();
    $meFromB->assertJson(['id' => $user->id]);
});

test('dang ky tai khoan moi cung duoc bind phien (khong lo current_session_id trong response)', function () {
    $response = test()->postJson('http://'.config('app.api_host').'/api/v1/auth/register', [
        'name' => 'Học Sinh T05',
        'date_of_birth' => now()->subYears(20)->toDateString(),
        'email' => 'dangky-t05@example.com',
        'phone' => '0912340005',
        'grade_level' => 10,
        'password' => 'matkhau123',
        'password_confirmation' => 'matkhau123',
        'accept_terms' => true,
        'accept_privacy' => true,
        'captcha_token' => 'test-token',
    ], [
        'Origin' => config('app.frontend_url'),
    ]);

    $response->assertCreated();
    $response->assertJsonMissing(['current_session_id']);

    $user = User::query()->where('email', 'dangky-t05@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Student);
    expect($user->current_session_id)->not->toBeNull();
    expect($user->current_session_id)->not->toBe('logged_out');

    // Session vừa bind() dùng ngay được cho /auth/me (không cần đăng nhập lại).
    $me = vvCallAsDevice($response, 'GET', vvMeUrl());
    $me->assertOk();
    $me->assertJson(['id' => $user->id]);
});
