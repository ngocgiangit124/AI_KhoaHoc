# SECURITY: Cụm 3 — Thanh toán, giỏ hàng, checkout (T14 phần đơn, T15–T18, khoá `FEATURE_PAID_CHECKOUT`) | 2026-10-06
**Kết luận:** PASS có điều kiện

Không có Critical/High. **Khoá thanh toán kín:** khi cờ tắt, không có đường nào tạo đơn tính tiền, tạo `payment_attempt`, gọi `createPayment` hay kích hoạt ghi danh mà không trả tiền (ngoài mã giảm 100%/cố định do admin phát hành, đúng thiết kế US-013). Có 1 Medium (M1) và 4 Low, 4 Info. Điều kiện của "PASS": sửa M1 trước khi lên production, hoặc tạm đặt `PAYMENT_GATEWAYS` khác rỗng. Nếu không, đơn 0đ (mã 100%) sẽ hỏng trên production với file mẫu hiện tại. Các mục T17-x, T18-x đã có trong `backlog-v2.md` không báo lại; không mục nào nặng hơn đánh giá cũ.

**Phạm vi:** commit `2cf9de0` trên `main`, cộng working tree của "Sửa lỗi nhỏ 3" phần `CartService::applyCoupon`, `CartCouponController` và `config/orders.php` (trần sai mã theo IP). Đã đọc: `routes/{api,admin}.php`, `Services/Cart/**`, `Services/Orders/**`, `Services/Payments/**` (MoMoSigner, MoMoGateway, FakeGateway, Manager), `Services/Coupons/CouponService.php`, `EnrollmentService::{requestFree,grantPurchase}`, `CouponPolicy`, các FormRequest cart/checkout/coupon, các model Order/PaymentAttempt/CouponUsage/Cart/CartItem/OrderItem/Coupon, migration `orders`, `config/{features,payments,orders,database,logging}.php`, `ProductionConfigGuard`, `ApiExceptionRenderer`, `AtomicCounter`, `infra/production/.env.production.example`, `docs/ops/production-checklist.md`. Đối chiếu: ADR-001, `review/T18.md`, `review/checkout-lock-T30.md`, `qa/checkout-lock-T30.md`, `review/minor-fixes-3.md`, `backlog-v2.md`.

**Cách kiểm:** phân tích tĩnh, kết hợp thử thật bằng curl qua nginx :8000 (host `api.localhost`, Origin `http://api.localhost:3000`, phiên Sanctum thật). Cờ lúc chạy: `paid_checkout=false`, `zero_total_checkout=true`, `enabled_gateways=["fake"]`, `APP_ENV=local`. Dữ liệu thử có tiền tố `sec-test-`: 1 GV, 1 admin, 2 HS đã xác thực, 5 khóa (2 có phí, 1 nháp, 1 miễn phí, 1 có phí 300k), 3 mã (`SEC-TEST-P100` 100% max_uses=1, `SEC-TEST-P50C1` 50% chỉ cho C1, `SEC-TEST-FIX40K` cố định 400k không giới hạn). **Đã dọn hết**: users, courses, coupons, orders, enrollments, carts, coupon_usages, cả 3 dòng `audit_logs` do thao tác thử sinh ra. Không gọi MoMo thật. Pest `T17 + T18` (trừ race) trên DB e: **178 passed, 547 assertions**.

## A. Khoá thanh toán: kết quả xác nhận

