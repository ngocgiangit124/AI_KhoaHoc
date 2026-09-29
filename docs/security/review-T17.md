# SECURITY: T17 [SEC] (Thanh toán: abstraction + MoMo) | 2026-09-29

**Phạm vi:** `git diff claude/zen-dirac-fmucf7...t17` trên nhánh local `t17` (worktree `.claude/worktrees/t17`, commit `3c93b99`, `fe38f3a`; bỏ qua commit merge `a49864e`). Gồm: `app/Services/Payments/**` (`PaymentGateway`, DTO, `PaymentStatus`, exception, `FakeGateway`, `MoMoGateway`, `MoMoSigner`, `MoMoResultCode`, `StrictAmountParser`, `PaymentGatewayManager`), `PaymentServiceProvider`, `ProductionConfigGuard::guardPayments/guardMomo`, `config/payments.php`, lệnh `payments:momo:verify-sandbox`, test `tests/Unit/Payments/*`, `tests/Feature/T01/ProductionConfigGuardTest.php`.
**Chuẩn đối chiếu:** tasks.md T17; ADR-001 §1, §2, §7b; api-contract §1.7, §2.6; `docs/security/audit-2026-09-25.md` S4, S12 (9 điểm); `docs/reviews/review-T17.md` (APPROVE; R1, R2).

**Kết luận:** **PASS có điều kiện**. Không có Critical/High. Có **2 phát hiện Medium** (M1, M2) và 4 Low. Cả hai Medium đều sửa được trong vài dòng.

Điều kiện:
1. Sửa **M1** (tắt follow redirect, pin host `payUrl`) kèm test **trước khi merge T18**, vì T18 là nơi đầu tiên dùng `createPayment()` cho người dùng thật.
2. Sửa **M2** (fail-closed khi credential MoMo rỗng ở mọi môi trường) kèm test **trước khi merge T19**, vì T19 mở route IPN công khai.
3. **R1** (review code) là gate go-live: chạy `payments:momo:verify-sandbox` với credential sandbox thật, đối chiếu danh sách trường ký (create, IPN, query request/response) và bảng `resultCode`, rồi gửi lại `laravel-security` ký phần này **trước khi bật `PAYMENT_GATEWAYS=momo` ở production**.
4. L1–L4 đưa vào backlog có chủ: L1, L2 làm cùng T19; L3, L4 trước go-live.

Nền tảng làm tốt (đã kiểm bằng đọc code và test tạm):
- **MoMoSigner:** `ksort(SORT_STRING)` cho ra đúng thứ tự trường như tài liệu MoMo (`orderId < orderInfo < orderType`, `partnerCode < payType`, `requestId < requestType`, `responseTime < resultCode`). Dùng `hash_hmac('sha256', …)` và `hash_equals(expected, received)` đúng thứ tự tham số. Chữ ký rỗng bị từ chối trước khi so. Không có nhánh so sánh sớm gây lộ timing.
- **IPN (`parseNotification`):** kiểm đủ trường bắt buộc, rồi verify chữ ký, rồi mới kiểm `partnerCode`, `amount` và `resultCode`. `accessKey` lấy từ config, không lấy từ payload (S12.2).
- **Query (`queryStatus`):** verify chữ ký phản hồi, sau đó kiểm `partnerCode`, `orderId` **và `requestId`** khớp request vừa gửi. Làm kỹ hơn yêu cầu. Chữ ký sai hoặc thiếu thì ném `InvalidSignatureException`, không hành động.
- **`StrictAmountParser`:** chỉ nhận `int >= 0` hoặc chuỗi toàn chữ số. Từ chối `"100000.0"`, `"1e5"`, số âm, float, bool, null, mảng (S12.3).
- **`MoMoResultCode`:** chỉ đúng chuỗi `'0'` là `Succeeded`. Đã thử `resultCode` = `0`, `'0'`, `0.0` (có chữ ký hợp lệ): cả 3 là Succeeded. Chấp nhận được vì chữ ký phủ giá trị chuỗi `"0"`. Các giá trị `'00'`, `' 0'`, `false` đều ra Failed. Các mã 9000/1000/7000/7002 ra Pending, không bao giờ Succeeded (S12.1).
- **FakeGateway có 2 lớp chặn:**
  - Lớp 1: `ProductionConfigGuard` dùng allowlist `KNOWN_GATEWAYS=['momo']`, không phân biệt hoa/thường. Mặc định `PAYMENT_GATEWAYS=fake` ở production thì boot thất bại (fail-closed).
  - Lớp 2: `createFakeDriver()` chỉ chạy khi `environment('local','testing')`, phân biệt hoa/thường. Vì vậy `APP_ENV=prod`/`Production`/`staging` đều bị chặn.
  - `match` không có `default` nên quên viết `guard*` cho cổng mới sẽ ném lỗi ngay khi boot.
  - Secret cố định `fake-gateway-secret` chấp nhận được vì chỉ dùng ở local/testing.
