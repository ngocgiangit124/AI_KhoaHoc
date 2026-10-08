# QA: SLN7 (đổi thứ tự câu hỏi quiz)
**Kết quả:** PASS (không có bug ứng dụng; 1 lưu ý test Low)

## Độ phủ
| Hạng mục | Test | Kết quả |
|---|---|---|
| 200 câu đảo ngược | QA: 200 cau dao nguoc | PASS |
| >200 phần tử, null, thiếu field, 1.5, "1e2", bool, mảng lồng, âm | QA: 201 phan tu / kieu du lieu question_ids | PASS (422 VALIDATION_ERROR, không 500) |
| Chuỗi "id" hợp lệ | cùng test | PASS (chấp nhận, đổi thứ tự đúng; "5" và 5 coi là trùng -> 422) |
| Quiz rỗng + [] | cùng test | PASS (200, data []) |
| DELETE rồi POST, quiz có câu gắn lượt làm + copy-on-write | QA: DELETE roi POST | PASS (position luôn 1..n, POST = cuối+1, reorder bỏ qua câu xoá mềm) |
| Lượt đang làm/đã nộp qua HTTP thật (admin + học sinh) | QA: luot dang lam ... qua HTTP quan tri | PASS (lượt cũ giữ thứ tự, lượt mới theo thứ tự mới) |
| Race reorder x delete | race (5 vòng) | PASS (chỉ OK hoặc 422 QUIZ_QUESTIONS_MISMATCH, còn 1..4 liền mạch) |
| Race reorder x PUT copy-on-write | race (4 vòng) | PASS (không lỗi/deadlock, 1..n, lượt cũ giữ nguyên) |
| Race reorder x start attempt (6 HS) | race (4 vòng) | PASS (mỗi lượt chốt đúng thứ tự cũ hoặc mới, không trộn) |
| Race hỗn hợp reorder+delete+add+start | race (3 vòng) | PASS |

File: `backend/tests/Feature/SLN7/QaReorderTest.php`, worker `backend/tests/Support/quiz_reorder_race_worker.php`, `backend/phpunit.local-g.xml`.

## Bug ứng dụng
Không có.

## Lưu ý (Low, test của dev, không phải bug ứng dụng)
- `tests/Feature/SLN7/QuestionReorderTest.php:69,106` đếm tuyệt đối `AuditLog ... quiz_question.reorder = 0`. Audit bất biến (trigger L2) nên mọi lần chạy race (của QA hay bất kỳ worker nào gọi reorder) làm bảng tích luỹ dòng và 2 test này fail ("9 is identical to 0") khi chạy cùng DB sau nhóm race. Sau `migrate:fresh` thì PASS. Đề xuất: đếm theo `subject_id = $quiz->id` hoặc so delta (đúng quy ước trong tests/Pest.php).

## Rủi ro & đề xuất
- Reorder N update lẻ (tối đa 200) trong 1 transaction giữ khoá quiz; đo 200 câu đảo ngược ~7s trong test do tạo dữ liệu, request không có dấu hiệu chậm bất thường. Chấp nhận được.
- Chuỗi số "1" được chấp nhận (không chặt kiểu); contract không cấm, FE nên gửi số.

## Lệnh đã chạy (trong `cd infra && docker compose exec -T php`, cwd /var/www/backend)
- `sed 's/vitaminvui_testing"/vitaminvui_testing_g"/g' phpunit.xml > phpunit.local-g.xml`
- `php artisan migrate:fresh --env=testing --database=mysql` (DB testing_g)
- `vendor/bin/pest -c phpunit.local-g.xml tests/Feature/SLN7 --exclude-group=race` -> 20 passed
- `vendor/bin/pest -c phpunit.local-g.xml tests/Feature/SLN7/QaReorderTest.php --group=race` -> 4 passed (200 assertions)
- `vendor/bin/pint` trên 2 file mới