| Đường có thể tạo tiền/ghi danh | Kết quả |
|---|---|
| `POST /checkout` tổng > 0, cờ tắt | 503 `PAYMENT_DISABLED` (thử thật). Throw ở `CheckoutService.php:210`, nằm trong transaction, **trước** `createOrder`, trước nhánh dùng lại hoặc huỷ đơn pending, trước `resolveAttempt`. Không có đơn nào được tạo, `payment_attempts` vẫn là 0. |
| Thêm tham số lạ (`total:0`, `paid_checkout:true`, `price`), `expected_total` kiểu chuỗi | Bị bỏ qua (chỉ đọc `validated()`), vẫn 503. Không có mass assignment: mọi model của cụm đều `$fillable = []`, chỉ ghi bằng `forceFill` ở service. |
| `gateway` = `momo` / `FAKE` (ngoài allowlist hoặc khác hoa thường) | 422 (`Rule::in`). |
| `expected_total` sai (0, âm, số thực) | 0 → 409 `CHECKOUT_CHANGED`; âm/số thực → 422. Không có đường đi "hạ giá" nào. |
| Gọi `initiatePayment` / `createPayment` | Chỉ có 1 chỗ gọi (`CheckoutService::checkout` dòng 137), và chỉ chạy sau khi `prepare()` trả attempt mới; `prepare()` đã throw khi cờ tắt. Không có command, job hay route nào khác gọi tới. |
| `markPaid` / `grantPurchase` | `markPaid` chỉ được gọi ở `CheckoutService:130` khi `total_amount === 0`. `grantPurchase` chỉ được gọi từ `markPaid`. Không có route IPN, `/orders/{code}/pay`, `check-payment` (grep `routes/`: chỉ có webhook video). |
| Đơn pending cũ có `pay_url` khi cờ tắt | Không có route nào trả `pay_url` ngoài response của `POST /checkout` (đã bị 503). QA đã thử: 503, đơn không bị `superseded`, không tạo attempt mới. |
| Khóa miễn phí qua giỏ | `POST /cart/items` khóa giá 0 → 422; `classify()` loại `price <= 0`. `free-enrollments` chỉ tạo `pending_approval` (cần duyệt) và chặn khóa có phí (`COURSE_NOT_FREE`). |
| Đơn 0đ: mã 100% có giới hạn (P100, max_uses=1), 2 HS checkout **đồng thời** | Một HS 201 `paid`, HS kia 409 `COUPON_EXHAUSTED`. DB: 1 đơn, 1 `coupon_usages`, `used_count=1`, `needs_review=false`. |
| Dùng lại mã sau khi đã dùng | Đã hết lượt → 422 `COUPON_EXPIRED`. Nâng `max_uses` lên 5 → 422 `COUPON_ALREADY_USED` (unique `(coupon_id,user_id)` và `alreadyUsedBy`). |
| 3 checkout 0đ đồng thời của cùng HS | 1 đơn `paid`, 2 request bị 429 (throttle `checkout`). Không có ghi danh trùng. |
| Mã cố định không giới hạn làm giỏ về 0đ | `SEC-TEST-FIX40K` (400k) áp lên giỏ 650k → giảm 400k. Gỡ bớt khóa còn 350k → mã tự gỡ (`COUPON_REMOVED`), vì `zeroesCartUnbounded`. Mã % < 100 không thể đưa về 0 (dùng `intdiv` làm tròn xuống). Mã 100% bắt buộc có `max_uses` và `valid_until` ở cả create lẫn update (`CouponService::assertLimits`). |