- **`guardMomo`:** endpoint bắt buộc `scheme=https` và `host=payment.momo.vn` (so khớp đúng, không dùng `str_contains`). Credential không được rỗng sau `trim`. Đã thử `https://payment.momo.vn\@evil.com`: cả `parse_url` lẫn Guzzle đều hiểu host là `evil.com`, nên guard chặn. Không có lệch cách parse giữa hai bên.
- **HTTP client:**
  - Timeout 10s, connectTimeout 5s.
  - `crypto_method` là TLS ≥ 1.2 (mặc định của Laravel).
  - Không `withoutVerifying()`, không `globalOptions` tắt verify. Có test tĩnh S12.8.
  - Không `retry()`, đúng ADR-001 §2 (không tạo 2 giao dịch).
  - `post()` từ chối endpoint không phải `https://`.
- **Log kênh `payments`:** chỉ ghi `request_id`, `gateway_order_id`/`order_id`, `result_code`, `status`, tên trường thiếu. Không có `secretKey`, `signature`, PII (có test tĩnh S12.9, đã đọc thủ công mọi lời gọi log). Thông điệp exception là chuỗi cố định, không chèn payload.
- **`rawResponse`/`raw`** đã bỏ `signature`.
- **SSRF:** endpoint chỉ lấy từ config/env. Không có đường nào để input người dùng ảnh hưởng URL gọi ra ngoài. Ở production endpoint bị pin host.
- **Lệnh `payments:momo:verify-sandbox`:**
  - Tự chặn khi `isProduction()`.
  - Thiếu cấu hình thì chỉ in **tên biến**, không in giá trị.
  - Output không có secret hay chữ ký.
  - Không ghi DB local.
- Không có credential mẫu công khai của MoMo trong repo, không `.env` nào bị track. `composer.json`/`composer.lock` không đổi.

## Môi trường & công cụ

| Mục | Kết quả |
|---|---|
| Môi trường | Docker local (`infra`), worktree mount `/var/www/wt`, DB `vitaminvui_testing_t17` (`phpunit.t17.xml`). Không chạy migrate, không đụng `vitaminvui`, `vitaminvui_testing`, `_t04` |
| `vendor/bin/pint --test` | Sạch |
| `vendor/bin/phpstan analyse` | 0 lỗi |
| `vendor/bin/pest -c phpunit.t17.xml` | **336 passed (848 assertions)**, chạy sau khi đã xoá test tạm |
| `composer audit` | Không chạy lại: T17 không đổi dependency (`composer.json`/`lock` không có trong diff) |

**Test tạm để xác minh** (`backend/tests/Unit/Payments/TmpSecT17Test.php`, **đã xoá**, working tree sạch):

