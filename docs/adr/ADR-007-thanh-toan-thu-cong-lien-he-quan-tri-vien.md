# ADR-007: Thanh toán thủ công "Liên hệ Quản trị viên" (phương thức `manual`)

**Trạng thái:** Proposed (thiết kế 2026-10-08; PO chưa trả lời Q1–Q16 của US-022, thiết kế dùng mặc định của BA, mọi con số/kênh đổi bằng cấu hình)
**Liên quan:** US-022, US-005, US-010; task T38, T24 (thu gọn), T39, FW3, FA8; ADR-001 (cổng thanh toán, giữ chỗ mã, thứ tự khoá), ADR-006 (thư phụ huynh khi đơn có tiền `paid`, xoá tài khoản); api-contract §1.6, §1.7, §2.1, §2.3, §2.3.1, §2.5, §2.5.1; data-model §3.5; thiết kế chi tiết `docs/tech/US-022.md`
**Thay thế tạm thời:** US-005 BR7 (12 giờ), BR10–BR14 (luồng MoMo) cho đơn `payment_method = manual`. ADR-001 vẫn nguyên hiệu lực cho MoMo.

## Bối cảnh
- Thanh toán có tiền đang tắt bằng `FEATURE_PAID_CHECKOUT=false` (PO 2026-10-06). `ProductionConfigGuard` chặn bật cờ này khi `payments.ipn_ready=false`, tức khi chưa có T19 (IPN) và T20 (đối soát).
- PO 2026-10-08 muốn bán ngay: giỏ hàng và checkout chạy như cũ, nhưng ở bước chọn phương thức thì **ẩn MoMo** và chỉ có "Liên hệ Quản trị viên". Quản trị viên liên hệ học sinh, nhận tiền ngoài hệ thống, rồi **duyệt** đơn.
- Code đã có và chạy được: `CheckoutService` (một đơn chờ/học sinh qua `orders_user_pending_unique`, chốt giá, giữ chỗ mã `coupon_hold_until`, huỷ đơn cũ `superseded`), `OrderFulfillmentService::markPaid` (đường duy nhất cấp enrollment, ghi `coupon_usages`, dọn giỏ, thư phụ huynh sau commit), `OrderStateMachine`. `AccountAnonymizer::livePaymentUntil` (T34) chỉ chặn xoá tài khoản khi có link MoMo còn sống.
- Ràng buộc: không xoá code MoMo; bật lại MoMo sau này không phải sửa luồng thủ công (US-022 AC30).

## Các phương án

### 1. Cờ bật/tắt
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Dùng lại `FEATURE_PAID_CHECKOUT` | Không thêm cờ | Guard chặn cờ này khi `ipn_ready=false`. Muốn bật thủ công thì phải nới guard, và khi đó MoMo cũng lọt qua (lúc chưa có IPN) |
| **B. Cờ riêng `FEATURE_MANUAL_PAYMENT`** | Hai phương thức bật/tắt độc lập. Guard của MoMo giữ nguyên | Thêm 1 cờ; FE phải đọc danh sách phương thức thay vì 1 boolean |

### 2. `manual` là "cổng" hay là "phương thức"
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Viết `ManualGateway` cài `PaymentGateway` (T17), thêm `manual` vào `PAYMENT_GATEWAYS` | Đi chung đường `resolveAttempt` | `enabled_gateways` là allowlist cho route webhook `/webhooks/payments/{gateway}` → mở route webhook giả cho `manual`. Phải tạo `payment_attempts` không có ý nghĩa (không link, không `request_id` thật). Hạn mức min/max của cổng áp nhầm. `createPayment` không trả `pay_url` → phá giả định của CheckoutService |
| **B. Khái niệm "phương thức thanh toán" đứng trên cổng: `manual` + các cổng đang bật** | Không đụng webhook/attempt. Nhánh `manual` trong checkout ngắn (không attempt, không gọi mạng). Danh sách phương thức do server quyết ở một chỗ (`PaymentMethods`) | Thêm 1 class nhỏ và 1 field request mới |

### 3. Field chọn phương thức ở `POST /checkout`
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Mở rộng `gateway` nhận thêm `manual` | Không thêm field | Tên sai nghĩa; `CheckoutRequest` đang kiểm `gateway ∈ enabled_gateways`; trộn 2 khái niệm của mục 2 |
| **B. Field mới `payment_method` (`manual` \| tên cổng), giữ `gateway` làm bí danh cũ** | Đúng nghĩa, khớp cột `orders.payment_method`. Tương thích v1: chỉ thêm field | Phải định nghĩa quy tắc khi gửi cả hai |

