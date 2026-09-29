# REVIEW: T05 — Một phiên học sinh (ADR-003) [SEC]

**Kết luận:** APPROVE (có 2 SHOULD nên xử lý trước khi coi task xong hẳn — không chặn merge)
**Phạm vi:** `git diff 48bb4ba...claude/zen-dirac-fmucf7-t05` (commit `c95fd24`, worktree `.claude/worktrees/t05`) · 14 file, +842/−28

Đối chiếu: `docs/architecture/tasks.md` (T05), `docs/adr/ADR-003-mot-thiet-bi-mot-phien-hoc-sinh.md`, US-014, `docs/architecture/api-contract.md` §1.2/§1.7/§2.2, security S11.

## Tổng quan
Đây là review bảo mật cuối cùng cho task này (`laravel-security` bị hoãn tới cuối dự án theo quyết định PO), nên tôi soi kỹ cả phần nghiệp vụ lẫn bảo mật, không chỉ style. Kết quả: triển khai bám rất sát ADR-003, đúng phương án D đã chọn, không có nhánh "nhận nuôi" (S11) ở bất kỳ đâu (cả `StudentSessionService` lẫn `EnforceSingleStudentSession`), race 2 thiết bị đăng nhập đồng thời được khoá đúng bằng `lockForUpdate()` bên trong `DB::transaction()` (theo đúng bài học T04: khoá hàng `users` trước, không gọi service ngoài trong transaction), và không có OTP/service ngoài nào bị gọi trong transaction. 7 test HTTP thật trong `SingleStudentSessionTest` dựng đúng luồng cookie/session thật (không dùng `actingAs()` cho phần lõi ADR-003) và phủ đủ 6 kịch bản bắt buộc + thêm 1 kịch bản UPDATE có điều kiện khi logout. Đã tự chạy lại độc lập: `pint --test` sạch, `phpstan analyse` 0 lỗi (208 file), `pest -c phpunit.t05.xml` **356 passed (1058 assertions)** — khớp báo cáo của Dev.

Hai điểm đáng chú ý không phải lỗi logic nhưng cần theo dõi: (1) chưa có test nào giả lập lỗi ở bước 4/5 của ADR-003 (DB/Redis lỗi khi bind), dù code đọc đúng theo tinh thần "fail-closed" của ADR; (2) `tests/TestCase.php::actingAs()` bị T05 và T28 cùng override — không xung đột logic ngay bây giờ (chạy song song, mỗi nhánh độc lập), nhưng SẼ xung đột thật khi gộp 2 nhánh (cùng khai lại 1 method), cần một bên chủ động hợp nhất.

## Phát hiện

### R1 [SHOULD] Thiếu test cho nhánh lỗi bind() (bước 4/5 ADR-003: DB/Redis lỗi)
- Vị trí: `backend/app/Services/Auth/StudentSessionService.php:39-96` (khối `try/catch` quanh `DB::transaction()` và `invalidatePrevious()`).
- Vấn đề: Dev tự nêu đúng đây là chỗ mình muốn được soi kỹ. Đọc code, hành vi ĐÚNG với ADR-003:
  - Nếu bước ghi `current_session_id` mới lỗi (transaction throw) → `catch` gọi `Auth::guard('web')->logout()` + `$request->session()->invalidate()` rồi `throw $e` lại (không nuốt exception, request trả lỗi thật, không có 2 phiên "sống" — vì phiên mới vừa tạo đã bị huỷ ngay, phiên cũ trong DB không hề bị đổi vì transaction rollback).
  - Nếu bước tombstone/`Session::getHandler()->destroy($old)` lỗi (Redis tạm gián đoạn) → `catch` riêng chỉ `report($e)` + `Log::warning(...)`, KHÔNG rethrow — đúng ý ADR-003 "chỉ mất lý do hiển thị, phiên cũ vẫn bị chặn ở middleware vì `current_session_id` đã đổi trong DB". Đây là quyết định đúng, nhưng chính vì nó nuốt lỗi có chủ đích, càng cần test xác nhận hành vi thật (không phải chỉ đọc code là đủ tin).
  - Hiện `grep` toàn bộ `tests/Feature/T05/` không thấy `mock`/`shouldReceive`/`partialMock` nào giả lập 2 nhánh lỗi này — 0 test chạm tới các dòng `catch` đó dù `phpstan`/coverage không báo vì chúng vẫn được include cú pháp.