| Kiểm tra | Kết quả |
|---|---|
| `createPayment`: endpoint trả `307 Location: https://evil.example/steal`, host lạ trả JSON có `partnerCode/orderId/requestId` khớp và `payUrl=https://evil.example/pay` | **Theo redirect**: `POST https://evil.example/steal` được gửi lại **nguyên body** (`accessKey`, `partnerCode`, `signature`, `ipnUrl`…). `PaymentInitResult.payUrl = https://evil.example/pay` được chấp nhận (M1) |
| Options mặc định của `PendingRequest` | `{"connect_timeout":10,"crypto_method":33,"http_errors":false,"timeout":30}`: không có `allow_redirects`, nên Guzzle dùng mặc định (tối đa 5 lần, cho phép cả `http`) (M1) |
| `createPayment`: MoMo "thật" trả `payUrl=https://evil.example/pay` | Chấp nhận, vì chỉ kiểm tiền tố `https://` (M1) |
| `enabled_gateways=['momo']`, `partner_code/access_key/secret_key` = null, môi trường `testing` | `driver('momo')` resolve được. IPN **tự ký bằng khoá rỗng** được chấp nhận: `status=succeeded amount=100000` (M2) |
| IPN có `message: ["a"]` (mảng) | `ErrorException: Array to string conversion` (500) **trước khi verify chữ ký** (L1) |
| IPN ký hợp lệ + trường lạ `injected`, `status` + query string `?qs=1` | `GatewayNotification.raw` chứa `injected,status,qs` là các trường **không được ký** (L2) |
| Endpoint `https://payment.momo.vn:8443`, `/../`, `user@`, `#@evil.com`, `evil.com\@payment.momo.vn` | `parse_url` và Guzzle cho cùng host. Guard chỉ pin host, không pin port/path (I2) |
| `resultCode` = `0`/`'0'`/`0.0`/`false`/`'00'`/`' 0'` (ký hợp lệ) | succeeded/succeeded/succeeded/failed/failed/failed |

## Phát hiện

### M1 [Medium] HTTP client tới MoMo theo redirect sang host bất kỳ; `payUrl` không pin host; phản hồi create không verify chữ ký (R2) — OWASP A08/A05
- **Vị trí:**
  - `backend/app/Services/Payments/Gateways/MoMo/MoMoGateway.php` `client()`: `Http::baseUrl(...)->timeout(10)->connectTimeout(5)->acceptJson()->asJson()`.
  - `createPayment()`: kiểm `payUrl` bằng `str_starts_with($payUrl, 'https://')`.
- **Mô tả & tác động:**
  - Laravel HTTP client (Guzzle) mặc định **theo tối đa 5 redirect**, kể cả sang `http://`. Với 307/308, Guzzle **gửi lại POST nguyên body** tới host mới (đã xác minh bằng test tạm). Body chứa `accessKey`, `partnerCode`, `signature`, `ipnUrl`. `secretKey` không nằm trong body.
  - Phản hồi `create` không có lớp xác thực nào ngoài TLS:
    - Không verify chữ ký (R2).
    - Chỉ so `partnerCode/orderId/requestId`. Ba giá trị này nằm ngay trong request, nên host nhận redirect biết sẵn.
    - `payUrl` chỉ cần bắt đầu bằng `https://`.
  - Kịch bản: cổng MoMo, một proxy/egress nội bộ, hoặc cấu hình endpoint sai ở môi trường không phải production trả redirect. Khi đó ứng dụng sẽ đưa **mọi học sinh** tới `payUrl` của host lạ (lừa đảo thanh toán hàng loạt). Tiền không vào đơn, nhưng người dùng mất tiền.
  - Đơn **không** bị đánh dấu `paid` sai, vì IPN và query vẫn yêu cầu chữ ký bằng `secretKey`.
  - Điều kiện khai thác khó (cần kiểm soát phản hồi của MoMo hoặc tầng mạng giữa). Nhưng ảnh hưởng trên toàn bộ luồng thanh toán và cách sửa rất rẻ, nên xếp Medium.
- **Đánh giá R2:**
  - Có TLS verify thì MITM thông thường không làm giả được phản hồi. R2 đơn lẻ chỉ là Low.
  - R2 thành vấn đề khi cộng với việc theo redirect và không pin host `payUrl`: TLS chỉ chứng thực host **cuối cùng** sau redirect, không chứng thực rằng đó là MoMo.
  - Sau khi tắt redirect và pin host, việc verify chữ ký create response là lớp bổ sung. Nên làm cùng R1, khi đã có danh sách trường chính thức từ sandbox.
