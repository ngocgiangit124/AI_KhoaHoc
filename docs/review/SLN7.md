# SLN7 — API đổi thứ tự câu hỏi quiz

## Dev
- Endpoint: `PUT /admin/courses/{course}/quizzes/{quiz}/questions/order`, body `{question_ids:[...]}`, trả `{data:[QuizQuestionResource]}`. Route khai báo trước `/questions/{question}`.
- Mẫu theo T09 `curriculum/order`: Form Request chỉ kiểm hình dạng (mảng số nguyên dương, không trùng, ≤ 200); tập id phải bằng đúng tập câu chưa xoá của quiz kiểm trong `QuizContentService::reorder` dưới `lockForUpdate` dòng quiz, sai → 422 `QUIZ_QUESTIONS_MISMATCH`. Ghi `position` 1..n chỉ cho dòng thay đổi. Audit `quiz_question.reorder` (không ghi nội dung câu).
- Không copy-on-write: chỉ đổi position, giữ id. Lượt làm giữ `question_ids` đã chốt nên không đổi thứ tự/điểm (có test).
- Xoá câu nay đánh lại position liền mạch 1..n (`renumber`, dưới cùng khoá quiz). Đã ghi vào contract.
- File: `app/Http/Requests/Admin/Quiz/QuizQuestionOrderRequest.php` (mới), `app/Http/Controllers/Api/V1/Admin/QuizQuestionController.php`, `app/Services/Quiz/QuizContentService.php`, `routes/admin.php`, `docs/architecture/api-contract.md`, `tests/Feature/SLN7/QuestionReorderTest.php`.
- Không có migration. Chưa test race đa tiến trình (chỉ kiểm có `for update` + kịch bản danh sách cũ → 422); QA có thể thêm vào worker T21 nếu muốn.

## Review (laravel-reviewer)
**Kết luận:** APPROVE (0 BLOCKER, 1 SHOULD, 2 NIT)
Phạm vi: working tree SLN7, 6 file. Chạy `tests/Feature/SLN7` + `T21` trên DB `vitaminvui_testing_g`: 45 passed (gồm race T21).

Đã kiểm, đạt: route `order` khai báo trước `{question}`; quyền qua Form Request `manageContent` (GV không được gán 403); `lockForUpdate` quiz theo `course_id` (quiz khác khóa/đã xoá 404); thứ tự khoá quiz -> questions giống create/update/delete nên không deadlock với copy-on-write PUT; không có unique (quiz_id, position) nên ghi position từng dòng không vướng; reorder không đụng copy-on-write, lượt làm giữ `question_ids`; start dùng `sharedLock` quiz nên chờ reorder commit rồi mới chốt thứ tự; 200 câu = tối đa ~200 UPDATE trong 1 transaction, chấp nhận được; audit không có nội dung; mã lỗi thật là `VALIDATION_ERROR` (`ApiExceptionRenderer.php:71`), dev đúng.

### R1 [SHOULD] Contract còn `VALIDATION_FAILED` sai mã trong cùng dòng
- Vị trí: `docs/architecture/api-contract.md` dòng quiz/questions (T21 chốt) và dòng quiz CRUD: "422 `VALIDATION_FAILED`".
- Vấn đề: đoạn SLN7 ghi `VALIDATION_ERROR` (đúng) nhưng cùng ô vẫn còn `VALIDATION_FAILED` -> FE đọc mâu thuẫn.
- Đề xuất: sửa cả hai chỗ thành `VALIDATION_ERROR`.

### R2 [NIT] Tên test "GET/DELETE /questions/order -> 404" chỉ gọi GET
- `tests/Feature/SLN7/QuestionReorderTest.php:119`: bỏ "DELETE" khỏi tên hoặc thêm assert DELETE (DELETE `/questions/order` cũng 404 vì `order` không phải id số; nên assert).

### R3 [NIT] Chưa có test race đa tiến trình
- Test đồng thời hiện chỉ mô phỏng tuần tự (danh sách cũ -> 422). Ttuỳ chọn thêm vào worker T21 cặp reorder x delete và reorder x PUT copy-on-write.

## Đối chiếu
| Yêu cầu | Đáp ứng | Ghi chú |
|---|---|---|
| Đổi thứ tự bằng đúng tập id | Có | 422 `QUIZ_QUESTIONS_MISMATCH` thiếu/thừa/khóa khác/đã xoá |
| Xoá đánh lại 1..n | Có | `renumber` dưới cùng khoá |
| Không copy-on-write, lượt làm giữ nguyên | Có | test có |
| Quyền / audit không lộ nội dung | Có | |

Gợi ý QA: reorder đồng thời với delete/PUT copy-on-write/start attempt; 200 câu đảo ngược; id dạng chuỗi số ("1") và số thực (1.5) trong `question_ids`; body thiếu `question_ids` hoặc `null`.
