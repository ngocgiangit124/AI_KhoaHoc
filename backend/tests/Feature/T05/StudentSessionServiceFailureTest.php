<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * R1 (review docs/reviews/review-T05.md) — ADR-003 bước 4/5: 2 nhánh lỗi
 * hạ tầng (Redis/DB) trong `StudentSessionService::bind()` chỉ đọc code là
 * không đủ tin — đây chính là nhánh chỉ chạy lúc hạ tầng có sự cố thật (dễ
 * xảy ra traffic bất thường nhất), và bước tombstone/destroy NUỐT lỗi có chủ
 * đích (không rethrow) nên càng cần test xác nhận hành vi thật.
 *
 * Dùng `Cache::shouldReceive()`/`DB::shouldReceive()` (KHÔNG `partialMock()`):
 * đã kiểm — `Illuminate\Database\Eloquent\Model::$resolver` (dùng cho MỌI
 * truy vấn Eloquent, kể cả `User::query()` trong chính `bind()`) được gán 1
 * LẦN lúc boot ứng dụng (`DatabaseServiceProvider::boot()`), giữ tham chiếu
 * tới `DatabaseManager` THẬT — swap facade `DB`/`Cache` (chỉ đổi
 * `Facade::$resolvedInstance` + container binding) SAU thời điểm boot đó
 * không ảnh hưởng các câu truy vấn Eloquent thật đang chạy trong cùng
 * request, chỉ chặn đúng lệnh gọi facade tường minh trong `bind()`.
 */
test('loi Redis/cache khi ghi tombstone khong lam hong dang nhap, phien cu van bi chan sau do (khong song lai)', function () {
    User::factory()->create(['email' => 'redis-loi-t05@example.com', 'password' => Hash::make('matkhau123')]);

    $loginA = vvLoginNewDevice('redis-loi-t05@example.com', 'matkhau123', 'aaaaaaaa-9000-4900-8900-900000000000');
    $loginA->assertOk();

    // Mô phỏng Redis/cache tạm gián đoạn ĐÚNG lúc `invalidatePrevious()` ghi
    // tombstone (`SessionTombstoneStore::put()`). `get()` cũng phải được khai
    // (dù không lỗi) — mock cứng facade chặn MỌI method chưa khai, và request
    // `/auth/me` bên dưới sẽ gọi `SessionTombstoneStore::get()` qua renderer.
    Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('redis down (test)'));
    Cache::shouldReceive('get')->andReturn(null);

    // B đăng nhập — PHẢI vẫn thành công (lỗi tombstone không được làm hỏng
    // đăng nhập của thiết bị mới, đúng ADR-003 bước 5: "chỉ mất lý do hiển
    // thị, không mất an toàn").
    $loginB = vvLoginNewDevice('redis-loi-t05@example.com', 'matkhau123', 'bbbbbbbb-9001-4901-8901-900100000001');
    $loginB->assertOk();

    // Phiên A vẫn bị chặn ở request kế tiếp — KHÔNG sống lại (S11) — dù không
    // ghi được tombstone thì đây là 401 UNAUTHENTICATED (không có lý do cụ
    // thể để hiển thị), khác `SESSION_REPLACED` bình thường, nhưng VẪN LÀ
    // 401: `Session::getHandler()->destroy($old)` (try/catch RIÊNG, không bị
    // mock ảnh hưởng) vẫn chạy thật, xoá phiên A khỏi store.
    $meFromA = vvCallAsDevice($loginA, 'GET', vvMeUrl(), 'aaaaaaaa-9000-4900-8900-900000000000');
    $meFromA->assertStatus(401);
    $meFromA->assertJson(['code' => 'UNAUTHENTICATED']);
});

test('loi DB khi ghi current_session_id: khong tao 2 phien hop le, phien moi bi teardown ngay', function () {
    $user = User::factory()->create(['email' => 'db-loi-t05@example.com', 'password' => Hash::make('matkhau123')]);

    // Mô phỏng DB lỗi (vd deadlock/mất kết nối) NGAY tại bước ghi
    // `current_session_id` mới — `DB::transaction()` không bao giờ chạy xong
    // callback, nên `users.current_session_id` của tài khoản này (vẫn đang
    // `null` — tài khoản mới tạo, chưa từng đăng nhập) không hề bị đổi.
    DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('db down (test)'));

    $login = vvLoginNewDevice('db-loi-t05@example.com', 'matkhau123', 'cccccccc-9002-4902-8902-900200000002');

    $login->assertStatus(500);

    // `bind()` đã `Auth::login($user)` + `session()->regenerate()` TRƯỚC khi
    // chạm `DB::transaction()` — catch phải teardown NGAY: guard không còn
    // coi là đã đăng nhập (kiểm trực tiếp trên guard, cùng cách
    // `tests/Feature/T03/LoginTest.php` đã làm cho logout — đáng tin hơn là
    // mô phỏng thêm 1 request rời rạc).
    expect(Auth::guard('web')->check())->toBeFalse();

    // Không có "2 phiên cùng hợp lệ": current_session_id trong DB giữ nguyên
    // giá trị TRƯỚC khi đăng nhập (transaction không hề chạm DB — rollback
    // tự nhiên vì closure chưa từng thực thi).
    expect($user->fresh()->current_session_id)->toBeNull();

    // Response lỗi VẪN có Set-Cookie phiên (Sanctum bọc route middleware bằng
    // `Illuminate\Routing\Pipeline`, có `prepareDestination()` bắt exception
    // của controller và render NGAY tại đó — nên `StartSession` vẫn coi như
    // pipeline trả về response BÌNH THƯỜNG, chạy tiếp bước gắn cookie), NHƯNG
    // đây là cookie của phiên đã bị `session()->invalidate()` làm rỗng/đổi id
    // trong chính catch của `bind()` — dùng lại cookie này KHÔNG đăng nhập
    // được (401), khác hẳn cookie thật của 1 lần bind() thành công.
    $me = vvCallAsDevice($login, 'GET', vvMeUrl());
    $me->assertStatus(401);
});
