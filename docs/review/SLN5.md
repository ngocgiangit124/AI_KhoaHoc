# SLN5 — Sửa lỗi nhỏ 5 (phục vụ FW4)

## Dev

1. `POST /api/v1/learn/lessons/{lesson}/complete` (không body, throttle `heartbeat`: 6/phút/user/bài). Chỉ bài `video_source=external_link`.
   - 200 `{status:'completed', completed:true, course_percent}` (giống heartbeat). Idempotent, giữ `completed_at` gốc.
   - 422 `LESSON_COMPLETION_NOT_MANUAL` với bài video tải lên/không video; 403 `COURSE_NOT_OWNED`; 404; 401 `SESSION_REPLACED`; 429.
   - Code: `ProgressService::completeManually` (cùng thứ tự khoá khóa S -> bài S -> lesson_progress X, kiểm quyền sau khi giữ khoá, cập nhật `enrollments.last_accessed_at`), `ProgressController::complete`, route `api.learn.lessons.complete`. Heartbeat hiện không có event/audit nên không thêm.
2. 403 `COURSE_NOT_OWNED` kèm `errors.course {id, slug, title}` khi khóa published (envelope lỗi đặt context vào `errors`); khóa nháp/ẩn/xoá thì không có `errors`. Sửa tập trung ở `LessonAccessService::notOwned(?int $courseId)`, nên áp dụng cho learn course/lesson/playback/heartbeat/complete, quiz và me/courses/{id}/progress.
3. Test: `backend/tests/Feature/SLN5/ManualCompleteTest.php` (8 test). Pint, PHPStan sạch; T13 + T22 + T23 (120 test) pass trên `vitaminvui_testing_e`.
4. Ghi chú QA: complete dùng chung bucket throttle với heartbeat theo bài (bài link ngoài không có heartbeat nên không va chạm); chưa có test race song song cho complete.

## Review

**Kết luận:** APPROVE (0 BLOCKER, 2 SHOULD, 2 NIT). Test SLN5 8/8 pass trên `vitaminvui_testing_g`.

Đã kiểm: thứ tự khoá khoá-S -> bài-S -> lesson_progress-X giống heartbeat (không deadlock; `insertOrIgnore` chỉ khi chưa có dòng, đọc X trước); quyền kiểm sau khi giữ khoá, enrollment active, bài/chương/khoá xoá mềm -> 404; race hai request đồng thời: dòng bị khoá X nên request sau thấy Completed và return, giữ `completed_at`; `course_percent` tính sau transaction (đúng, đọc dữ liệu đã commit); `notOwned` chỉ lộ `id/slug/title` khi `status=published` (Course có SoftDeletes nên khoá xoá không lộ); route dùng chung limiter `heartbeat` khoá theo user+bài; contract khớp code.

### R1 [SHOULD] /complete lộ sự tồn tại bài của khoá nháp/ẩn cho người chưa ghi danh
- Vị trí: `backend/app/Services/Learning/ProgressService.php:81-83`
- Vấn đề: người không sở hữu gọi `/complete` với ID bài thuộc khoá nháp/ẩn nhận 403 `COURSE_NOT_OWNED` (không kèm course), còn ID không tồn tại nhận 404. Dò được ID bài của khoá chưa công bố. Trái nguyên tắc của `LessonAccessService` ("khoá chưa published mà không sở hữu: 404"). Heartbeat cũ cũng bị y hệt, nhưng endpoint mới không nên lặp lại.
- Đề xuất: khi không sở hữu, nếu khoá không published thì `throw $this->access->notFound()`, ngược lại `notOwned`. Làm trong `notOwned`-caller của complete (và nếu tiện, sửa cả `record()` cho heartbeat; khi đó giữ ý: người đã bị thu hồi rồi khoá chuyển nháp vẫn có thể 403, test hiện tại ở dòng cuối `ManualCompleteTest` cần điều chỉnh theo quyết định PO).