- **Cách sửa:**
  ```php
  private function client(string $endpoint): PendingRequest
  {
      return Http::baseUrl($endpoint)
          ->withoutRedirecting()          // không bao giờ theo 3xx tới cổng thanh toán
          ->timeout(10)
          ->connectTimeout(5)
          ->acceptJson()
          ->asJson();
  }

  // createPayment(): pin host payUrl (và deeplink nếu T18 dùng) theo allowlist
  $host = parse_url($payUrl, PHP_URL_HOST);
  $scheme = parse_url($payUrl, PHP_URL_SCHEME);
  if ($scheme !== 'https' || ! in_array($host, config('payments.gateways.momo.pay_url_hosts'), true)) {
      Log::channel('payments')->warning('momo.create.untrusted_pay_url', ['request_id' => $request->requestId]);
      throw new GatewayUnavailableException('Phản hồi tạo giao dịch MoMo có payUrl không hợp lệ.');
  }
  ```
  - `pay_url_hosts` mặc định là `['payment.momo.vn']` ở production (thêm vào `guardMomo()`) và `['test-payment.momo.vn']` ở sandbox. Dev xác nhận host thật của `payUrl` khi chạy sandbox (R1).
  - Mọi phản hồi 3xx coi là lỗi cổng: `GatewayUnavailableException` và log `momo.{op}.unexpected_redirect` kèm status.
  - Sau R1: nếu tài liệu MoMo có `signature` ở phản hồi create thì verify bằng `MoMoSigner` trước khi đọc `resultCode`/`payUrl`.
- **Kiểm chứng sau khi sửa:**
  - `Http::fake` endpoint trả 307 tới host khác: ném `GatewayUnavailableException`, `Http::assertSentCount(1)` (không có request thứ 2).
  - `payUrl` = `https://evil.example/pay`, `http://payment.momo.vn/...`, `https://payment.momo.vn.evil.com/...`: đều bị từ chối.
  - Test tĩnh: `client()` có `withoutRedirecting()`.

### M2 [Medium] Adapter MoMo chấp nhận credential rỗng ngoài production; chữ ký HMAC khoá rỗng giả mạo được — OWASP A07/A05
- **Vị trí:**
  - `backend/app/Services/Payments/PaymentGatewayManager.php` `createMomoDriver()`.
  - `MoMoGateway` (`(string) $this->config['secret_key']`).
  - `MoMoSigner::sign()`/`verify()` không từ chối `$secretKey === ''`.
- **Mô tả & tác động:**
  - `ProductionConfigGuard` chỉ chạy khi `APP_ENV === 'production'`. Ở mọi môi trường khác (staging/UAT, hoặc production đặt nhầm `APP_ENV=prod`/`Production`), nếu `PAYMENT_GATEWAYS` có `momo` mà `MOMO_SECRET_KEY` chưa đặt, thì `(string) null === ''`. Khi đó `hash_hmac('sha256', raw, '')` là giá trị **ai cũng tính được**, vì `accessKey`/`partnerCode` rỗng hoặc dễ đoán.
  - Đã xác minh: IPN tự ký bằng khoá rỗng trả `Succeeded amount=100000`.
  - Khi T19 mở `POST /webhooks/payments/momo`, kẻ tấn công đánh dấu được đơn `paid` và nhận khoá học miễn phí trên môi trường đó. Nếu đó là production đặt sai `APP_ENV`, đây là lộ hàng loạt.
  - Hiện tại chưa có route IPN (T19) nên chưa khai thác được. Vì vậy xếp Medium và yêu cầu sửa trước T19.
- **Cách sửa (fail-closed ở mọi môi trường, không phụ thuộc guard production):**
  ```php
  protected function createMomoDriver(): PaymentGateway
  {
      $config = (array) $this->config->get('payments.gateways.momo', []);

      foreach (['partner_code', 'access_key', 'secret_key', 'endpoint'] as $key) {
          if (trim((string) ($config[$key] ?? '')) === '') {
              throw new RuntimeException("Thiếu cấu hình MoMo '{$key}' — không khởi tạo adapter (T17/M2).");
          }
      }

      return new MoMoGateway($config, new MoMoSigner);
  }
  ```
  Thêm lớp chặn trong `MoMoSigner`: `sign()` ném `InvalidArgumentException` khi `$secretKey === ''`, còn `verify()` trả `false`. Lỗi cấu hình khi đó luôn là "từ chối" chứ không phải "ai cũng ký được".