## B. Các điểm đạt khác (đã kiểm)
- **Thao túng giá:** giá luôn lấy từ DB. `prepare()` đọc lại `courses` với `FOR SHARE` và so giá, trạng thái, xoá mềm → `ITEMS_CHANGED`. Mã được đánh giá lại dưới khoá `coupons FOR UPDATE`. Client chỉ gửi `expected_total`. CHECK DB `subtotal = discount + total`, `unit_price = discount + final`, cột `unsigned`.
- **Khóa trùng / số lượng âm:** không có trường số lượng. Unique `(cart_id, course_id)` → 409 `ALREADY_IN_CART`. `course_id` âm, chuỗi, mảng → 422. Khóa nháp/xoá → 422. Khóa đã sở hữu → 409 `ALREADY_OWNED` và bị loại khỏi đơn (`classify`).
- **Phạm vi mã:** `P50C1` chỉ giảm dòng C1 (đã thử). Pivot rỗng sau cascade không bị coi là áp toàn bộ.
- **Race `max_uses`:** khoá `coupons FOR UPDATE` + `CouponCapacity` (tính cả đơn pending còn hạn giữ chỗ), kết nối READ COMMITTED (`config/database.php:68`, cả `--transaction-isolation` của MySQL local). Đã thử race thật (bảng A).
- **Giữ chỗ mã để chặn người khác:** khi cờ tắt không làm được, vì đơn tổng > 0 không được tạo, còn đơn 0đ chuyển `paid` ngay. Khi bật cờ (V2) vẫn còn T18-5.
- **IDOR:** chưa có route đọc đơn. Giỏ luôn lấy theo `user_id` của phiên, và `DELETE /cart/items/{course}` chỉ xoá trong giỏ của chính mình. Route admin mã giảm giá: `CouponPolicy` chỉ cho admin và quản lý trang (`isStaff`); GV và HS → 403. `CouponIndexRequest`/`CouponRequest` kiểm quyền trước validate.
- **MoMo signer:** HMAC-SHA256 hex. Danh sách trường cố định theo alphabet, kiểu dữ liệu được kiểm (bool, mảng → lỗi). So sánh bằng `hash_equals`. Secret rỗng → false. Verify chữ ký **trước** khi đọc trường; sau đó kiểm `partnerCode` (timing-safe), `amount` chặt (`"1e5"`, `"100000.0"`, số âm → loại), `resultCode` nguyên. Mã lạ: IPN → Failed, query → Pending (fail-closed). Query đối chiếu `orderId` và `requestId` của chính request. `accessKey` lấy từ config, không lấy từ payload. Chỉ lưu các trường đã biết (`knownFields`).
- **Gọi ra ngoài (SSRF/TLS):** endpoint chỉ từ config, bắt buộc `https`, `withoutRedirecting`, có timeout. `pay_url` qua allowlist host, chặn userinfo, cổng ≠ 443, ký tự điều khiển, backslash. Guard production ép `payment.momo.vn` và cấm `fake`.
- **Lộ secret/PII:** log `payments` chỉ có `request_id`, `gateway_order_id`, `result_code`, `duration_ms`. `create_response` đã bỏ `signature`, không chứa `accessKey`/`secretKey`. `orderInfo` = mã đơn, không có PII. `zend.exception_ignore_args = On` (`infra/php/conf.d/zz-vitaminvui.ini`) nên secret truyền vào `sign()` không lọt vào stack trace. Lỗi 5xx ở production dùng thông điệp chung.
- **Rate limit:** `throttle:checkout` 10/phút/identity; `throttle:coupon` 10/phút + 60/giờ/IP; trần 30 lần sai/ngày/HS + 150/ngày/IP (Sửa lỗi nhỏ 3, đếm nguyên tử bằng Lua, hoàn lượt khi áp thành công hoặc lỗi hạ tầng).
- **Audit:** `coupon.create/update/activate/deactivate/delete` (có cờ `high_risk`), `enrollment.grant` kèm `order_id`, `order_status_logs` cho mọi chuyển trạng thái.

## Phát hiện

