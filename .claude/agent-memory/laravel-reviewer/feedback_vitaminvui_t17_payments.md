---
name: feedback-vitaminvui-t17-payments
description: Cách review adapter MoMo/PaymentGatewayManager (T17) và những gì vẫn cần xác nhận trước khi T18-T20 (checkout/IPN) đi vào production
metadata:
  type: project
---

**T17 (thanh toán: abstraction + MoMo, ADR-001) đã APPROVE ở vòng 1 (2026-09-29), commit `fe38f3a` trên nhánh local `t17`.** 336 test pass, Pint/Larastan sạch — tự verify lại độc lập trong Docker, không chỉ tin báo cáo bàn giao.

**Kỹ thuật đáng chú ý để nhận diện lại ở T18/T19/T20 (checkout, IPN, đối soát):**
- `App\Support\ProductionConfigGuard::guardPayments()` dùng ALLOWLIST tường minh (`KNOWN_GATEWAYS` const) thay vì chỉ chặn `'fake'`, và dùng `match()` KHÔNG có nhánh `default` — cố ý, để thêm cổng mới mà quên viết `guard*` tương ứng sẽ nổ `UnhandledMatchError` lúc boot thay vì âm thầm bỏ qua. Đây là pattern chung của dự án cho "an toàn khi mở rộng" (bài học từ M3 — trước đó có blocklist theo chuỗi cụ thể bị vượt qua bởi biến thể viết hoa/khoảng trắng). Khi thêm cổng thứ 2 (VNPay/ZaloPay...) ở task sau, kiểm `KNOWN_GATEWAYS` + hàm `guard{Ten}()` tương ứng đã được thêm chưa — thiếu 1 trong 2 là BLOCKER (guard cũ sẽ tự nổ lỗi ở CI/production nếu thiếu hàm, nhưng nếu quên thêm vào `KNOWN_GATEWAYS` thì cổng mới bị chặn nhầm ở production — cũng cần test).
- `PaymentGatewayManager` (kế thừa `Illuminate\Support\Manager`) có 2 lớp chặn `FakeGateway` ở production: (1) `ProductionConfigGuard::guardPayments()` lúc boot app, (2) `createFakeDriver()` tự kiểm `app()->environment('local','testing')` dù driver có lỡ nằm trong `enabled_gateways`. Khi review task sau có thêm gateway giả lập khác, kỳ vọng cùng pattern 2 lớp này.
- Thứ tự bắt buộc khi verify IPN/query MoMo (S12): (1) đủ trường bắt buộc, (2) build `signedFields` (được phép đọc raw string để tính chữ ký), (3) `hash_equals` chữ ký bằng secret từ **config** (không bao giờ từ payload), (4) CHỈ SAU ĐÓ mới đọc `partnerCode`/business fields để so khớp, (5) `StrictAmountParser` (chỉ int hoặc chuỗi toàn chữ số, từ chối thập phân/khoa học/âm/float/bool/mảng). `MoMoGateway.php` là ví dụ chuẩn của thứ tự này — dùng làm baseline khi review adapter cổng thanh toán khác.
- `queryStatus()` verify thêm `requestId` của response phải khớp UUID vừa tự sinh cho request đó (không phải requestId gốc của attempt) — chặn kịch bản trộn phản hồi giữa 2 lần query dù chữ ký hợp lệ. Task local đã tự thêm việc này ngoài yêu cầu tối thiểu của ADR.

**Rủi ro CHƯA đóng, cần theo dõi ở T18-T20 (đã ghi trong `docs/reviews/review-T17.md`, không phải BLOCKER của T17):**
- Danh sách trường ký (đặc biệt: response của `create` và `query`) và bảng `resultCode` (`1000/7000/7002/9000` = Pending) đều lấy từ ADR-001, CHƯA đối chiếu với sandbox/tài liệu MoMo thật — môi trường viết code bị chặn mạng ra `test-payment.momo.vn`. Có lệnh `payments:momo:verify-sandbox` (tự chặn production, không in secret) nhưng CHƯA chạy được. **Bắt buộc chạy lệnh này với credential sandbox thật + đối chiếu tài liệu trước khi bật `PAYMENT_GATEWAYS=momo` ở production** — coi đây là gate go-live riêng, không phải "xong" của T17. Khi review T18/T19/T20, hỏi lại việc này đã làm chưa nếu liên quan tới bật MoMo thật.
- `createPayment()` không verify chữ ký (`signature`) của response create (chỉ so `partnerCode`/`orderId`/`requestId`) — đúng spec ADR-001 hiện tại (không bắt buộc), nhưng là điểm có thể siết thêm sau này (SHOULD, không phải quy định đang vi phạm).

**Cách test đã dùng để tự verify vector chữ ký (áp dụng lại được cho review lần sau):** copy raw string kỳ vọng trong test + secret, tính lại độc lập bằng `python3 -c "import hmac,hashlib; print(hmac.new(b'secret', raw.encode(), hashlib.sha256).hexdigest())"` — không tin code đang test tự chấm điểm chính nó. Vector trong `MoMoSignerTest.php` là TỰ TẠO (Dev đã tự ghi rõ), chỉ xác nhận đúng thuật toán, không xác nhận đúng danh sách trường thật của MoMo.