### R2 [SHOULD] Thiếu test cho các nhánh rủi ro
- Vị trí: `backend/tests/Feature/SLN5/ManualCompleteTest.php`
- Vấn đề: chưa có test cho (a) bài đã có dòng `in_progress` từ trước (nhánh update, không insert); (b) chương bị xoá mềm -> 404 (chỉ test bài xoá); (c) khoá xoá mềm -> 404; (d) bài đổi nguồn upload <-> link ngoài (đổi sang upload -> 422, kể cả khi đã có dòng `in_progress`); (e) bài `none` -> 422; (f) 429 sau 6 lần; (g) race song song (Dev đã tự ghi chú chưa có).
- Đề xuất: bổ sung (a)-(f) bằng Pest; (g) có thể ghi nợ.

### R3 [NIT] Bài đã /complete lúc là link ngoài rồi đổi sang upload vẫn giữ completed
- Vị trí: `ProgressService.php:119-121`
- Ghi chú: hành vi chấp nhận được (không thể lạm dụng: kiểm `video_source` ở thời điểm gọi, dưới khoá S của bài nên không race với admin sửa nguồn). Chỉ nên ghi rõ trong docs để QA/PO biết không reset tiến độ.

### R4 [NIT] `notOwned` thêm 1 query mỗi lần 403
- Vị trí: `LessonAccessService.php:117-123`
- Ghi chú: chấp nhận được (đường lỗi, 403 hiếm); trong `completeManually` truy vấn chạy bên trong transaction đang giữ khoá S, ngắn nên ổn. Có thể ném sau khi ra khỏi transaction nếu muốn gọn.

### Đối chiếu yêu cầu
| Yêu cầu | Đáp ứng |
|---|---|
| Complete bài link ngoài, idempotent giữ completed_at | OK (test 1, 2) |
| 422 LESSON_COMPLETION_NOT_MANUAL bài upload | OK (test 3); bài `none` cũng 422 theo code, chưa có test |
| 403 kèm errors.course chỉ khi published | OK (test 4, 8) |
| Một phiên / 401 | OK (middleware nhóm route, test 6) |
| Throttle chung heartbeat | OK |
| Contract | Khớp |

### Gợi ý cho QA
- Hai request /complete song song cùng bài; /complete song song với heartbeat cùng bài; /complete trong lúc admin xoá bài/chương/đổi nguồn video.
- Thu hồi enrollment giữa phiên rồi gọi /complete.
- ID bài thuộc khoá nháp với tài khoản chưa ghi danh (xem R1).
- `course_percent` với khoá có bài xoá mềm.

## Dev đã sửa

- R1: `LessonAccessService::denyNotOwned($courseId)`: người không sở hữu + khóa không published -> 404, published -> 403 kèm `errors.course`. Dùng ở `ProgressService` cho `/complete` VÀ heartbeat (`assertCanWatch`/`assertCanLearnCourse` vốn đã 404 cho khóa nháp). Học sinh ĐÃ ghi danh (enrollment active) rồi khóa bị ẩn vẫn giữ quyền (BR4, không đổi). Chỉ thay đổi: học sinh bị thu hồi ghi danh rồi khóa bị ẩn -> heartbeat nay 404 thay vì 403 (contract chỉ ghi "thu hồi giữa phiên 403"; đã bổ sung điều kiện khóa published). `assertOwnsCourse` của quiz-attempt giữ nguyên (người gọi đã biết ID qua lượt của mình).
- R2: thêm test (a) dòng in_progress có sẵn, (b)(c) chương/khóa xoá mềm, (d) đổi nguồn upload<->link, (e) bài `none`, (f) 429 sau 6 lần, (g) 2 race (8 `/complete` song song; `/complete` >< heartbeat) trong `ManualCompleteRaceTest.php` (group `race`), worker thêm mode `complete`, `make_external`, `completed_state`.
- R3: ghi trong api-contract.
- Lưu ý: chạy test bằng `pest -c phpunit.local-e.xml` (phpunit.xml ép DB `vitaminvui_testing`; DB_DATABASE env không có tác dụng).