### M1 [Medium] Đơn 0đ hỏng khi `PAYMENT_GATEWAYS` rỗng, mà đây đúng là giá trị trong mẫu production của V1 — OWASP A04/A05
- Vị trí: `backend/app/Services/Orders/CheckoutService.php:104-107` (kiểm cổng trước mọi thứ, kể cả khi tổng = 0); `backend/app/Http/Requests/Checkout/CheckoutRequest.php:29-34` (`gateway()` trả `''` khi danh sách rỗng); `infra/production/.env.production.example:87` (`PAYMENT_GATEWAYS=` "để trống khi MVP"); `docs/ops/production-checklist.md:67` ghi "Đơn 0đ ... không phụ thuộc cổng thanh toán".
- Mô tả & tác động: đã tái hiện bằng tinker với `config(['payments.enabled_gateways' => []])`, `CheckoutRequest` hợp lệ, `expected_total=0` → `ValidationException gateway: "Phương thức thanh toán không được hỗ trợ."` (422). Trên production V1 theo mẫu hiện tại, mọi đơn dùng mã 100% hoặc mã cố định làm tổng về 0 sẽ bị 422, trong khi preview vẫn báo `can_checkout=true`. Đây không phải lỗ hổng lộ hay chiếm dữ liệu. Nhưng nó làm hỏng luồng duy nhất còn mở của checkout, và đẩy vận hành tới chỗ "sửa nhanh" bằng cách đặt `PAYMENT_GATEWAYS=momo` khi chưa có khoá MoMo, chưa qua T17-1. Không được đặt `fake` (guard chặn, đúng).
- Cách sửa: chỉ kiểm cổng khi đơn cần thanh toán. Gợi ý:
  ```php
  // CheckoutService::checkout — bỏ kiểm cổng ở đầu hàm, chuyển vào prepare() SAU khi đã có $pricing:
  if ($pricing->total > 0) {
      if (! config('features.paid_checkout')) {
          throw new DomainException('PAYMENT_DISABLED', 'Thanh toán trực tuyến đang tạm khoá.', 503);
      }
      if (! in_array($gateway, $this->gateways->enabled(), true)) {
          throw ValidationException::withMessages(['gateway' => ['Phương thức thanh toán không được hỗ trợ.']]);
      }
  }
  ```
  Lưu ý: throw trong `prepare()` sẽ rollback cả phần gỡ mã. Nếu muốn giữ đúng thứ tự lỗi đã ghi trong contract (cổng 422 → giỏ trống → CHANGED → DISABLED), thì giữ kiểm cổng ở đầu hàm nhưng bỏ qua khi `$gateway === ''` và danh sách rỗng, rồi để `prepare()` throw 422 nếu tổng > 0. `CheckoutRequest::gateway()` trả `''` là đủ. Cập nhật contract (thứ tự lỗi) và `production-checklist.md`.
- Cách kiểm chứng: test `config(['payments.enabled_gateways' => []])`: giỏ 0đ (mã 100%) → 201 `paid`, có enrollment; giỏ > 0 với cờ tắt → 503 `PAYMENT_DISABLED`; giỏ > 0 với cờ bật → 422 `gateway`, không tạo đơn.

### L1 [Low] Khoá thanh toán chỉ dựa vào một biến môi trường; bật nhầm trước khi xong T19 sẽ thu tiền mà không cấp quyền — OWASP A04/A05
- Vị trí: `backend/config/features.php:33`; `backend/app/Support/ProductionConfigGuard.php:90-95` (chỉ kiểm cổng không rỗng); `CheckoutService::notifyUrl()` trỏ tới `/api/v1/webhooks/payments/{gateway}`, nhưng route này chưa tồn tại.
- Mô tả & tác động: nếu ai đó đặt `FEATURE_PAID_CHECKOUT=true` và `PAYMENT_GATEWAYS=momo` (có khoá thật) trước khi T19/T20 xong, checkout sẽ tạo link MoMo và HS trả tiền được. Nhưng IPN rơi vào 404, không có job đối soát, đơn bị huỷ sau 12h, HS mất tiền mà không được ghi danh. Guard hiện không bắt trường hợp này.
- Cách sửa: thêm vào `guardPaidCheckout()` điều kiện "cờ bật thì phải có route `api.webhooks.payments`" (hoặc một hằng `PAYMENTS_FULFILLMENT_READY` do T19 đổi):
  ```php
  throw_if(config('features.paid_checkout') && ! Route::has('api.webhooks.payments'), RuntimeException::class,
      'FEATURE_PAID_CHECKOUT=true nhưng chưa có route IPN (T19).');
  ```
  Guard chạy ở boot của mọi môi trường trừ local/testing. Vì route nạp sau provider, đặt kiểm này ở `booted()` hoặc trong command `config:verify` của T31.
- Cách kiểm chứng: test guard: `APP_ENV=production` + cờ bật + không có route → RuntimeException.

