# REVIEW: T14 (US-012 — EnrollmentService, ghi danh miễn phí)
**Kết luận:** PASS (0 BLOCKER · 4 SHOULD · 3 NIT)
**Phạm vi:** commit bf17bb3 (diff b23efe4..bf17bb3), nhánh claude/zen-dirac-fmucf7-t14 · 26 file. Security review hoãn đến cuối dự án nên review này gồm cả bảo mật.

## Tổng quan
Bám sát story và api-contract. Chuyển trạng thái bằng `UPDATE ... WHERE status='pending_approval'` nên double-approve/reject an toàn. Race gửi trùng dựa vào unique `(user_id, course_id, live_flag)` (generated column, NULL khi rejected/revoked) và bắt lỗi 1062 đúng. Policy `decide` kiểm theo `course_teacher` của khóa của enrollment, không tin input. Không thấy lỗi nghiệp vụ hay lỗ hổng chặn merge.

## Kiểm tra bảo mật (kết quả)
- Race gửi trùng: an toàn. NULL không vi phạm unique nên gửi lại sau reject vẫn được. try/catch 1062 rồi đọc lại để trả 409. Chưa có test race thật (xem R4).
- Double-approve/reject: an toàn (UPDATE có điều kiện, `increment` chỉ chạy khi `affected=1`, cùng transaction; DomainException làm rollback).
- IDOR GV: `can:decide,enrollment` chạy trước controller. GV không phụ trách nhận 403 (kể cả khi enrollment đã xử lý). Danh sách tự giới hạn theo `course.teachers`; lọc `course_id` khóa lạ bị 403.
- PII: `EnrollmentRequestResource` chỉ trả `email_masked`/`phone_masked`, không lộ email/SĐT thô. `PiiMask` giữ nguyên hành vi cũ của MeResource.
- XSS `rejection_reason`: mail dùng `{{ }}` (escape), request có `PlainText`, `max:1000`. Không có `{!! !!}`.
- Mass assignment: `status`, `approved_*` không nằm trong `$fillable`; gán trực tiếp.
- `parent.consent` bản tạm: chỉ cho qua `not_required`/`granted`, đặt sau `account.verified`, có test. Đúng với tasks.md T18.
- Mail: `ShouldBeEncrypted` + `ShouldQueue`, mặc định tắt theo flag (xem R1).
- RouteMiddlewareGroupsTest quét toàn bộ route nên 4 route mới được kiểm. Route học sinh có đủ `account.active, student.single_session, no_store, role:hoc_sinh`. Route admin nằm trong nhóm staff (origin/idle/session/mfa).

## Phát hiện
### R1 [SHOULD] Lỗi hàng đợi mail/audit sau commit gây 500 dù trạng thái đã đổi
- Vị trí: `app/Services/Enrollment/EnrollmentService.php` (`approve`, `reject`: đoạn sau transaction, `auditLogger->log` và `sendDecisionMailIfEnabled`)
- Vấn đề: khi flag bật, nếu `Mail::queue` ném lỗi (Redis/queue sập) thì API trả 500 trong khi enrollment đã `active`. Người duyệt bấm lại sẽ nhận 409 và tưởng là lỗi. Học sinh đã có quyền học nhưng không được báo.
- Đề xuất: bọc việc gửi mail trong try/catch, `report($e)` và `Log::warning` (không kèm email/lý do), không ném lại. Nên dùng `afterCommit`/dispatch sau commit.
  ~~~php
  try { Mail::to(...)->queue(...); } catch (\Throwable $e) { report($e); }
  ~~~
  Thêm test: mock Mail ném exception, khi đó approve vẫn trả 200.

### R2 [SHOULD] Duyệt không kiểm lại khóa còn miễn phí/published
- Vị trí: `EnrollmentService::approve` và `EnrollmentPolicy::decide`
- Vấn đề: nếu Admin đổi khóa sang có phí hoặc unpublish khi còn yêu cầu pending, người duyệt vẫn kích hoạt được (khóa có phí được cấp miễn phí). Story có ghi câu hỏi mở này và PO chưa quyết.
- Đề xuất: hỏi PO. Tối thiểu ghi rõ hành vi hiện tại vào docs/board, hoặc trong `approve` chặn khi `price > 0` (trả 409 `COURSE_NOT_FREE`). Đưa vào T08/T09 (đổi giá) một hook xử lý pending.