- Rủi ro nếu bỏ qua: đây là đúng loại lỗi mà ADR-003 sinh ra để sửa (S11), và là nhánh code chỉ chạy khi hạ tầng (Redis/DB) có sự cố thật — tức là đúng lúc hệ thống dễ có traffic bất thường nhất. Một thay đổi tương lai vô tình đổi thứ tự 2 dòng trong `invalidatePrevious()` (vd gọi `Session::destroy()` trước `SessionTombstoneStore::put()`) sẽ không bị test nào bắt.
- Đề xuất: thêm 2 test trong `tests/Feature/T05/` (hoặc file mới `StudentSessionServiceFailureTest.php`):
  ```php
  test('tombstone/destroy loi khong lam hong request dang nhap, nhung phien cu van bi chan sau do', function () {
      // Fake Session store handler ném exception khi destroy() được gọi,
      // hoặc bind Cache::shouldReceive('put')->andThrow(...) qua Mockery.
      Cache::shouldReceive('put')->andThrow(new RuntimeException('redis down'));

      $user = User::factory()->create(['password' => Hash::make('matkhau123')]);
      // ... đăng nhập thiết bị A thật (không mock) ...
      // ... đăng nhập thiết bị B: response vẫn OK (login thành công) ...
      // ... nhưng /auth/me của A vẫn 401 (dù có thể là UNAUTHENTICATED thay vì SESSION_REPLACED, do tombstone lỗi) ...
  });

  test('loi DB khi ghi current_session_id: khong tao 2 phien hop le', function () {
      // Ép lockForUpdate/save() ném exception (vd mock User::query() hoặc
      // deadlock giả lập bằng savepoint), xác nhận response 500 VÀ phiên MỚI
      // vừa Auth::login() không còn dùng được (cookie trả về, nếu gọi lại, phải 401).
  });
  ```
  Không bắt buộc phải mock được chính xác Redis — miễn test chứng minh được 2 tính chất bất biến ADR-003 nêu: "không bao giờ 2 phiên cùng hợp lệ" và "lỗi ghi tombstone không làm sống lại phiên cũ".

