<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * T28, R2 (review-T28.md, [SHOULD]) — test HTTP THẬT (2 cookie phiên riêng
 * biệt cho 2 "thiết bị", KHÔNG dùng `actingAs()`/`withSession()`) chứng minh
 * "đổi mật khẩu huỷ phiên khác" (`Laravel\Sanctum\Http\Middleware\AuthenticateSession`,
 * alias `staff.session`) — bullet test tường minh trong tasks.md T28.
 *
 * Kỹ thuật luân chuyển cookie giữa các lệnh gọi HTTP rời rạc trong CÙNG 1 hàm
 * test Pest tham khảo `tests/Feature/T05/SingleStudentSessionTest.php` (ADR-003,
 * nhánh `claude/zen-dirac-fmucf7-t05`): `session.store` và guard là SINGLETON
 * trong container test, nên phải tự `flush()`/`forgetGuards()` trước mỗi lệnh
 * gọi mô phỏng "thiết bị khác", rồi gắn ĐÚNG cookie phiên (`Set-Cookie`, chưa
 * giải mã lại — `withUnencryptedCookie` + `withCredentials`) của "thiết bị"
 * muốn mô phỏng.
 *
 * Khác biệt so với bản gốc (host api — cookie mặc định trùng luôn
 * `config('session.cookie')`): dùng `app()->resolved('session.store')`
 * (KHÔNG phải `bound()`, vốn luôn `true` vì `SessionServiceProvider` luôn
 * đăng ký sẵn binding) để chỉ `flush()` khi `session.store` ĐÃ được dựng bởi
 * 1 request THẬT trước đó trong cùng test — nếu gọi `app('session.store')`
 * sớm hơn (trước request admin đầu tiên), `SessionManager` sẽ dựng `Store`
 * ngay với tên cookie MẶC ĐỊNH (`vv_session`, của học sinh — cấu hình lúc đó
 * chưa bị `ConfigureHostContext` đổi sang `vv_admin_session`) và CỐ ĐỊNH tên
 * đó suốt phần đời còn lại của test, khiến mọi `Set-Cookie` thật sự trả về
 * (`vv_admin_session`, do middleware `StartSession` tự đọc `config('session.cookie')`
 * MỚI khi dựng `Store` LẦN ĐẦU trong request đó) không bao giờ khớp — đã tự
 * bắt lỗi này bằng cách chạy thử và log cookie thật trả về trước khi sửa.
 *
 * Dùng tài khoản Giáo Viên (không bắt buộc MFA) để tách biệt hẳn với luồng
 * MFA (đã có test riêng ở `StaffMfaTest.php`/`StaffPasswordTest.php`) — mục
 * tiêu DUY NHẤT của test này là cơ chế `staff.session`.
 */
function vvAdminLoginNewDevice(string $login, string $password): TestResponse
{
    if (app()->resolved('session.store')) {
        app('session.store')->flush();
    }

    Auth::forgetGuards();

    test()->withUnencryptedCookie((string) config('session.admin_cookie'), 'khong-phai-cookie-phien-that')
        ->withCredentials();

    return test()->postJson(vvAdminUrl('/admin/auth/login'), [
        'login' => $login,
        'password' => $password,
    ], vvAdminHeaders());
}

/**
 * @return array{0: string, 1: string} [tên cookie, giá trị (đã mã hoá, y hệt
 *                                     header `Set-Cookie` — dùng lại NGUYÊN
 *                                     VĂN, không mã hoá lại lần 2)]
 */
function vvAdminSessionCookie(TestResponse $response): array
{
    // Dùng `session.admin_cookie` (khoá TĨNH, không bị `ConfigureHostContext`
    // ghi đè) chứ KHÔNG dùng `session.cookie` (khoá BỊ ghi đè theo từng
    // request — xem giải thích `resolved()` ở đầu file) để so tên cookie
    // trong response — đáng tin cậy bất kể `Store` đã được dựng từ lúc nào.
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.admin_cookie'));

    expect($cookie)->not->toBeNull();

    return [$cookie->getName(), (string) $cookie->getValue()];
}

/**
 * Gọi tiếp 1 request bằng ĐÚNG phiên (`Set-Cookie`) của response đăng nhập
 * trước đó — mô phỏng "thiết bị" đã đăng nhập gọi request tiếp theo.
 */
function vvCallAsAdminDevice(TestResponse $loginResponse, string $method, string $url, array $payload = []): TestResponse
{
    if (app()->resolved('session.store')) {
        app('session.store')->flush();
    }

    Auth::forgetGuards();

    [$name, $value] = vvAdminSessionCookie($loginResponse);

    return test()->withUnencryptedCookie($name, $value)
        ->withCredentials()
        ->json($method, $url, $payload, vvAdminHeaders());
}

test('doi mat khau o thiet bi A huy phien cua thiet bi B (HTTP that, 2 cookie rieng)', function () {
    $teacher = User::factory()->teacher()->create(['password' => Hash::make('matkhaucu123')]);

    $deviceA = vvAdminLoginNewDevice($teacher->email, 'matkhaucu123');
    $deviceA->assertOk();

    $deviceB = vvAdminLoginNewDevice($teacher->email, 'matkhaucu123');
    $deviceB->assertOk();

    // Thiet bi B dang con hop le TRUOC khi A doi mat khau.
    vvCallAsAdminDevice($deviceB, 'GET', vvAdminUrl('/admin/auth/me'))->assertOk();

    // Thiet bi A doi mat khau.
    $changePassword = vvCallAsAdminDevice($deviceA, 'PUT', vvAdminUrl('/admin/auth/password'), [
        'current_password' => 'matkhaucu123',
        'password' => 'matkhaumoi456',
        'password_confirmation' => 'matkhaumoi456',
    ]);
    $changePassword->assertOk();

    // Thiet bi B (session cu, con giu hash mat khau CU trong session) goi
    // tiep 1 route staff bat ky -> Laravel\Sanctum\Http\Middleware\AuthenticateSession
    // phat hien lech hash -> tu dang xuat -> 401.
    $meFromB = vvCallAsAdminDevice($deviceB, 'GET', vvAdminUrl('/admin/auth/me'));
    $meFromB->assertStatus(401);

    // Thiet bi A (vua doi mat khau, session KHONG doi ID) van dung binh
    // thuong voi CHINH cookie da dang nhap tu dau.
    $meFromA = vvCallAsAdminDevice($deviceA, 'GET', vvAdminUrl('/admin/auth/me'));
    $meFromA->assertOk();
    $meFromA->assertJson(['id' => $teacher->id]);
});