- **Kiểm chứng sau khi sửa:**
  - `enabled_gateways=['momo']` + mỗi khoá `partner_code/access_key/secret_key/endpoint` lần lượt rỗng hoặc chỉ có khoảng trắng: `driver('momo')` ném lỗi (dataset 4×2).
  - `MoMoSigner::verify($fields, '', $sig)` trả `false` với cả chữ ký tính bằng khoá rỗng.
  - T19: IPN ký bằng khoá rỗng trả 400 (hoặc 5xx cấu hình), đơn không đổi trạng thái.

### L1 [Low] Trường IPN không phải scalar gây `ErrorException` 500 trước khi verify chữ ký — OWASP A04
- **Vị trí:** `MoMoGateway::parseNotification()`, các lệnh ép `(string) $payload[...]` khi dựng `$signedFields`.
- **Mô tả & tác động:** Payload JSON có trường là mảng/object (ví dụ `"message": ["a"]`) gây `ErrorException: Array to string conversion`, thành HTTP 500 thay vì 400. Không vượt được chữ ký. Nhưng request không cần xác thực vẫn tạo được lỗi 500 và log `error` kèm stack trace, gây nhiễu giám sát và tăng kích thước log. Ngoài ra, controller T19 nếu chỉ bắt `InvalidSignatureException` sẽ không ghi event `rejected_signature` cho các request này.
- **Cách sửa:** trước khi ép chuỗi, kiểm mỗi trường bắt buộc (và `extraData` nếu có) là `is_string || is_int`. Nếu sai thì ném `InvalidSignatureException('Trường IPN không hợp lệ.')` và log tên trường. Áp dụng tương tự cho `$body` trong `queryStatus()`.
- **Kiểm chứng:** dataset: mỗi trường bắt buộc lần lượt là `[]`, `{}`, `null` (với trường bắt buộc), `true` → `InvalidSignatureException`, không có `ErrorException`.

### L2 [Low] `GatewayNotification::raw` chứa trường không được ký và cả query string — OWASP A08
- **Vị trí:** `MoMoGateway::parseNotification()` dùng `$request->all()` (gộp JSON body và query string), rồi `raw: $this->withoutSecrets($payload)`. `FakeGateway` làm tương tự.
- **Mô tả & tác động:** Sau khi chữ ký hợp lệ, `raw` vẫn giữ mọi khoá do bên gửi thêm vào (đã thử `injected`, `status`, `qs`). ADR-001 §2 và S12.4 yêu cầu "chỉ lưu các trường đã biết". Nếu T19 lưu `raw` nguyên vẹn vào `payment_webhook_events.payload`, hoặc sau này có code đọc `raw['...']`, thì dữ liệu **chưa ký** sẽ bị lưu hoặc bị tin. Kẻ tấn công cũng có thể nhồi dữ liệu tuỳ ý (kể cả PII hoặc HTML) vào bảng log webhook qua một IPN hợp lệ bị phát lại.
- **Cách sửa:** dựng `raw` từ đúng danh sách trường đã ký (cộng `extraData`), bỏ phần còn lại. Đọc nguồn là `$request->json()->all()` (chỉ body JSON), không dùng `$request->all()`.
- **Kiểm chứng:** IPN ký hợp lệ kèm `injected`/`?qs=1` → `array_keys($n->raw)` đúng bằng tập trường đã biết.