### L2 [Low] Các route giỏ hàng và preview không có throttle — OWASP A04
- Vị trí: `backend/routes/api.php:150-158` (`GET /cart`, `POST /cart/items`, `DELETE /cart/items/{course}`, `DELETE /cart/coupon`), `:164` (`GET /checkout/preview`).
- Mô tả & tác động: tài khoản HS (đăng ký miễn phí, không cần OTP) có thể gọi liên tục. Mỗi lần gọi mở transaction, khoá `carts`, chạy nhiều truy vấn (`classify`, đánh giá mã, `CouponCapacity::held` đếm `orders`). `GET /cart` còn có thể ghi DB (T16-3). Rủi ro chỉ là tốn tài nguyên. Không lộ dữ liệu. `RouteMiddlewareGroupsTest` chỉ bắt buộc throttle cho route công khai.
- Cách sửa: thêm limiter `cart` (ví dụ 60/phút/identity) cho các route trên; preview dùng chung hoặc 30/phút.
- Cách kiểm chứng: test 61 request `POST /cart/items` → lần 61 trả 429 kèm `Retry-After`.

### L3 [Low] Log chạm trần sai mã theo IP dùng SHA-256 không khoá: IPv4 dò ngược được — OWASP A09, dữ liệu cá nhân
- Vị trí: `backend/app/Services/Cart/CartService.php:210` (working tree "Sửa lỗi nhỏ 3").
- Mô tả & tác động: `substr(hash('sha256', 'coupon-fail-ip:'.$ip), 0, 16)`: không gian IPv4 chỉ 2^32, tiền tố cố định, nên ai đọc được log thì dò lại ra IP trong vài giây. Thực chất vẫn là ghi IP vào log `laravel` (giữ 90 ngày), trong khi comment ghi là "IP băm". Đây là bút danh hoá yếu, không phải ẩn danh.
- Cách sửa: `hash_hmac('sha256', $ip, config('app.key'))` (hoặc một khoá riêng `LOG_HASH_KEY`), cắt 16 ký tự. Hoặc ghi thẳng IP và tính vào thời hạn lưu log có IP mà PO đã cho phép tự quyết.
- Cách kiểm chứng: unit test giá trị log ≠ `sha256('coupon-fail-ip:'.$ip)`, và ổn định với cùng IP.

### L4 [Low] Phản hồi tạo giao dịch MoMo không được đối chiếu số tiền / partnerCode / chữ ký — OWASP A08
- Vị trí: `backend/app/Services/Payments/Gateways/Momo/MoMoGateway.php:127-142`.
- Mô tả & tác động: adapter đã kiểm `resultCode`, `orderId`, `requestId` và host của `payUrl`. Nhưng nó chưa so `amount` và `partnerCode` trong phản hồi với request, và chưa verify `signature` của phản hồi (nếu MoMo có ký). Rủi ro thấp vì có TLS và allowlist host. Đây là lớp phòng thủ thứ hai theo tinh thần S12/ADR-001 §2.
- Cách sửa: khi kiểm sandbox (cùng T17-1), xác nhận phản hồi create có `signature` hay không. Nếu có, verify theo danh sách trường của MoMo. Bất kể có hay không, thêm kiểm `(int) ($json['amount'] ?? -1) === $request->amount` và `partnerCode` khớp, sai thì `GatewayUnavailableException('create_response_mismatch')`.
- Cách kiểm chứng: test `Http::fake` trả `amount` khác hoặc `partnerCode` khác → attempt `error`, 502.

### I1 [Info] Thông điệp 502/503 bị thay bằng câu chung ở production
- `backend/app/Support/ApiExceptionRenderer.php:32`: mọi status ≥ 500 khi tắt debug đều thành "Đã có lỗi xảy ra...", kể cả `PAYMENT_DISABLED` (503) và `PAYMENT_GATEWAY_UNAVAILABLE` (502, kèm `errors.order_code`). `code` vẫn giữ. Local đang bật debug nên thấy thông điệp thật, dễ bị bỏ sót khi test FE. Bổ sung cho R2 của review khoá thanh toán: FE phải dựa vào `code` và `paid_checkout_enabled`; hoặc cho `DomainException` giữ thông điệp riêng.

### I2 [Info] `CheckoutRequest` so cổng phân biệt hoa thường còn service thì hạ chữ thường
- `CheckoutRequest.php:25` dùng `Rule::in(config)` nguyên văn, còn `PaymentGatewayManager::enabled()` hạ chữ thường. Nếu env ghi `PAYMENT_GATEWAYS=MoMo` thì client gửi `momo` sẽ bị 422. Chỉ ảnh hưởng tính năng. Nên chuẩn hoá ở `config/payments.php` (`strtolower` khi đọc env).

