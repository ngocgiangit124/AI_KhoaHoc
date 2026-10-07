# QA: SLN5 (POST /learn/lessons/{lesson}/complete + 403 COURSE_NOT_OWNED kèm course)
**Kết quả:** PASS (0 Critical/High/Major; 1 Minor ghi nhận, không chặn)

Chạy trên DB `vitaminvui_testing_g`: SLN5 + T13 + T22 + T23: 158 pass, 1 skip (đúng là test BUG-1). Group race (SLN5, T13, T22, T23): 10/10 pass. Pint sạch (sau khi sửa style file test QA), PHPStan `--memory-limit=2G`: No errors.

## Độ phủ
| Yêu cầu | Test | Kết quả |
|---|---|---|
| Complete bài link ngoài, 200 cùng shape heartbeat, idempotent giữ completed_at | ManualCompleteTest 1, 2, in_progress sẵn | PASS |
| 422 LESSON_COMPLETION_NOT_MANUAL (upload, none, đổi nguồn) | ManualCompleteTest | PASS |
| Khách -> 401; phiên bị thay -> 401 SESSION_REPLACED | QaMatrix (khách), ManualComplete (phiên) | PASS |
| Enrollment pending/rejected/revoked -> 403 kèm course, không ghi tiến độ | QaMatrix | PASS |
| Thu hồi giữa phiên rồi khoá bị ẩn/nháp -> 404, không lộ slug/title | QaMatrix | PASS |
| Khoá nháp/ẩn chưa ghi danh: 404 và không lộ slug/title/"course" ở course, lesson, playback, heartbeat, complete, me/progress, quiz start/list | QaMatrix | PASS |
| Khoá published chưa ghi danh: 403 `errors.course` đúng {id,slug,title} ở mọi endpoint trên | QaMatrix | PASS |
| Đã ghi danh rồi khoá bị ẩn vẫn học/complete được (BR4) | ManualComplete | PASS |
| Chương/khoá/bài xoá mềm -> 404 | ManualComplete | PASS |
| ID khoá nháp so với ID không tồn tại: cùng status 404 và cùng code | QaMatrix | PASS |
| ID khoá nháp so với ID không tồn tại: cùng body | QaMatrix (skip) | BUG-1 |
| Khoá có cả video + link ngoài: course_percent 50 -> 100, khớp me/courses/{id}/progress | QaMatrix | PASS |
| 429 sau 6 lần, không ảnh hưởng bài khác | ManualComplete, QaMatrix | PASS |
| ID không phải số 404, GET 405, body thừa (user_id...) bị bỏ qua | QaMatrix | PASS |
| Race: 8 complete song song; complete và heartbeat song song | ManualCompleteRaceTest | PASS |

Test QA mới: `backend/tests/Feature/SLN5/QaMatrixTest.php` (14 test, 1 skip).

## Bug
### BUG-1 [Minor]: 404 của ID khoá/bài nháp khác message với 404 của ID không tồn tại
- Tái hiện: (1) tài khoản học sinh chưa ghi danh gọi `POST /api/v1/learn/lessons/999999/complete`; (2) gọi với ID bài thuộc khoá `draft`/`unpublished`.
- Mong đợi: body giống nhau (chống dò ID, theo mục tiêu của SLN5).
- Thực tế: (1) `{"message":"Không tìm thấy tài nguyên.","code":"NOT_FOUND"}`, (2) `"Không tìm thấy bài học."`. Status và code giống nhau. Lệch message ở cả 6 endpoint: complete, heartbeat, lesson, playback, learn/course, me/progress. Có từ trước ở các endpoint cũ, SLN5 chỉ kéo thêm complete.
- Vị trí nghi ngờ: `LessonAccessService::notFound()` (message riêng) so với handler ModelNotFound mặc định.
- Đề xuất: dùng chung một message cho hai nhánh. Khi sửa, bỏ `->skip` ở test "QA 404 cua ID khong ton tai giong het...". Mức Minor vì ID tăng dần nên dò được bằng cách khác, và không lộ slug/title.

## Rủi ro và ghi nhận
- Học sinh bị thu hồi ghi danh rồi khoá chuyển nháp: heartbeat nay 404 thay vì 403 (đã ghi trong review/contract, chấp nhận).
- Quiz `assertOwnsCourse` (submit/answer) vẫn 403 không kèm course khi khoá nháp, không lộ dữ liệu (đã kiểm ở test lộ thông tin cho quiz start/list).
- `notOwned` thêm 1 query ở đường 403, chấp nhận.
- Bài đã complete lúc là link ngoài rồi đổi sang upload: giữ completed (R3, test có).
- Không xung đột throttle: complete và heartbeat dùng chung bucket theo bài; bài link ngoài không có heartbeat nên không vướng.
- Đã xoá `backend/phpunit.local-g.xml` (file bị gitignore). Không sửa code ứng dụng, không commit.

## Sau QA (2026-10-07)

- BUG-1 đã sửa: `LessonAccessService::notFound()` dùng chung message "Không tìm thấy tài nguyên." với 404 mặc định; bỏ `->skip` ở test QA tương ứng.