### 4. Duyệt đơn đi đường nào
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Service duyệt riêng tự cấp enrollment | Tách bạch | Có 2 đường cấp quyền học từ đơn, vi phạm quy tắc "markPaid là đường duy nhất". Phải lặp lại logic coupon/giỏ/thư phụ huynh/needs_review |
| **B. `markPaid($order, 'manual', ...)` + 2 móc: `guard` (kiểm điều kiện duyệt dưới khoá `orders`, ném lỗi để không đổi gì) và `after` (ghi `confirmed_by`, ghi chú, audit trong CÙNG transaction)** | Một đường cấp quyền duy nhất; idempotent và retry deadlock có sẵn; thư phụ huynh (ADR-006) tự đúng | `markPaid` thêm tham số; phải giữ móc thuần (không gọi mạng, không khoá bảng ngoài thứ tự chuẩn) |

### 5. Đơn chờ khác nội dung khi học sinh đặt lại
- Hiện tại: tự huỷ đơn cũ (`superseded`) không hỏi. Hợp với MoMo (chưa trả tiền thì link cũ không còn tác dụng).
- Với `manual`: học sinh có thể **đã chuyển khoản** cho đơn cũ. **Chọn:** khi đơn chờ hiện có là `manual` và **chưa quá hạn**, mà nội dung khác → 409 `PENDING_ORDER_EXISTS`; học sinh gửi lại với `replace_pending=true` mới huỷ. Đơn chờ MoMo giữ hành vi cũ.

### 6. Hạn chờ và giữ chỗ mã
- `expires_at = created_at + orders.manual.pending_ttl_hours` (72). Cấu hình riêng, không đổi `orders.pending_ttl_hours` (12) của MoMo.
- `coupon_hold_until = expires_at` (giữ chỗ lượt mã suốt thời gian chờ, US-022 Q15). `CouponCapacity` không đổi: vẫn đếm đơn `pending` có `coupon_hold_until > now`.
- Lệnh `orders:expire-manual` mỗi 15 phút chỉ quét đơn `manual`. Job huỷ 12 giờ cho MoMo vẫn thuộc T20.

### 7. `paid_checkout_enabled` trong `/config/public`
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Giữ nghĩa cũ (= cờ MoMo), thêm `checkout_enabled` mới | Không đổi nghĩa field cũ | FE có 2 boolean gần giống nhau |
| **B. `paid_checkout_enabled` = "có ít nhất 1 phương thức thanh toán có tiền", thêm `payment_methods[]`** | Đúng điều FE đang dùng field này để quyết (ẩn/hiện nút "Mua"). Không có FE cũ nào bị sai: web chưa có trang giỏ/checkout (FW3 chưa làm) | Đổi định nghĩa field, nhưng hành vi hướng tới người dùng ("có mua được khóa có phí không") giữ nguyên |

## Quyết định
1. **Cờ riêng `FEATURE_MANUAL_PAYMENT`** (`features.manual_payment`, mặc định `false` trong code; `.env.example` local/dev đặt `true`). Không liên quan `payments.ipn_ready`.
2. **`manual` là phương thức, không phải cổng.** Không thêm vào `PAYMENT_GATEWAYS`, không có driver, không tạo `payment_attempts`, không có route webhook. Class mới `App\Services\Orders\PaymentMethods`:
   - `available(): list<string>` = `['manual']` nếu `features.manual_payment`, cộng các cổng trong `payments.enabled_gateways` nếu `features.paid_checkout`. Thứ tự: `manual` trước (mặc định khi request không chọn).
   - `isAvailable(string)`, `describe(): list<{code,label,description}>` cho preview.
   - Mọi chỗ trước đây đọc thẳng `features.paid_checkout` để quyết "mua có tiền được không" (PublicConfigController, CheckoutPreviewResource, CheckoutService) chuyển sang `PaymentMethods`.
