# REVIEW: T17 — Thanh toán: abstraction + MoMo [SEC]

**Kết luận:** APPROVE
**Phạm vi:** nhánh local `t17` (worktree), commit `fe38f3a`; diff so với nhánh chính `claude/zen-dirac-fmucf7...t17` (đã loại phần merge `a49864e`) — 29 file thay đổi (28 file mới + `backend/app/Support/ProductionConfigGuard.php` sửa). Không đụng routes/migrations — đúng phạm vi T17 (chỉ abstraction + adapter, chưa wiring `payment_attempts`/webhook route, để T18/T19).

## Tổng quan
Chất lượng cao, bám sát ADR-001 và checklist Security S4/S12 rất chặt — thực tế còn làm mạnh hơn yêu cầu tối thiểu (allowlist tường minh `KNOWN_GATEWAYS` cho `guardPayments()` thay vì chỉ chặn `fake`; kiểm cả 3 secret MoMo không rỗng ở production; `match` không có nhánh `default` để tự nổ `UnhandledMatchError` nếu thêm cổng mà quên viết guard — đúng bài học M3 đã ghi trong comment). Thứ tự kiểm IPN/query (đủ trường → verify chữ ký bằng `hash_equals` → mới đọc field nghiệp vụ → parse amount nghiêm ngặt) khớp chính xác S12. Tôi đã tự chạy lại toàn bộ (không chỉ tin báo cáo bàn giao):

```
cd infra && docker compose run --rm --no-deps -T \
  -v .../worktrees/t17/backend:/var/www/wt -w /var/www/wt php \
  sh -c 'vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && vendor/bin/pest -c phpunit.t17.xml'
```
→ Pint: 198 file sạch. Larastan: No errors. Pest: **336 passed (848 assertions)** — khớp đúng con số báo cáo.

Tôi cũng tự tính lại độc lập (Python `hmac`, không dùng lại code đang test) 2 vector chữ ký trong `MoMoSignerTest.php` (loại `create` và `query request`) — khớp 100% với giá trị hex trong test. Vector là tự tạo (không phải ví dụ chính thức của MoMo — điều này đã được Dev tự ghi rõ trong comment/docblock), nên đây chỉ xác nhận *thuật toán* (thứ tự alphabet + `HMAC-SHA256(secretKey, "k=v&k=v...")`) đúng như ADR-001 §2 mô tả, không xác nhận *danh sách trường* khớp tài liệu MoMo hiện hành — rủi ro này Dev đã tự nêu, xem R1.

## Phát hiện

