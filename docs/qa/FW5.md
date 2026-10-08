# QA: FW5 + SLN6
**Kết quả:** PASS (0 bug Critical/High/Medium; 3 lưu ý Low)

## Lệnh đã chạy
- Backend (container php, DB `vitaminvui_testing_g`): `php artisan test -c phpunit.local-g.xml tests/Feature/SLN6 tests/Feature/T22 tests/Feature/T21 tests/Feature/T23` -> 100 pass (trước khi thêm test QA); sau đó `tests/Feature/SLN6` -> 11 pass (6 của Dev + 5 test QA mới).
- Frontend web: `typecheck` sạch, `lint` sạch, `test` 388/388 (47 file).
- E2E thật: `seed-e2e-quiz.sh --reset`, `E2E_FW5=... run-quiz-real.sh` (workers=1) -> 13/13 pass (3,0 phút), `seed-e2e-quiz.sh --clean`. Build chạy ở `.next-e2e-fw5` do config có sẵn (không đụng `.next` dùng chung, không restart dev server).

## Độ phủ
| AC | Test | Kết quả |
|---|---|---|
| SLN6: nộp tay trước `expires_at` -> false | AutoSubmittedTest | PASS |
| SLN6: nộp lúc `now >= expires_at` (đúng hạn, +29s) -> true, điểm không đổi | AutoSubmittedTest, QaBoundaryTest | PASS |
| SLN6: trong ân hạn `submitted_at = now`; quá ân hạn `= expires_at` | QaBoundaryTest (+20s, +30s, +31s) | PASS |
| SLN6: mốc ân hạn +30s còn nhận autosave, +31s 409 và lượt tự nộp | QaBoundaryTest | PASS |
| SLN6: GET lượt quá hạn (nộp lười) true; lịch sử phản ánh cờ; nộp lại idempotent | QaBoundaryTest, AutoSubmittedTest | PASS |
| SLN6: quiz không giới hạn giờ nộp tay -> false | AutoSubmittedTest | PASS |
| FW5: BR1-BR7 (làm, autosave, KaTeX, hết giờ, kết quả, làm lại, quyền) | 13 ca e2e + 388 unit | PASS |
| FW5: nhãn "tự động nộp khi hết giờ" khớp SLN6 | đọc `QuizResultScreen.tsx:108` (hiện khi `auto_submitted=true`); e2e hết giờ chạy qua luồng này | PASS (xem L1) |

Test QA mới: `backend/tests/Feature/SLN6/QaBoundaryTest.php`.

## Bug phát hiện
Không có Critical/High/Medium.

### Lưu ý Low
- L1: e2e hết giờ (`lam-quiz-real.spec.ts:268`) chưa assert nhãn "Bài đã được tự động nộp khi hết giờ" xuất hiện. Hành vi đúng theo code và backend test, nhưng chưa có kiểm tự động ở tầng UI. Đề xuất thêm 1 assert.
- L2: hệ quả của SLN6 (đã được PO chốt): học sinh nộp tay trong 30s sau hạn sẽ thấy nhãn "tự động nộp khi hết giờ" dù tự bấm. UI khoá form khi đồng hồ về 0 nên trường hợp này gần như chỉ xảy ra ở luồng hết giờ thật.
- L3: nhắc lại Review N2: docblock đầu `QuizAttemptService.php` đã cập nhật nhưng vẫn nên rà câu chữ; CORS `max_age=0` (mỗi PUT autosave cần preflight) là tối ưu, chưa xử lý. Chưa kiểm Safari/Firefox cho keepalive, 375px trên máy thật, tương phản KaTeX giao diện tối, e2e 401/403 giữa phiên (chỉ unit).

## Rủi ro và đề xuất
- Bảo mật KaTeX (`trust:false`, giới hạn 2000 ký tự, `maxExpand`) đã xem lại, e2e xác nhận `<b>` chỉ là chữ, không có `<a>/<img>` trong công thức, không vi phạm CSP.
- Không phát hiện N+1/mass assignment mới trong phạm vi thay đổi (chỉ đổi cờ trong `settle`, UPDATE vẫn dưới `lockForUpdate` và `WHERE submitted_at IS NULL`).
- File thừa: `backend/phpunit.local-g.xml` (tạo theo quy tắc, không nên commit, giống các `phpunit.local-*.xml` khác).