### R2 [SHOULD] `tests/TestCase.php::actingAs()` bị T05 và T28 cùng override — cần hợp nhất khi gộp nhánh
- Vị trí: `backend/tests/TestCase.php` (T05: dòng cuối file, ~50 dòng mới) và cùng file trên nhánh `claude/zen-dirac-fmucf7-t28` (~30 dòng mới, độc lập).
- Vấn đề: Cả 2 nhánh viết lại đúng method `public function actingAs(...)` của `Tests\TestCase` — không tương thích khi merge theo kiểu "ai đến sau thắng":
  - T05: nếu `$user instanceof User && $user->role === UserRole::Student` → tự sinh session id, ghi `current_session_id`, gắn cookie phiên thật + `withCredentials()`.
  - T28: nếu `$user instanceof User` (MỌI role, kể cả `hoc_sinh`) → `withSession(['staff_login_at' => ..., 'staff_last_activity' => ..., 'staff_mfa_passed' => true])`.
  - Nếu merge chỉ giữ 1 trong 2 bản (conflict resolution kiểu "theirs"/"ours"), một nửa số test hiện có của nhánh còn lại sẽ hồi quy: test T28 (`StaffIdleTimeoutTest`, `StaffMfaTest`...) mất luôn phần seed session staff nếu bản T05 thắng; ngược lại toàn bộ test cũ dùng `actingAs()` cho vai trò `hoc_sinh` trên route nhóm `student` (T03/T04/T10, và cả `SingleStudentSessionTest`'s "giáo viên" case gọi `actingAs()` trực tiếp) sẽ lại nhận nhầm `SESSION_REPLACED` như trước khi có bản T05, nếu bản T28 thắng.
  - Về mặt kỹ thuật 2 bản không xung khắc nhau (điều kiện áp dụng khác nhau: role Student vs mọi User), chỉ cần hợp nhất thân hàm, không cần thiết kế lại.
- Đề xuất (cho người merge sau cùng, dù là T05 hay T28 merge trước, review lại `tests/TestCase.php` ở lần merge đó):
  ```php
  public function actingAs(UserContract $user, $guard = null)
  {
      if ($user instanceof User) {
          // T28 — seed sẵn phiên staff hợp lệ (không idle, đã MFA) cho MỌI actingAs(User).
          $this->withSession([
              'staff_login_at' => now()->timestamp,
              'staff_last_activity' => now()->timestamp,
              'staff_mfa_passed' => true,
          ]);

          // T05 — chỉ học sinh mới cần bind session/cookie thật cho student.single_session.
          if ($user->role === UserRole::Student) {
              $sessionId = Str::random(40);
              $user->forceFill(['current_session_id' => $sessionId])->save();
              $this->withCookie((string) config('session.cookie'), $sessionId)->withCredentials();
          }
      }

      return parent::actingAs($user, $guard);
  }
  ```
  Thêm 1 test kiến trúc/feature ngay sau khi merge, xác nhận CẢ HAI đường vẫn đi qua middleware đúng trong 1 lần chạy: `actingAs($student)` qua được `student.single_session`, VÀ `actingAs($staff)` qua được `staff.idle`/`staff.mfa_passed` — tránh trường hợp hợp nhất tay bị gõ nhầm điều kiện.
- Không tính là BLOCKER cho task T05 hiện tại vì 2 nhánh đang chạy song song trên worktree/DB riêng, đúng quy trình `docs/board.md` — chỉ là rủi ro CHẮC CHẮN xảy ra ở bước merge, nên ghi lại tường minh để người merge không bị bất ngờ.

### R3 [NIT] `User::$hidden` thiếu `current_device_id` (có `current_session_id` nhưng không có cột song song)
- Vị trí: `backend/app/Models/User.php:67-72`.
- Vấn đề: `current_session_id` được thêm vào `$hidden` (đúng, tránh lộ qua `toArray()`/`toJson()` mặc định), nhưng `current_device_id` (cột mới, cũng ghi bởi `StudentSessionService`) thì không. Hiện tại không lộ ra ngoài vì mọi Resource (`UserResource` đã kiểm — dùng allow-list tường minh, không có `current_device_id`) không show field lạ, nhưng nếu sau này có chỗ dùng `$user->toArray()`/`Log::info('...', $user->toArray())` trực tiếp (debug, log lỗi) thì UUID thiết bị của học sinh sẽ lộ không cố ý.
- Đề xuất: thêm `'current_device_id'` vào `$hidden` cho nhất quán, dù rủi ro thấp (chỉ là UUID do client tự sinh, không phải PII trực tiếp).

## Đối chiếu acceptance criteria / kịch bản bắt buộc ADR-003

| Kịch bản (ADR-003 "Test bắt buộc") | Code đáp ứng | Ghi chú |
|---|---|---|
| A đăng nhập → B đăng nhập → A nhận `SESSION_REPLACED` | Có | `SingleStudentSessionTest::'A dang nhap roi B dang nhap...'`, qua HTTP thật |
| A → B → B đăng xuất → A gọi `/auth/me` vẫn 401 (không sống lại, S11) | Có | Test riêng, đúng tên kịch bản trong ADR |
| Đăng nhập 2 lần cùng `device_id` → `SESSION_EXPIRED`, không báo nhầm thiết bị khác | Có | Test `'dang nhap 2 lan tren CUNG device_id...'` |
| Đổi mật khẩu → phiên khác nhận `SESSION_REVOKED` | Có (chuẩn bị cho T27) | Gọi thẳng `revokeForPasswordChange()` vì chưa có endpoint T27 — hợp lý, có ghi chú rõ |
| Giáo viên/admin đăng nhập 2 nơi → cả 2 cùng hoạt động (BR4) | Có | Test dùng `actingAs()` xác nhận không bị `student.single_session` chặn (403 từ `role:` middleware, không phải 401) |
| `X-Device-Id` sai định dạng bị bỏ qua, không làm sập request | Có | `DeviceIdTest` (unit) + 1 test HTTP tổng hợp |
| Renderer đọc tombstone từ cookie đã giải mã, phân biệt `replaced`/`password_changed`/`locked` | Có | `ApiExceptionRenderer::resolveAuthenticationException()`, đúng comment "cookie đã giải mã, không dùng `session()->getId()`" |
| Logout: UPDATE có điều kiện, không tạo tombstone, không ảnh hưởng phiên vừa thay thế nó | Có | Test `'dang xuat khong anh huong phien cua thiet bi khac vua dang nhap dung luc'` |
| Không có nhánh "nhận nuôi" (S11) | Có | Middleware + Service đều từ chối mọi giá trị khác `current_session_id`, kể cả `NULL`/`logged_out` |
| Race 2 thiết bị đăng nhập đồng thời | Có | `lockForUpdate()` trong `DB::transaction()`, khoá hàng `users` trước khi đọc `old` (đúng bài học T04) |
| Lỗi DB/Redis ở bước bind (bước 4/5 ADR-003) | Code đúng theo đọc, **chưa có test** | Xem R1 |
| Tombstone dùng `Cache::` mặc định, ép `array` ở test, `redis` ở production/local qua `.env` | Đúng | Verify: `.env`/`.env.example` production `CACHE_STORE=redis`, `phpunit*.xml` ép `array`; TTL = `session.lifetime` phút × 60 (comment giải thích đúng đơn vị giây vs phút) |

## Gợi ý cho QA
- Test tay: mở 2 trình duyệt thật (không phải Postman/cùng 1 cookie jar) đăng nhập cùng tài khoản học sinh, xác nhận trình duyệt A nhận đúng overlay "đăng nhập ở thiết bị khác" (không phải banner lỗi chung) — khớp US-014 §2.1 phía frontend (ngoài phạm vi T05, nhưng là nơi dễ lộ lỗi tích hợp `code` field).
- Test lệch thời gian: tombstone TTL = `session.lifetime` (phút) — nếu phiên cũ bị treo lâu hơn TTL này trước khi request tới, `/auth/me` của thiết bị cũ sẽ trả `UNAUTHENTICATED` thay vì `SESSION_REPLACED` (tombstone đã hết hạn) — xác nhận đây là hành vi được chấp nhận, không phải bug, trước khi báo cáo.
- Ưu tiên test thủ công 1 lần "Redis cache down khi đang đăng nhập" (tắt Redis cache DB tạm thời trong môi trường staging) để bù cho khoảng trống ở R1 — xác nhận thiết bị cũ vẫn bị từ chối dù không có thông điệp "thiết bị khác" cụ thể.
- Không cần test lại T01-T04/T10 vì diff không đổi hành vi các route đó ngoài việc gắn `student.single_session` thật (đã có kiến trúc test T02 bảo vệ từ trước) — 356 test hiện có đã xanh, xác nhận không hồi quy.