### R1 [SHOULD] Danh sách trường ký và bảng `resultCode` chưa được đối chiếu với sandbox/tài liệu MoMo thật
- Vị trí: `backend/app/Services/Payments/Gateways/MoMo/MoMoGateway.php` (docblock đầu file), `MoMoResultCode.php`, `backend/app/Console/Commands/Payments/VerifyMomoSandboxCommand.php`.
- Vấn đề: Toàn bộ danh sách trường ký (create/IPN/query request/**query response**) và bảng mã "đang xử lý" (`1000/7000/7002/9000`) hiện dựa vào ADR-001 §2 (chính Dev ADR cũng ghi "Dev xác nhận lại với tài liệu MoMo hiện hành ở T17"), nhưng môi trường viết code bị chặn mạng ra `test-payment.momo.vn` nên **chưa chạy được** `payments:momo:verify-sandbox` với sandbox thật. Nếu tài liệu MoMo hiện hành khác ADR-001 (đổi thứ tự trường, đổi mã pending...), chữ ký sẽ luôn sai → mọi IPN/query thật bị từ chối ở production, hoặc tệ hơn nếu Dev đoán sai bảng mã theo hướng lỏng (coi 1 mã lỗi là Pending) — rủi ro chấp nhận nhầm. Đây không phải lỗi code (thuật toán, thứ tự kiểm, `hash_equals`, cấu trúc đều đúng), mà là một **gate bắt buộc chưa qua được** trước khi cổng MoMo thật chạy ở production.
- Đề xuất: Không chặn merge task này (Dev đã làm hết những gì làm được trong môi trường hiện tại, đã để lại lệnh + runbook rõ ràng), nhưng **bắt buộc** ghi vào `docs/board.md` như một điều kiện go-live riêng (không phải "xong" của T17 theo nghĩa production-ready): chạy `payments:momo:verify-sandbox` với credential sandbox thật trên máy có Internet (Docker local) trước khi bật `PAYMENT_GATEWAYS=momo` ở production, đối chiếu output với tài liệu MoMo hiện hành, cập nhật `MoMoGateway`/`MoMoResultCode`/`MoMoSignerTest` (thay vector tự tạo bằng vector chính thức) nếu có sai khác, rồi mới cho `laravel-security` ký lại phần này.

### R2 [SHOULD] Phản hồi `createPayment` không verify chữ ký, chỉ so khớp partnerCode/orderId/requestId
- Vị trí: `MoMoGateway.php` — `createPayment()`, đoạn kiểm `mismatch` trước khi đọc `resultCode`/`payUrl`.
- Vấn đề: `parseNotification()` (IPN) và `queryStatus()` đều bắt buộc `hash_equals` chữ ký phản hồi trước khi tin bất kỳ field nào (đúng S12). Nhưng response của `createPayment` (POST trực tiếp tới MoMo qua TLS, path `/v2/gateway/api/create`) chỉ so khớp 3 field, không verify `signature` MoMo trả kèm theo response (theo tài liệu MoMo v2, response create cũng có `signature`). ADR-001 §1/§2 không bắt buộc điều này (interface docblock chỉ ghi "Ném GatewayUnavailableException khi timeout/lỗi mạng/resultCode != 0"), nên đây **không phải lỗi lệch spec** — nhưng vì kênh này quyết định `payUrl` mà học sinh sẽ được redirect tới (nếu response bị làm giả do misconfig DNS/proxy nội bộ hoặc lỗi cấu hình TLS ở tầng hạ tầng, `payUrl` giả có thể trỏ tới domain lừa đảo), nên thêm 1 lớp verify chữ ký giống IPN/query sẽ nhất quán và an toàn hơn về lâu dài.
- Đề xuất: Ghi TODO(T18/T19 hoặc ngay khi có vector chính thức ở R1) verify `body['signature']` của response create bằng cùng `MoMoSigner`, danh sách trường theo tài liệu MoMo. Không bắt buộc sửa ngay vì (a) đúng spec ADR hiện tại, (b) `str_starts_with($payUrl, 'https://')` đã có một lớp chặn tối thiểu, (c) nên làm cùng lúc với R1 khi đã có danh sách trường response create chính thức.

### R3 [NIT] `PaymentAttemptReference::$requestId` không được dùng trong `queryStatus()`
- Vị trí: `backend/app/Services/Payments/Data/PaymentAttemptReference.php`, `MoMoGateway::queryStatus()`.
- Vấn đề: `queryStatus()` tự sinh `requestId` UUID mới (đúng ADR-001 §2 "requestId (UUID mới)") và không đọc `$attempt->requestId` ở đâu cả trong thân hàm. Không sai, nhưng khiến người đọc code phải tự suy luận field này để dành cho việc gì (có thể là log/đối chiếu tương lai ở T18 khi map từ Eloquent model).
- Đề xuất: thêm 1 dòng comment ngắn giải thích field này dành cho tầng gọi (T18) đối chiếu/log, không dùng trong adapter — hoặc bỏ khỏi DTO nếu thực sự không cần, tuỳ Dev quyết định khi hiện thực T18.

## Đối chiếu tasks.md T17 / ADR-001

| Yêu cầu | Code đáp ứng | Ghi chú |
|---|---|---|
| `enabled_gateways` + boot guard + Fake chỉ local/testing (S4) | Có | 2 lớp: `ProductionConfigGuard::guardPayments()` (allowlist tường minh `KNOWN_GATEWAYS`, không riêng chặn `fake`) + `PaymentGatewayManager::createFakeDriver()` tự chặn nếu không phải local/testing dù có lỡ nằm trong allowlist. Test cả 2 lớp (`ProductionConfigGuardTest`, `PaymentGatewayManagerTest`) |
| `MoMoSigner` (unit test vector mẫu) | Có, nhưng vector tự tạo | Đúng thuật toán (đã tự verify độc lập bằng Python `hmac`), chưa đối chiếu vector chính thức MoMo — xem R1 |
| `accessKey` từ config; kiểm partnerCode/requestId/orderId; parse amount nghiêm ngặt; bảng mã (chỉ 0 thành công) | Có | `accessKey` luôn lấy từ `$this->config`, không bao giờ từ payload; `StrictAmountParser` từ chối thập phân/khoa học/âm/float/bool/mảng; `MoMoResultCode::toStatus()` chỉ `'0'` → Succeeded, có test `with()` đủ case |
| `orderInfo` không PII; TLS verify; log không secret/PII | Có (phần T17) | `orderInfo` do caller (T18/CheckoutService) truyền — đã có docblock cảnh báo rõ trách nhiệm; `post()` từ chối endpoint không `https://`; `client()` không gọi `withoutVerifying()` (có test tĩnh S12.8); mọi `Log::channel('payments')` chỉ log `request_id`/`gateway_order_id`/`result_code`/`status`, không có `signature`/`secretKey` (test tĩnh S12.9 + tôi đã đọc thủ công toàn bộ các lời gọi log trong `MoMoGateway.php`) |
| `queryStatus` verify chữ ký phản hồi | Có, kỹ hơn yêu cầu | Verify chữ ký bằng `hash_equals` + kiểm thêm `partnerCode`/`orderId`/**`requestId`** của response phải khớp đúng request vừa gửi (chặn được kịch bản trộn lẫn phản hồi của 1 request query khác dù chữ ký hợp lệ) — có test riêng cho case này |
| Kiểm chứng sandbox | Lệnh có, CHƯA chạy được | `payments:momo:verify-sandbox` tự chặn production, không in secret (`rawResponse` đã bỏ `signature`), có runbook chi tiết trong docblock — nhưng chưa thực thi được với sandbox thật (mạng bị chặn). Xem R1 — cần làm trước go-live, không phải điều kiện chặn merge T17 |
| Chặn cổng giả/sandbox ở production; endpoint MoMo đúng allowlist | Có | `guardMomo()` bắt buộc `scheme=https` + `host=payment.momo.vn` (không dùng `str_contains`), bắt buộc đủ `partner_code`/`access_key`/`secret_key` không rỗng |
| Việc khớp `payment_attempts` (T18) | Ngoài phạm vi T17 | Đúng như đầu bài — `PaymentAttemptReference` là DTO tạm, có docblock giải thích rõ lý do tách khỏi Eloquent model, không tạo phụ thuộc ngược |

## Gợi ý cho QA
- Khi T18/T19 nối `PaymentGatewayManager` vào `CheckoutService`/`PaymentWebhookService`: kiểm lại **thứ tự thật** trong `PaymentWebhookService::apply()` — `parseNotification()` (IPN) chỉ trả về `GatewayNotification`, KHÔNG tự so `requestId`/`orderId` với `payment_attempts` trong DB (đúng thiết kế — trách nhiệm này thuộc tầng nghiệp vụ T19 theo ADR-001 §2 mục 3 "orderId khớp attempt; requestId khớp payment_attempts.request_id"). Đảm bảo T19 không bỏ sót bước so khớp này.
- Test race `/pay` (S12.5) và test khoá dòng chuẩn (DBA #2) thuộc T18/T19, không thuộc T17 — đừng kỳ vọng thấy ở đây.
- Trước khi bật `momo` thật ở production: chạy `payments:momo:verify-sandbox`, đối chiếu bảng mã/trường ký, và yêu cầu `laravel-security` re-check nhanh phần `MoMoGateway`/`MoMoResultCode` nếu có thay đổi so với bản đã review này.
