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
 */
function vvLoginNewDevice(string $login, string $password, ?string $deviceId = null): TestResponse
{
    if (app()->bound('session.store')) {
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
    // Cùng lý do với `vvLoginNewDevice()` (xem chú thích ở đó) — mỗi "thiết
    // bị" gọi tiếp phải buộc guard/session đọc lại từ ĐÚNG cookie phiên của
    // CHÍNH NÓ, không dùng lại dữ liệu đã merge/user đã resolve (cache trên
    // `Store`/instance guard) từ lệnh gọi trước đó của 1 thiết bị KHÁC.
    if (app()->bound('session.store')) {
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
