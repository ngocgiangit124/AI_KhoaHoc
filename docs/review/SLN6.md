# SLN6 — Sửa lỗi nhỏ 6 (review FW5 R1)

## Dev

1. `QuizAttemptService::settle`: nộp tay lúc `now >= expires_at` (trong ân hạn 30 s) giờ ghi `auto_submitted=true`; nộp trước hạn hoặc quiz không giới hạn giờ vẫn `false`. Giữ nguyên ân hạn, cách chấm, `submitted_at=now` trong ân hạn, `submitted_at=expires_at` sau ân hạn. Không có job/scheduler tự đóng lượt riêng (lượt quá hạn được chốt lazy qua `settleIfExpired`, vốn đã `auto_submitted=true`).
2. api-contract (dòng quiz submit) cập nhật.
3. Test: `backend/tests/Feature/SLN6/AutoSubmittedTest.php` (6 test: trước hạn, đúng hạn, +29s, +31s, nộp lại idempotent, không giới hạn giờ). Sửa 2 assert cũ ở `T22/TimeLimitTest.php` (+20s) và `T22/EdgeQaTest.php` (+29s) từ false sang true theo quyết định mới. T21 + T22 + T23 + SLN6: 100 pass trên `vitaminvui_testing_e`. Pint, PHPStan sạch.
4. Ghi chú QA: không thêm test race mới (race group T22 hiện có chạy giờ thật, không đổi hành vi); idempotency nộp lại đã có test tuần tự, khoá `lockForUpdate` giữ nguyên.

## Review

**Kết luận: APPROVE** (0 BLOCKER, 0 SHOULD, 2 NIT)

Phạm vi: `QuizAttemptService::settle`, `tests/Feature/SLN6/AutoSubmittedTest.php`, 2 assert T22, dòng submit trong api-contract. Chạy Pest T21+T22+T23+SLN6 (DB `vitaminvui_testing_g`): 100 pass, 1008 assertions.

- Điều kiện biên: `! now()->lt(expires_at)` tức `now >= expires_at` đúng quyết định PO; đúng `expires_at` -> true (có test). `expires_at` và `now()` cùng đồng hồ app/cùng timezone (cột datetime do app ghi, không so với `NOW()` của DB) nên không lệch đồng hồ DB/app.
- Quiz không giới hạn giờ: `expires_at === null` -> `pastDeadline=false`, `auto=!manual` -> nộp tay vẫn false (có test).
- Sau ân hạn: `expired=true` -> auto=true, `submitted_at=expires_at` giữ nguyên; trong ân hạn `submitted_at=now`, điểm không đổi (cùng nhánh chấm).
- `settleIfExpired`/start-resume gọi `settle` không `manual` -> auto=true như cũ; không có path khác ghi `auto_submitted` ngoài `start` (false khi tạo lượt).
- Idempotent/race: `settle` chỉ chạy dưới `lockForUpdate` theo PK, `isSubmitted()` trả sớm, UPDATE có `WHERE submitted_at IS NULL`. Hai submit quanh biên: submit thứ hai thấy đã nộp, trả kết quả đã chốt, cờ không bị ghi đè. Thay đổi không động tới khoá.
- Sửa assert cũ (+20s, +29s) từ false sang true là hệ quả trực tiếp của quyết định PO, hợp lý; tên test đã đổi theo.

NIT
- N1: `settle` tính `now()` nhiều lần (pastDeadline, submittedAt, updated_at); trong request thật chênh micro giây, vô hại. Có thể gán `$now = now()` một lần.
- N2: docblock đầu file (dòng 19-21) và docblock `settle` còn mô tả "nộp tay trong ân hạn không phải tự nộp"/"đã quá hạn mới đánh dấu tự nộp"; nên cập nhật câu chữ cho khớp.

Gợi ý QA: FE (ResultView) nếu hiển thị nhãn "tự nộp" dựa `auto_submitted` thì nay xuất hiện cả khi học sinh bấm nộp trong 30 s cuối; nên xác nhận hiển thị phù hợp ý PO.