### L3 [Low] Lệnh `payments:momo:verify-sandbox` chỉ chặn `APP_ENV=production`, không chặn endpoint production — OWASP A05
- **Vị trí:** `backend/app/Console/Commands/Payments/VerifyMomoSandboxCommand.php` `handle()`.
- **Mô tả & tác động:** Chạy ở staging, hoặc trên máy dev có `.env` chép từ production (`MOMO_ENDPOINT=https://payment.momo.vn` + credential thật), lệnh sẽ tạo **giao dịch thật** trên MoMo production với `--amount` tuỳ ý (không kiểm `> 0`). Lệnh cũng in `payUrl` thật ra terminal. Không mất tiền nếu không ai trả, nhưng vẫn để lại giao dịch production và lệch đối soát.
- **Cách sửa:** bắt buộc `parse_url(endpoint, PHP_URL_HOST) === 'test-payment.momo.vn'` (allowlist host sandbox), nếu sai thì `FAILURE`. Validate `--amount` là số nguyên dương. Validate `--order-id` theo regex `orderId` của MoMo.
- **Kiểm chứng:** test Artisan: endpoint `https://payment.momo.vn` → exit code FAILURE, `Http::assertNothingSent()`. `--amount=-1` → FAILURE.

### L4 [Low] Tham số `$secretKey` thiếu `#[\SensitiveParameter]` — OWASP A09
- **Vị trí:** `MoMoSigner::sign(array $fields, string $secretKey)`, `verify(...)`.
- **Mô tả & tác động:** Image `php` chính thức không có `php.ini`, nên `zend.exception_ignore_args` mặc định là `Off`. Nếu có exception phát sinh trong khung gọi này, stack trace trong log (Laravel ghi trace dạng chuỗi) sẽ in tham số chuỗi, cắt ở 15 ký tự, tức là **lộ tiền tố secretKey** vào `laravel.log`. Hiện chưa có đường nào làm hàm này ném lỗi, nên xếp Low (phòng ngừa).
- **Cách sửa:** `public function sign(array $fields, #[\SensitiveParameter] string $secretKey): string` (tương tự cho `verify`). Đặt `zend.exception_ignore_args=On` trong `infra` php.ini cho production.
- **Kiểm chứng:** test dùng Reflection: tham số `secretKey` có attribute `SensitiveParameter`.

### Info
- **I1 (R1, fail-closed):** Nếu danh sách trường ký IPN/query sai, mọi IPN/query thật bị **từ chối**. Đây là lỗi về tính sẵn sàng (đơn kẹt `pending`, huỷ sau 12h), không phải lỗi toàn vẹn. Nếu bảng mã Pending sai theo hướng lỏng, một mã lỗi bị xếp thành Pending, nhưng vẫn **không bao giờ** thành Succeeded. Gate go-live như ở phần điều kiện.
- **I2:** `guardMomo()` pin scheme và host nhưng không pin port/path/query/fragment. Ví dụ `https://payment.momo.vn#x` làm mất path API. Đây chỉ là rủi ro cấu hình sai, host vẫn là MoMo. Có thể siết thành `rtrim($endpoint, '/') === 'https://payment.momo.vn'`.
- **I3:** Chuỗi ký dạng `k=v&k=v` không escape `&`/`=` trong giá trị. Với danh sách khoá cố định và mỗi khoá xuất hiện đúng 1 lần thì không tạo được va chạm thực tế. T18 vẫn nên giới hạn `orderId` theo regex MoMo `^[0-9a-zA-Z]([-_.]*[0-9a-zA-Z]+)*$` và `orderInfo` trong `[A-Za-z0-9 _-]`.
- **I4:** `FakeGateway::sign()` chỉ phủ `orderId|amount`, không phủ `status`. Không đáng kể vì secret công khai và chỉ chạy ở local/testing. Không dùng Fake để kiểm hành vi chữ ký thật.
- **I5:** `ProductionConfigGuard` chưa kiểm `MOMO_IPN_URL`/`MOMO_REDIRECT_URL` là `https` và đúng host (`api.` / `FRONTEND_URL`). Nên bổ sung khi T18/T19 dùng tới.