3. **`POST /checkout` nhận `payment_method`**; `gateway` thành bí danh cũ (deprecated). Gửi cả hai mà khác nhau → 422. Không gửi gì → phương thức đầu tiên của `available()`.
4. **Duyệt = `markPaid($order, 'manual', $reference, guard, after)`**, nguồn `manual`, actor `staff` + id. `status_reason = manual_confirmed`. Chế độ khóa nghiêm (`strictCourses`): khóa đã xoá → ném 409 `COURSE_UNAVAILABLE`, rollback toàn bộ (US-022 AC24: không duyệt một phần). IPN/0đ giữ hành vi cũ (bật `needs_review`).
5. **Một đơn chờ/học sinh** (unique cũ, áp mọi phương thức). Thay đơn `manual` đang chờ phải có `replace_pending=true`.
6. **72 giờ + giữ chỗ mã tới `expires_at`**, tác vụ hết hạn riêng (mục 6 ở trên).
7. **Duyệt muộn**: đơn `manual` đã `cancelled` (mọi lý do trừ `account_deleted`) trong `orders.manual.approval_window_days` (30) kể từ `cancelled_at`; bắt buộc `late=true`; `markPaid` tự bật `needs_review` (`late_payment`). Quá hạn → 409 `ORDER_APPROVAL_WINDOW_PASSED`.
8. **Xoá tài khoản bị chặn** khi có đơn `manual` `pending` (409 `ACCOUNT_HAS_PENDING_PAYMENT`, `retry_after_at = expires_at`). Pha B (T34) gặp đơn `manual` `pending` do race thì **giữ** đơn (không huỷ) và không coi là "chưa xong"; `orders:expire-manual` dọn sau.
9. **`paid_checkout_enabled` = có ≥ 1 phương thức**, thêm `payment_methods[]` và khối `manual_payment` (kênh liên hệ, hạn chờ) vào `/config/public`.
10. **Thư** (tất cả `ShouldQueue` + `ShouldBeEncrypted` + `afterCommit`, lỗi gửi không làm hỏng thao tác): học sinh nhận "đã nhận đơn", "đã thanh toán" (`OrderPaidMail`, dùng chung cho T19), "đơn bị huỷ" (admin huỷ / hết hạn); hộp thư quản trị (`orders.manual.notify_emails`) nhận "đơn mới" **không có PII**. Tài khoản đã ẩn danh: không gửi thư học sinh/phụ huynh.
11. **Guard production/staging khi `FEATURE_MANUAL_PAYMENT=true`:** có ít nhất 1 kênh liên hệ; `PAYMENT_CONTACT_ZALO_URL` (nếu có) dạng `https://zalo.me/…`; email kênh và mọi địa chỉ `ORDERS_MANUAL_NOTIFY_EMAILS` hợp lệ, danh sách nhận thư không rỗng; `ORDERS_MANUAL_PENDING_TTL_HOURS` trong 1..168; `ORDERS_MANUAL_APPROVAL_WINDOW_DAYS` trong 0..90; `ORDERS_MANUAL_PER_DAY` trong 1..50. Guard `FEATURE_PAID_CHECKOUT` + `ipn_ready` giữ nguyên. Thêm các biến mới vào `ENV_KEYS_NO_INLINE_COMMENT`.

## Hệ quả
- **Tích cực:** bán được khóa có phí ngay mà không cần MoMo; MoMo bật lại chỉ bằng cờ (`PaymentMethods` trả cả hai); một đường cấp quyền học duy nhất; thư phụ huynh, `needs_review`, hoàn tiền (T24) dùng chung.
- **Rủi ro mới:**
  - **Lạm quyền nội bộ:** Admin/QLT duyệt đơn không thu tiền = cấp khóa miễn phí. Giảm nhẹ: audit `order.manual_approve` (người, thời điểm, có mã giao dịch hay không), `confirmed_by` trên đơn, lọc danh sách theo người duyệt (backlog), đối soát sao kê ngoài hệ thống. Không chặn kỹ thuật được; cần PO chấp nhận.
  - **Giữ chỗ mã 72 giờ:** nhiều tài khoản đã xác thực có thể giữ hết lượt của mã có `max_uses` nhỏ. Giảm nhẹ: 1 đơn chờ/học sinh, 5 đơn/ngày/học sinh, admin huỷ được đơn. Mã giới hạn lượt nên đặt `max_uses` có dư.
  - **Spam hộp thư quản trị:** giới hạn ở trên + bắt buộc tài khoản đã xác thực; thư quản trị là 1 thư/đơn mới (không gửi khi dùng lại đơn).
- **Nợ:** `gateway` trong `POST /checkout` thành deprecated (bỏ ở v2). `paid_checkout_enabled` đổi định nghĩa (ghi trong api-contract). Màn "Đang chờ duyệt" trên thẻ khóa (`viewer_state`) để backlog.
- **Không đổi:** `payments.enabled_gateways`, route webhook, `payment_attempts`, `OrderStateMachine::ALLOWED` (đã có `cancelled → paid`), thứ tự khoá chuẩn `carts → orders → courses → enrollments → coupons`.

## Điểm chờ PO (đang dùng mặc định BA)
Q1 kênh liên hệ thật (SĐT, Zalo, giờ hỗ trợ) · Q2 không hiện STK/QR (đổi thì cần khối văn bản cấu hình, ảnh hưởng thiết kế FW3) · Q3 72 giờ · Q4 admin + QLT duyệt · Q5/Q6 thư · Q11 cửa sổ duyệt muộn 30 ngày · Q13 5 đơn/ngày · Q15 giữ chỗ mã suốt thời gian chờ · chấp nhận rủi ro "lạm quyền nội bộ" ở trên.