### I3 [Info] Nhánh `needs_review` vẫn cấp quyền học
- `OrderFulfillmentService::recordCouponUsage` (vượt `max_uses`, trùng lượt dùng) và `grantPurchase` (khóa ngừng bán giữa `prepare` và `markPaid`) chỉ bật `needs_review`, vẫn ghi danh. Với đơn 0đ khi cờ tắt, các nhánh này không xảy ra được, vì capacity được kiểm dưới khoá `coupons` và race thật cho đúng 1 đơn. Khi làm V2: T24 (admin đơn hàng) phải có màn lọc `needs_review` và luồng thu hồi quyền hoặc hoàn tiền. Trước đó, nên có cảnh báo khi xuất hiện đơn 0đ có `needs_review=true`.

### I4 [Info] Checklist bắt buộc cho V2 (T19/T20), rút từ audit này
- IPN: tra attempt theo `(gateway, gateway_order_id)`; **so `amount` của IPN với `payment_attempts.amount`** trước `markPaid`; idempotent theo `gateway_trans_id` (đã có unique); route webhook `withoutMiddleware(EnsureFrontendRequestsAreStateful)` + `throttle:webhook` + allowlist `{gateway}`; trả 204 cả khi trùng.
- `/orders/{code}/pay`, `check-payment`, `GET /orders`: luôn `where('user_id', auth id)` (không route model binding trần theo `code`); `pay` phải kiểm `features.paid_checkout` (đã ghi trên board); `check-payment` limiter đang lấy `route('order')`, cần thống nhất tên tham số khi tạo route.
- `create_response`: đặt thời hạn lưu (T18-6).
- Gỡ L1 khi route IPN đã có.

## Kết quả công cụ
- Pest `tests/Feature/T17 tests/Feature/T18 --exclude-group=race` (DB e): 178 passed, 547 assertions, 31 s. Không chạy nhóm race (QA đã chạy ở checkout-lock-T30); load máy lúc chạy khoảng 4.
- `composer audit` / `npm audit`: không chạy trong cụm này (thuộc cụm 4).
- Thử curl: 26 request (bảng A, B). Race 0đ: 2 HS × mã max_uses=1, và 3 request song song cùng HS.

## Chuyển cho `laravel-dev`
1. M1: chỉ kiểm cổng khi tổng > 0, cùng test và cập nhật contract/checklist. Nên sửa trước staging.
2. L2: limiter `cart`.
3. L3: dùng HMAC cho `ip_hash` (chung với phần việc của Sửa lỗi nhỏ 3 đang làm).
4. L1, L4, I2: ghi `backlog-v2.md` mục "Cụm 3"; L1 làm cùng T19, L4 làm cùng T17-1.

## Test nên có (`laravel-qa`)
- `enabled_gateways = []`: 0đ → 201 paid; > 0 → 503 (cờ tắt) hoặc 422 (cờ bật) (M1).
- Guard: `production` + `paid_checkout=true` + chưa có route IPN → không boot (L1).
- Throttle `cart` trả 429 (L2).
- Kiến trúc: grep `createPayment(` và `markPaid(` trong `app/` chỉ được xuất hiện ở các file đã biết, để không có đường mới vượt cờ khi thêm code V2.

## Điểm cần pháp chế / PO quyết
- PO: với V1, có giữ `FEATURE_ZERO_TOTAL_CHECKOUT=true` (cho phép mã 100% cấp khóa có phí) không. Hiện mã 100% bắt buộc có `max_uses` và `valid_until`, nhưng không có trần cho từng mã (ví dụ `max_uses` tối đa 1.000.000, hạn tới năm 2099 vẫn hợp lệ). Nếu cần, nên đặt trần `max_uses` cho mã 100%.
- Pháp chế: ghi IP (dạng băm hoặc nguyên văn, L3) vào log ứng dụng 90 ngày. Cần bộ phận pháp chế xác nhận thời hạn này nằm trong chính sách lưu log có IP.