## Yêu cầu chuyển cho T18/T19/T20 (không tính là thiếu của T17)
**T18 (Checkout):**
- `description`/`orderInfo` chỉ là `"Thanh toan don hang {order.code}"`. Không có tên, SĐT, email học sinh/phụ huynh (S12.6). `extraData = ''`.
- `gateway_order_id` theo regex MoMo. `amount` là `int > 0` và nằm trong hạn mức MoMo (xác nhận ở R1).
- `returnUrl`/`notifyUrl` lấy từ config (không lấy từ request), đúng ADR-001 §2.
- `GatewayUnavailableException` thì không tự retry. Attempt ở trạng thái lỗi/không rõ, người dùng bấm "thử lại" thì tạo attempt mới với `orderId` mới.
- Lưu `rawResponse` (đã bỏ `signature`) vào `payment_attempts.create_response`. Redirect người dùng **chỉ** tới `payUrl` đã qua pin host (M1).

**T19 (IPN):**
- Route `->whereIn('gateway', config('payments.enabled_gateways'))`. Nginx giới hạn body 16 KB và Laravel kiểm thêm → 413. `throttle:webhook` 120/phút/IP (S12.4).
- Bắt `InvalidSignatureException` → 400 + log `rejected_signature`. Cho đến khi sửa L1, bắt thêm lỗi parse → 400.
- Sau `parseNotification()`:
  - `orderId` phải khớp attempt.
  - `requestId === attempt.request_id`.
  - `amount === attempt.amount === order.total_amount`.
  - Idempotent theo unique `gateway_trans_id`, theo đúng máy trạng thái ADR-001 §3.
  - Không đọc tham số trên `redirectUrl`.
- `payment_webhook_events.payload` chỉ chứa trường đã biết (L2), `source` = `ipn`/`query`.
- `acknowledge()` (204) sau khi commit.
- Test: IPN ký bằng khoá rỗng khi thiếu cấu hình bị từ chối (M2). IPN trùng → `duplicate`. `amount` lệch → không `paid`.

**T20 (Đối soát):**
- `queryStatus()` ném `InvalidSignatureException` thì không hành động, chỉ log.
- So `notification.amount` với `attempt.amount` như IPN.
- Kết quả đi qua `PaymentWebhookService::apply()` giống IPN.

## Test `laravel-qa` nên thêm
- M1: redirect 307/302 từ endpoint → lỗi, chỉ 1 request được gửi. `payUrl` ngoài allowlist host → lỗi.
- M2: credential MoMo rỗng/khoảng trắng → không resolve được adapter. `MoMoSigner::verify` với khoá rỗng → `false`.
- L1: trường IPN là mảng/object/bool → `InvalidSignatureException`, không 500.
- L2: `raw` không chứa khoá lạ hay query string.
- L3: lệnh verify-sandbox với endpoint production → FAILURE, `Http::assertNothingSent()`.
- L4: Reflection kiểm `#[SensitiveParameter]`.
- Giữ nguyên các test hiện có (bảng mã, amount nghiêm ngặt, partnerCode, requestId query, guard allowlist).

## Điểm cần pháp chế / PO quyết
- **Chia sẻ dữ liệu với MoMo (bên thứ ba):** thiết kế hiện tại chỉ gửi mã đơn và số tiền (thu thập tối thiểu). Người thanh toán (thường là phụ huynh) cung cấp thông tin trực tiếp cho MoMo. Việc xác định vai trò các bên (bên kiểm soát/bên xử lý) theo Luật Bảo vệ dữ liệu cá nhân 2025 và Nghị định 356/2025/NĐ-CP, cùng việc có cần thoả thuận xử lý dữ liệu với MoMo hay không: **cần bộ phận pháp chế xác nhận**.
- **Thời hạn lưu:** log `payments` giữ 90 ngày (`LOG_DAILY_DAYS`), chỉ chứa mã đơn và mã kết quả, không có PII. `payment_webhook_events`/`create_response` (T18/T19) cần thời hạn lưu và xoá: PO quyết, **cần bộ phận pháp chế xác nhận** nếu liên quan nghĩa vụ lưu chứng từ kế toán.
- **PO:** chấp nhận thứ tự điều kiện ở trên (M1 trước T18, M2 trước T19, R1 trước go-live). Ghi gate R1 vào `docs/board.md`.