### R3 [SHOULD] Không có throttle cho `POST /courses/{course}/free-enrollments`
- Vị trí: `routes/api.php` (route free-enrollments)
- Vấn đề: vòng lặp gửi, bị reject, gửi lại sinh vô hạn dòng `rejected` (bảng giữ lịch sử) và tạo nhiễu cho người duyệt. Các route ghi khác của học sinh (otp) đều có throttle.
- Đề xuất: thêm limiter theo user (ví dụ `throttle:free-enroll` 10/phút) hoặc giới hạn số lần gửi lại/ngày/khóa.

### R4 [SHOULD] Test race chưa thật sự kiểm tra nhánh bắt 1062
- Vị trí: `tests/Feature/T14/EnrollmentServiceTest.php` (test "goi requestFree 2 lan")
- Vấn đề: gọi tuần tự nên chỉ đi qua nhánh `findLiveEnrollment`, nhánh `catch QueryException` chưa được chạy.
- Đề xuất: test chèn sẵn dòng live bằng `DB::listen`/mock `findLiveEnrollment` trả null lần đầu, hoặc gọi private qua Reflection, để buộc `save()` đụng unique và khẳng định trả `ENROLLMENT_PENDING`.

### R5 [NIT] Route model binding `{enrollment}` không scope: 404 và 403 cho phép dò ID
- GV thử ID tuần tự sẽ phân biệt "không tồn tại" (404) với "khóa khác" (403). Rủi ro thấp (chỉ nhân sự đã đăng nhập, MFA). Có thể chấp nhận; nếu muốn, trả 404 cho GV không phụ trách.

### R6 [NIT] `CourseEnrollmentsCountRecounter` cập nhật không nguyên tử
- Đọc `withCount` rồi `update` từng dòng; nếu approve chạy xen giữa (02:00 ít xảy ra) có thể ghi đè lệch 1. Ngoài ra `Course` xoá mềm bị bỏ qua (nếu Course dùng SoftDeletes). Có thể dùng một `UPDATE courses SET enrollments_count = (SELECT COUNT(*) ...) WHERE enrollments_count <> (...)` và `withTrashed()`.

### R7 [NIT] `per_page` chưa validate trong `EnrollmentRequestIndexRequest`
- Đang kẹp bằng `max/min` trong controller nên an toàn; nên đưa vào `rules()` (`integer|min:1|max:50`) cho đồng nhất 422.

## Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 | `FreeEnrollmentController@store` + `requestFree` (201 pending) | Hiển thị "Đang chờ duyệt" thuộc viewer-state/frontend, ngoài T14 |
| AC2 | `approve`: active, `approved_by/at`, tăng count | Mail theo flag (tắt mặc định, chờ PO) |
| AC3 | `reject` + `rejection_reason` | OK; `approved_by` để NULL, ai từ chối nằm ở audit_logs |
| AC4 | 409 `ENROLLMENT_PENDING` + unique live_flag | OK; race chưa test thật (R4) |
| AC5 | live_flag NULL khi rejected | Có test |
| AC6 | `decide`/`viewAnyRequests` theo `course_teacher` | 403 kể cả gọi thẳng ID; có test |
| AC7 | index sắp `requested_at asc`, phân trang, che PII | OK |
| BR1/BR7 | Policy `requestFree`, `account.verified` | OK; unpublished trả 404 |

## Gợi ý cho QA
- Hai request approve song song (và approve + reject song song) trên cùng enrollment: chỉ một thành công, `enrollments_count` tăng đúng 1.
- Hai request gửi đăng ký song song trên MySQL thật: 1 bản ghi live, cái còn lại 409.
- GV bị gỡ khỏi khóa khi còn pending: GV 403, Admin duyệt được.
- Đổi giá khóa khi còn pending rồi duyệt (R2).
- Bật flag mail và giả lập queue lỗi (R1); rejection_reason chứa `&lt;`, dấu nháy, ký tự Unicode.
- Học sinh `parent_consent_status` = pending/denied; học sinh chưa verify OTP.
- `counters:recount` khi khóa bị xoá mềm.
