# ADR-001: Tích hợp MoMo qua lớp trừu tượng cổng thanh toán

**Trạng thái:** Accepted (đã sửa theo Security S4, S12, S18 và DBA #2) · **Ngày:** 2026-09-25
**Story:** US-005, US-010, US-013 · **Liên quan:** [data-model.md](../architecture/data-model.md) §3.5, [api-contract.md](../architecture/api-contract.md) §2.3, §2.6

## Bối cảnh

- PO chốt MoMo cho MVP nhưng phải đổi/thêm cổng khác (VNPay, ZaloPay, chuyển khoản...) mà không viết lại nghiệp vụ.
- Quy tắc đã chốt: **không bao giờ xác nhận đơn dựa trên redirect phía trình duyệt** (redirect chỉ để hiển thị); đơn chỉ chuyển `paid` khi có **xác nhận server-to-server có chữ ký hợp lệ** từ cổng; xử lý **idempotent**; **số tiền phải khớp**; đơn `pending` quá **12 giờ** tự huỷ; 1 đơn = 1 phương thức; hoàn tiền thủ công toàn đơn.
- **Đính chính (điều phối viên, 2026-09-25):** câu "chỉ dựa vào IPN" trong US-005 là yêu cầu an toàn do người điều phối thêm vào, *không* phải quyết định của PO. Ý nghĩa đúng là "không xác nhận đơn dựa trên redirect". **Quyết định của PO (2026-09-25): BẬT đối soát chủ động** — truy vấn trạng thái giao dịch server-to-server từ MoMo, có kiểm chữ ký phản hồi, được coi là nguồn tin cậy **tương đương IPN** (§7b).
- Link thanh toán MoMo có thời hạn riêng (thường ngắn hơn 12 giờ) → 1 đơn có thể cần nhiều lần tạo link (US-005 BR14).
- MoMo yêu cầu `orderId` duy nhất cho mỗi request tạo thanh toán, số tiền là số nguyên VNĐ, có giới hạn tối thiểu (1.000đ) / tối đa; IPN phải được phản hồi HTTP 204 trong thời gian ngắn, MoMo sẽ gọi lại nếu không nhận được.

## Các phương án

| Phương án | Ưu | Nhược |
|---|---|---|
| A. Gọi MoMo trực tiếp trong `CheckoutService`/controller | Nhanh nhất lúc đầu | Logic MoMo (ký, mã kết quả) lan vào nghiệp vụ; thêm cổng = sửa luồng tiền; khó test |
| B. Package bên thứ ba đa cổng (omnipay-momo...) | Có sẵn ký/verify | Package cộng đồng, ít bảo trì, khó kiểm soát bảo mật; vẫn phải tự viết idempotency/state machine |
| **C. Interface `PaymentGateway` + adapter tự viết cho MoMo + bảng `payment_attempts` tách khỏi `orders`** | Nghiệp vụ (order, enrollment, coupon) không biết MoMo; thêm cổng = thêm 1 adapter + config; 1 đơn nhiều lần tạo link; test bằng `FakeGateway` | Viết thêm ~300 dòng adapter + ký HMAC; cần review bảo mật |

## Quyết định

Chọn **C**.

### 1. Hợp đồng (contract)

```php
namespace App\Services\Payments\Contracts;

interface PaymentGateway
{
    public function code(): string; // 'momo'

    /** Tạo giao dịch phía cổng. Ném GatewayUnavailableException khi timeout/lỗi mạng/resultCode != 0. */
    public function createPayment(PaymentRequest $request): PaymentInitResult;
    // PaymentRequest: gatewayOrderId, requestId, amount (int VNĐ), description, returnUrl, notifyUrl, expiresAt
    // PaymentInitResult: payUrl, expiresAt, rawResponse (array)

    /** Xác thực chữ ký + chuẩn hoá IPN. Ném InvalidSignatureException nếu chữ ký sai/thiếu trường. */
    public function parseNotification(Request $request): GatewayNotification;
    // GatewayNotification: gatewayOrderId, transactionId, amount (int), status (Succeeded|Failed|Pending),
    //                      resultCode, message, raw (array)

    /** Phản hồi HTTP cổng yêu cầu sau khi xử lý (MoMo: 204 No Content). */
    public function acknowledge(bool $accepted): Response;

    /** Tra cứu trạng thái chủ động server-to-server (đối soát — §7b). Ký request, verify chữ ký + partnerCode
     *  của phản hồi; phản hồi không có/sai chữ ký → ném InvalidSignatureException (coi như lỗi, không hành động).
     *  Ném GatewayUnavailableException khi timeout/lỗi mạng. */
    public function queryStatus(PaymentAttempt $attempt): GatewayNotification;
}
```

- `PaymentGatewayManager` (kế thừa `Illuminate\Support\Manager`) resolve adapter theo `config('payments.gateways.*')`, **chỉ trong allowlist `config('payments.enabled_gateways')`** (env `PAYMENT_GATEWAYS=momo`).
- **Chặn cổng giả/sandbox ở production (Security S4):**
  - `FakeGateway` (giả lập IPN + query) nằm ở `App\Services\Payments\Gateways\Fake`. Nó chỉ được đăng ký trong `AppServiceProvider` khi `app()->environment('local', 'testing')`.
  - Route webhook ràng buộc `->whereIn('gateway', config('payments.enabled_gateways'))`. Gateway lạ → 404, không 500.
  - Boot guard trong `AppServiceProvider::boot()`:
    ```php
    if ($this->app->isProduction()) {
        throw_if(in_array('fake', config('payments.enabled_gateways'), true), RuntimeException::class, 'FakeGateway bị cấm ở production');
        throw_if(! str_starts_with(config('payments.gateways.momo.endpoint'), 'https://payment.momo.vn'), RuntimeException::class, 'MoMo endpoint không phải production');
    }
    ```
  - Khoá sandbox và khoá production tách riêng theo môi trường. `MOMO_SECRET_KEY` chỉ nằm trong biến môi trường server/secret manager, có quy trình xoay khoá.
  - Test: production + `fake` → không boot; `POST /webhooks/payments/fake` và `/webhooks/payments/abc` ở production → 404.
- Route webhook chung: `POST /api/v1/webhooks/payments/{gateway}` → `PaymentWebhookService` (nghiệp vụ) → adapter chỉ lo ký/chuẩn hoá.
- **Một đường xử lý duy nhất:** cả IPN và kết quả query đều đi vào `PaymentWebhookService::apply(GatewayNotification $n, string $source /* 'ipn'|'query' */)` — cùng bước so số tiền, khoá dòng, chuyển trạng thái, fulfillment. Không có logic xác nhận riêng cho đối soát.
- Nghiệp vụ chỉ biết 3 trạng thái chuẩn hoá `Succeeded | Failed | Pending`. Bảng mã `resultCode` riêng của MoMo nằm trong `MoMoGateway`.

### 2. Adapter MoMo (API v2, `/v2/gateway/api/create`, `requestType` cấu hình — mặc định `captureWallet`)

- **Ký:** `signature = hex(HMAC_SHA256(secretKey, rawSignature))`; `rawSignature` là chuỗi `key=value` nối bằng `&`, **key sắp theo alphabet** đúng danh sách trường tài liệu MoMo quy định cho từng API (create: `accessKey, amount, extraData, ipnUrl, orderId, orderInfo, partnerCode, redirectUrl, requestId, requestType`; IPN: `accessKey, amount, extraData, message, orderId, orderInfo, orderType, partnerCode, payType, requestId, responseTime, resultCode, transId`). Tách vào `MoMoSigner` + unit test với vector mẫu trong tài liệu MoMo. So sánh chữ ký bằng `hash_equals`.
- **Kiểm IPN (Security S12):**
  1. `accessKey` trong `rawSignature` luôn lấy từ **config**, không từ request (payload IPN không có trường này).
  2. Đủ trường bắt buộc, rồi verify chữ ký bằng `hash_equals`.
  3. Chỉ sau đó mới đọc các trường còn lại. `partnerCode` khớp config; `orderId` khớp attempt; `requestId` khớp `payment_attempts.request_id`. Sai → 400 + log `rejected_signature`.
  4. `amount` parse nghiêm ngặt: chuỗi chỉ gồm chữ số (`ctype_digit`) hoặc int JSON rồi ép `int`. `"100000.0"`, `"1e5"`, số âm → từ chối.
  5. Khi apply còn kiểm `attempt.amount === order.total_amount`.
- **Bảng mã kết quả (chuẩn hoá trong `MoMoGateway`, Dev xác nhận lại với tài liệu MoMo hiện hành ở T17):**
  | `resultCode` | Trạng thái chuẩn hoá |
  |---|---|
  | `0` | `Succeeded` (**duy nhất**) |
  | `9000` (authorized/chờ capture), `1000`, `7000`, `7002` (đang xử lý) | `Pending` — **không bao giờ** `Succeeded` |
  | mã khác | `Failed` |
- `orderId` gửi MoMo = `payment_attempts.gateway_order_id` = `{order.code}-{n}`; `requestId` = UUID.
- **`orderInfo` không chứa PII (tên/SĐT/email HS)**, chỉ `"Thanh toan don hang {order.code}"`; `extraData` = chuỗi rỗng (S12, S7).
- Endpoint IPN:
  - Nginx giới hạn body **16 KB**, Laravel kiểm thêm `Content-Length` ≤ 16 KB → 413.
  - `throttle:webhook` **120 request/phút/IP**.
  - Chỉ lưu các trường đã biết vào `payment_webhook_events.payload` (bỏ `signature`).
- HTTP client:
  - Luôn verify TLS (cấm `withoutVerifying()`).
  - Endpoint lấy từ config, bắt buộc `https://`.
  - Log channel `payments` không chứa `signature`, `secretKey`, PII.
- `redirectUrl` = trang Next.js `{FRONTEND_URL}/checkout/ket-qua?order={code}` (theo design US-005; chỉ hiển thị, poll `GET /api/v1/orders/{code}` mỗi 3 giây tối đa ~1 phút; **không đọc tham số MoMo gắn vào URL để quyết định trạng thái**); `ipnUrl` = `https://api.vitaminvui.vn/api/v1/webhooks/payments/momo` (gọi thẳng Laravel, không qua Next.js).
- HTTP client: `Http::timeout(10)->connectTimeout(5)`, **không retry tự động khi tạo giao dịch** (tránh tạo 2 giao dịch; người dùng bấm "thử lại" sẽ tạo attempt mới với `orderId` mới). Log `request_id`, `gateway_order_id`, thời gian, `resultCode` (channel log riêng `payments`, không log `secretKey`/chữ ký).
- Config qua `.env`: `MOMO_ENDPOINT`, `MOMO_PARTNER_CODE`, `MOMO_ACCESS_KEY`, `MOMO_SECRET_KEY`, `MOMO_REQUEST_TYPE`, `MOMO_LINK_TTL_MINUTES`, `MOMO_IPN_URL`, `MOMO_REDIRECT_URL`, `MOMO_IP_ALLOWLIST` (tuỳ chọn).
- **Query trạng thái:** `POST /v2/gateway/api/query` (`partnerCode, requestId (UUID mới), orderId = gateway_order_id, lang, signature`); verify chữ ký phản hồi theo danh sách trường tài liệu MoMo quy định; ánh xạ `resultCode` bằng cùng bảng mã với IPN.
- **Việc Dev phải kiểm chứng ở T17 với tài liệu/sandbox MoMo hiện hành:** danh sách trường ký chính xác (create, IPN, query request và query response), mã resultCode "đang xử lý" và mã "giao dịch không tồn tại/đã hết hạn", tham số thời hạn link (nếu có, truyền `min(link_ttl, thời gian còn lại của đơn)`), hạn mức số tiền, giới hạn tần suất gọi query.

### 3. Máy trạng thái

```
("xác nhận" = IPN hoặc kết quả query, đều đã kiểm chữ ký)
orders:            pending ──xác nhận ok──▶ paid ──admin hoàn tiền──▶ refunded
                   pending ──xác nhận fail (attempt mới nhất)──▶ failed
                   pending ──quá 12h (sau đối soát lần cuối) / bị thay bởi đơn mới──▶ cancelled
                   failed|cancelled ──xác nhận ok đến muộn (tiền đã trừ)──▶ paid (+ needs_review nếu có xung đột)
payment_attempts:  created ─▶ pending ─▶ succeeded | failed | expired | error
```

Chuyển trạng thái đơn chỉ qua `OrderStateMachine` (ghi `order_status_logs` mỗi lần, `meta.source = ipn|query`).
Attempt chỉ vào trạng thái cuối `failed`/`expired` khi **cổng xác nhận** (IPN hoặc query trả kết quả không thành công/không tồn tại sau khi link hết hạn). Riêng `error` = tạo giao dịch thất bại (chưa từng có `pay_url`).

**Thứ tự khoá chuẩn duy nhất (DBA #2, trùng data-model §4; cập nhật T18 2026-10-06):** `carts → orders → payment_attempts → courses (S ở checkout / X ở fulfillment, id tăng dần) → enrollments → coupons / coupon_usages`. Luồng fulfillment ở §4 đi đúng thứ tự này (khoá `courses` X theo id tăng dần trong `grantPurchase`, `courses.enrollments_count` tăng ngay lúc đó; sau đó `coupons`/`coupon_usages`); checkout đi `carts → orders → courses(S) → coupons`. (Bản cũ đặt `enrollments → coupons → courses` — đảo thứ tự gây deadlock vì `EnrollmentService` khoá `courses` trước `enrollments`.)

**Nguyên tắc: tiền đã thực nhận (xác nhận hợp lệ, đúng số tiền) không bao giờ bị bỏ qua.** Khi trả tiền muộn (`cancelled → paid`) làm `coupons.used_count > max_uses`, hoặc vi phạm unique `coupon_usages`, đơn cũng được gắn `needs_review` (S12.7). Nếu đơn đã huỷ/hết hạn/đã trả bằng attempt khác/đã hoàn tiền → vẫn ghi nhận attempt `succeeded`, bật `orders.needs_review = true` và (nếu đơn chưa `paid`) chuyển `paid` + cấp quyền học; admin xử lý hoàn tiền thủ công phần trùng. (Tình huống hiếm vì hạn link MoMo < 12h — **cần PO đồng ý nguyên tắc này**.)

### 4. Luồng checkout & IPN

```mermaid
sequenceDiagram
  autonumber
  actor HS as Học sinh (Next.js)
  participant API as Laravel API
  participant DB as MySQL
  participant GW as MoMo
  HS->>API: GET /checkout/preview
  API-->>HS: items hợp lệ, removed_items, pricing
  HS->>API: POST /checkout {expected_total}
  API->>DB: BEGIN; SELECT carts FOR UPDATE
  API->>DB: tìm đơn pending cũ (đọc thường) → có thì khoá theo PK → cùng nội dung: dùng lại / khác: cancel(superseded)
  API->>DB: coupons FOR UPDATE → kiểm hiệu lực, HS chưa dùng, sức chứa
  API->>API: PricingCalculator → total != expected_total? → 409 CHECKOUT_CHANGED
  API->>DB: INSERT orders(pending, expires_at=+12h), order_items, status_log; COMMIT
  API->>DB: INSERT payment_attempts(created)
  API->>GW: POST /v2/gateway/api/create (ký HMAC, timeout 10s)
  alt Lỗi / timeout
    API->>DB: attempt=error (đơn vẫn pending)
    API-->>HS: 502 PAYMENT_GATEWAY_UNAVAILABLE {order_code}
  else OK
    API->>DB: attempt=pending, pay_url, expires_at
    API-->>HS: 201 {order_code, pay_url}
  end
  HS->>GW: Thanh toán trên MoMo
  GW-->>HS: redirect Next.js /checkout/ket-qua?order=... (chỉ hiển thị, poll GET /orders/{code})
  GW->>API: POST /webhooks/payments/momo (IPN)
  API->>DB: INSERT payment_webhook_events (raw)
  API->>API: verify chữ ký (sai → 400 + cảnh báo)
  API->>DB: tìm attempt theo gateway_order_id (không có → log unknown_attempt, 204)
  API->>API: amount == attempt.amount? (lệch → needs_review, log, 204, KHÔNG enroll)
  API->>DB: BEGIN; carts FOR UPDATE → orders FOR UPDATE → attempt FOR UPDATE
  alt attempt đã ở trạng thái cuối (IPN lặp)
    API->>DB: ROLLBACK; event outcome=duplicate
  else Succeeded
    API->>DB: attempt=succeeded; order=paid (paid_at, payment_reference=transId)
    API->>DB: enrollments active (bỏ qua khóa đã sở hữu → needs_review)
    API->>DB: coupon_usages INSERT + coupons.used_count+1 (unique vi phạm → needs_review)
    API->>DB: xoá cart_items các khóa đã mua; gỡ mã khỏi giỏ; courses.enrollments_count+1
    API->>DB: COMMIT → event OrderPaid (afterCommit) → queue mail xác nhận
  else Failed
    API->>DB: attempt=failed; nếu là attempt mới nhất & order pending → order=failed(gateway_failed:code)
  end
  API-->>GW: 204 No Content
```

### 5. Idempotency (BR4, BR12, AC5)

1. Khoá thật sự là **trạng thái attempt/order dưới `SELECT ... FOR UPDATE`**: IPN thứ 2 thấy attempt đã `succeeded` → không làm gì, vẫn trả 204. IPN và job đối soát đến **đồng thời** cho cùng attempt → chạy tuần tự nhờ khoá dòng `carts` → `orders` → attempt; bên đến sau thấy trạng thái cuối và ghi `outcome=duplicate`.
2. Lưới an toàn ở DB: unique `enrollments(user_id, course_id, live_flag)`, unique `coupon_usages(order_id)` và `(coupon_id, user_id)`, unique `payment_attempts(gateway, gateway_trans_id)`.
3. **Không** dùng unique trên bảng log IPN (khoá lấy từ payload chưa xác thực → có thể bị đầu độc). Log chỉ để audit.
4. Mail gửi qua event `afterCommit` + listener queued → IPN lặp không gửi mail lặp (vì event chỉ bắn khi chuyển trạng thái thực sự).

### 6. Mã giảm giá & sức chứa (US-013 BR3/BR7, US-005 edge case)

- `used_count`/`coupon_usages` chỉ ghi khi đơn `paid` (**đúng diễn giải BA ở US-013 BR7 — vẫn chờ PO xác nhận**).
- **Giữ chỗ có thời hạn (S18):** `orders.coupon_hold_until = hạn link thanh toán đầu tiên` (≤ `payments.coupon_hold_minutes` = 30). Khi tạo link mới cho đơn (`/orders/{code}/pay`) sau mốc này:
  - Khoá `carts → orders → courses(S) → coupons` và kiểm lại sức chứa.
  - Hết chỗ → huỷ đơn (`coupon_exhausted`), trả 409 `COUPON_EXHAUSTED`; HS tạo đơn mới không có mã.
  - Còn chỗ → gia hạn `coupon_hold_until`.
  - Nhờ vậy vài chục tài khoản tạo đơn pending không thể "giữ hết" mã trong 12 giờ.
- Để không vượt `max_uses` khi nhiều HS thanh toán đồng thời: lúc tạo đơn khoá dòng `coupons` và kiểm `used_count + (số đơn pending mang mã này có coupon_hold_until > now) < max_uses` (phép đếm chính xác nhờ connection chạy `READ COMMITTED` — data-model §6; mọi transaction tạo đơn có mã đều phải khoá dòng coupon trước nên không có đơn "chen" vào giữa). Đơn pending hết hạn/huỷ/thất bại tự "nhả chỗ" vì không còn được đếm. Hết chỗ → gỡ mã khỏi giỏ, trả 409 `CHECKOUT_CHANGED` (HS thấy tổng mới, bấm thanh toán lại không giảm giá — đúng US-005 edge case).
- Nếu PO đổi ý thành "trừ lượt ngay khi tạo đơn, hoàn khi huỷ": cơ chế đếm giữ nguyên, chỉ đổi cách hiển thị `used_count` → chi phí thay đổi thấp.

### 7. Hạn link vs hạn đơn 12 giờ (US-005 BR14)

- `orders.expires_at = created_at + 12h` (config `orders.pending_ttl_hours`). `payment_attempts.expires_at = now + MOMO_LINK_TTL_MINUTES` (không vượt `orders.expires_at`).
- **Chống race `/pay` (S12.5):** quyết định "dùng lại link cũ hay tạo attempt mới" và INSERT attempt mới được thực hiện **dưới khoá `carts → orders`**; truy vấn MoMo nằm ngoài transaction, rồi mở lại transaction và kiểm lại trạng thái. Nếu vi phạm unique `gateway_order_id` → trả attempt vừa được tạo bởi request kia (không 500). 2 request song song → chỉ 1 attempt mới.
- `POST /orders/{code}/pay`: attempt mới nhất còn hạn → trả lại `pay_url`; hết hạn → **đối soát attempt đó ngay (query đồng bộ)**: nếu thành công → xử lý như IPN và trả trạng thái `paid` (không tạo link mới); nếu cổng xác nhận chưa thanh toán → `expired`, tạo attempt mới (`{code}-{n+1}`) với **cùng số tiền đã chốt** của đơn; nếu query lỗi → 502 `PAYMENT_GATEWAY_UNAVAILABLE` (không tạo link mới khi chưa chắc link cũ chưa được trả). Đơn vẫn `pending` tới mốc 12h.
- Trả lời câu hỏi của Designer (design US-005 §5): `GET /orders/{code}` trả thêm `payment.link_expired` (bool) và `expires_at` của đơn → trang kết quả/"Đơn hàng của tôi" nên có **biến thể riêng** "Link thanh toán đã hết hạn" với nút "Tạo lại giao dịch" (gọi `POST /orders/{code}/pay`), khác biến thể "Thất bại" (đơn `failed` → quay lại `/checkout` tạo đơn mới).
- Scheduler `orders:expire-pending` (5 phút/lần, `withoutOverlapping()->onOneServer()`) — **luôn đối soát lần cuối trước khi huỷ** để không huỷ nhầm đơn đã trả tiền:
  1. Lấy đơn `pending` có `expires_at <= now` (lô 100, `chunkById`).
  2. Với mỗi attempt chưa ở trạng thái cuối của đơn: gọi `queryStatus` **ngoài transaction** → kết quả đưa vào `PaymentWebhookService::apply(..., 'query')`.
  3. Mở transaction, khoá `carts` → `orders`, đọc lại: đơn đã `paid` (do bước 2 hoặc IPN chen vào) → bỏ qua; còn `pending` và mọi attempt đã được cổng xác nhận là chưa thanh toán → `cancelled` (`expired_12h`), attempt → `expired`.
  4. Query lỗi/timeout (MoMo chậm/sập) hoặc cổng vẫn báo `Pending` → **hoãn huỷ**, thử lại ở lượt chạy sau, tối đa `payments.reconcile.cancel_grace_minutes` (mặc định 120). Hết thời gian ân hạn → vẫn huỷ đơn (`expired_12h_unverified`), attempt **giữ nguyên chưa kết thúc** để job đối soát (§7b) tiếp tục kiểm; nếu sau đó xác nhận đã trả tiền → áp dụng nguyên tắc "tiền đến muộn" (§3: `cancelled → paid` + `needs_review`).
  5. Không đụng giỏ hàng (BR6/BR7).

### 7b. Đối soát chủ động với MoMo — **Quyết định của PO (2026-09-25): BẬT mặc định**

Mục đích: cứu trường hợp IPN bị mất/chậm (MoMo lỗi, mạng, server mình tạm sập lúc MoMo gọi) và đảm bảo không huỷ nhầm đơn đã trả tiền.

| Hạng mục | Quy định |
|---|---|
| Bật/tắt | `config('payments.reconcile.enabled')` ← `PAYMENTS_RECONCILE_ENABLED=true` (mặc định **bật**; chỉ tắt khẩn cấp, vd MoMo giới hạn tần suất) |
| Lịch | Command `payments:reconcile` **mỗi 5 phút**, `withoutOverlapping()->onOneServer()`; command chỉ chọn attempt đến hạn và dispatch `ReconcilePaymentAttemptJob` (queue `default`, `ShouldBeUnique` theo `attempt_id`, `tries 3`, backoff 30/120s, timeout 30s) |
| Phạm vi | `payment_attempts` có `pay_url` (đã tạo giao dịch thành công) và **chưa ở trạng thái cuối** (`status = pending`), `next_check_at <= now`, bất kể trạng thái đơn (đơn `pending`, hoặc đơn đã `cancelled`/bị thay thế mà attempt chưa được cổng xác nhận). Không query attempt `error` (chưa từng tạo giao dịch ở MoMo) |
| Nhịp kiểm mỗi attempt | Lần đầu sau **3 phút** kể từ khi tạo (để IPN về trước), sau đó giãn dần 5 → 10 → 20 → 30 phút (tối đa 30); lưu `next_check_at`, `check_count`, `last_checked_at` trên attempt |
| Dừng | Cổng trả kết quả cuối (thành công/thất bại) → xử lý và dừng. Link đã hết hạn quá 30 phút và cổng xác nhận chưa thanh toán/không tồn tại → attempt `expired`, dừng. Query lỗi liên tục > 24 giờ sau khi link hết hạn → dừng, `orders.needs_review = true`, log cảnh báo cho admin |
| Xử lý kết quả | Kết quả `Succeeded`/`Failed` → **`PaymentWebhookService::apply($n, 'query')`** — cùng đường idempotent với IPN (§4, §5): **số tiền phải khớp `attempt.amount`** (lệch → không enroll, `needs_review`, log), khoá dòng theo thứ tự chuẩn, không lặp hiệu ứng phụ. `Pending` → chỉ cập nhật `next_check_at` |
| Chữ ký | Phản hồi query phải có chữ ký hợp lệ + `partnerCode` + `orderId` khớp attempt; sai → coi như lỗi (không hành động), log `rejected_signature` |
| Nhật ký | Mỗi lần query ghi 1 dòng `payment_webhook_events` với `source = 'query'` (cùng bảng với IPN) |
| Tải | Mỗi lượt tối đa `payments.reconcile.batch_size` (mặc định 100) attempt; với ~300 đơn/ngày số lần query là rất nhỏ |
| Theo yêu cầu người dùng | Nút "Kiểm tra lại" ở trang `/checkout/ket-qua` (design US-005): `POST /orders/{code}/check-payment` (chủ đơn, throttle 1 lần/30 giây) → đối soát ngay attempt mới nhất rồi trả trạng thái đơn |

Tương tác với các luồng khác: IPN đến sau khi job đã xác nhận → `duplicate`; job huỷ 12h luôn đối soát trước (§7); tạo lại link (`/orders/{code}/pay`) luôn đối soát link cũ trước (§7).

### 8. Trường hợp số tiền đặc biệt (**chờ PO**)

- Tổng sau giảm = 0đ (mã 100%): flag `zero_total_checkout` (mặc định BẬT) → `markPaid` ngay trong transaction tạo đơn, `payment_method = 'none'`, không qua MoMo. Mã 100% **bắt buộc có `max_uses` + `valid_until`** (CHECK ở DB — data-model §3.5), tạo mã như vậy được ghi `audit_logs` (S18).
- Tổng 1–999đ: MoMo từ chối → trả 422 `AMOUNT_BELOW_GATEWAY_MIN`. Đề xuất PO: cấm tạo mã fixed khiến tổng rơi vào khoảng này, hoặc làm tròn giảm giá.

### 9. Hoàn tiền (US-010)

`RefundService::refund(Order, User $actor, ?string $note)`: ghi `audit_logs` (`order.refund`); transaction, khoá order; chỉ `paid → refunded`; `enrollments` của đơn `active → revoked` (reason `refund`), giảm `enrollments_count`; ghi log. Lần 2 → 409 `ALREADY_PROCESSED`. Không gọi API hoàn tiền MoMo (ngoài phạm vi); `payment_reference` hiển thị để admin tra trên cổng MoMo.

### 10. Thêm cổng mới sau này

1 class implement `PaymentGateway` + khối config + `.env` + test ký/verify. Không đổi bảng, không đổi `CheckoutService`/`PaymentWebhookService`/`OrderFulfillmentService`. Frontend thêm lựa chọn `gateway` (BR2: vẫn 1 đơn 1 phương thức — `payment_method` ghi theo attempt thành công).

## Hệ quả

- (+) Nghiệp vụ tiền độc lập cổng; test end-to-end bằng `FakeGateway` (ký giả, bắn IPN giả) không cần sandbox.
- (+) Truy vết đầy đủ: attempts + raw IPN/query + status logs cho đối soát/tranh chấp.
- (+) Đối soát chủ động: mất IPN không làm mất đơn; không huỷ nhầm đơn đã trả tiền ở mốc 12h.
- (−) Phụ thuộc thêm API query của MoMo (tần suất, chữ ký phản hồi); job huỷ đơn có thể hoãn tối đa 2 giờ khi MoMo sập.
- (−) Thêm 2 bảng và 1 máy trạng thái; Dev phải tuân thủ thứ tự khoá (data-model §4).
- (−) IPN URL phải truy cập được từ Internet ở môi trường dev (dùng tunnel như ngrok/cloudflared) — ghi vào hướng dẫn dev.
- Bắt buộc `laravel-security` review code T17–T20: verify chữ ký, bảng mã, so khớp số tiền, allowlist gateway + boot guard, giới hạn body IPN, race `/pay`, log không lộ secret/PII.
